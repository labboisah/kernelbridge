<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Notifications\Services\AdminNotificationService;
use App\Domain\Notifications\Services\NotificationMessageFormatter;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class NotificationController extends Controller
{
    public function index(NotificationMessageFormatter $formatter)
    {
        $messages = AdminNotification::with(['creator:id,name', 'attachments'])->latest()->paginate(5);
        $messages->getCollection()->each(function (AdminNotification $message) use ($formatter): void {
            if ($message->message_format === 'html') {
                $message->setAttribute('formatted_message', $formatter->sanitize($message->message, 'html'));
            }
        });

        return Inertia::render('Admin/Notifications/Index', [
            'roles' => UserRole::names(),
            'templates' => NotificationTemplate::orderBy('name')->get(['uuid', 'name', 'subject', 'message', 'message_format']),
            'messages' => $messages,
        ]);
    }

    public function progress(Request $request)
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*' => ['required', 'uuid', 'distinct'],
        ]);
        $messages = AdminNotification::whereIn('uuid', $data['messages'])->get(['id', 'uuid', 'delivery_tracking']);
        $counts = DB::table('admin_notification_deliveries')
            ->whereIn('admin_notification_id', $messages->pluck('id'))
            ->selectRaw('admin_notification_id, channel, status, count(*) as total')
            ->groupBy('admin_notification_id', 'channel', 'status')->get()->groupBy('admin_notification_id');
        $progress = $messages->mapWithKeys(function (AdminNotification $message) use ($counts): array {
            if (! $message->delivery_tracking) {
                return [$message->uuid => null];
            }
            $channels = [];
            foreach ($counts->get($message->id, collect()) as $count) {
                $channels[$count->channel] ??= ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
                $channels[$count->channel][$count->status] = (int) $count->total;
            }

            return [$message->uuid => $channels];
        });

        return response()->json(['progress' => $progress])->header('Cache-Control', 'private, no-store');
    }

    public function recipients(Request $request)
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $search = '%'.$data['search'].'%';
        $users = User::where(fn ($query) => $query->where('name', 'like', $search)->orWhere('email', 'like', $search))
            ->orderBy('name')->limit(20)->get(['public_id', 'name', 'email', 'status'])
            ->map(fn (User $user) => ['type' => 'user', 'id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status]);
        $customers = Customer::whereDoesntHave('users')->whereNotNull('email')
            ->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('email', 'like', $search))
            ->orderBy('name')->limit(20)->get(['uuid', 'name', 'email'])
            ->map(fn (Customer $customer) => ['type' => 'customer', 'id' => $customer->uuid, 'name' => $customer->name, 'email' => $customer->email]);

        return response()->json(['users' => $users, 'customers' => $customers]);
    }

    public function roleRecipients(Request $request, AdminNotificationService $service)
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:'.count(UserRole::names())],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(UserRole::names())],
        ]);
        $resolved = $service->recipientsForRoles($data['roles'], true);
        $invalidUsers = $resolved['users']->filter(fn (User $user) => ! filter_var($user->email, FILTER_VALIDATE_EMAIL));
        $recipients = $resolved['users']
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL))
            ->unique(fn (User $user) => mb_strtolower(trim($user->email)))
            ->map(fn (User $user) => ['name' => $user->name, 'email' => $user->email, 'type' => 'user'])
            ->concat($resolved['customers']->map(fn (Customer $customer) => ['name' => $customer->name, 'email' => $customer->email, 'type' => 'customer']))
            ->sortBy(fn (array $recipient) => mb_strtolower($recipient['email']))->values();

        return response()->json([
            'recipients' => $recipients,
            'invalid_users' => $invalidUsers->map(fn (User $user) => ['name' => $user->name, 'email' => $user->email])->values(),
            'unreachable_customers' => $resolved['unreachable'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, AdminNotificationService $service, NotificationMessageFormatter $formatter)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
            'message_format' => ['sometimes', Rule::in(['plain', 'html'])],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,txt,jpg,jpeg,png,webp', 'max:10240'],
            'audience_type' => ['required', Rule::in(['user', 'customer', 'role'])],
            'audience_identifier' => [
                Rule::requiredIf($request->input('audience_type') !== 'role' || ! $request->filled('roles')),
                'nullable', 'string', 'max:255',
                Rule::when($request->input('audience_type') === 'role', [Rule::in(UserRole::names())]),
            ],
            'roles' => [
                'exclude_unless:audience_type,role',
                Rule::requiredIf($request->input('audience_type') === 'role' && ! $request->filled('audience_identifier')),
                'nullable', 'array', 'min:1', 'max:'.count(UserRole::names()),
            ],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(UserRole::names())],
            'channels' => ['required', 'array', 'min:1', 'max:2'],
            'channels.*' => ['required', 'distinct', Rule::in(['database', 'mail'])],
        ]);
        $data['message_format'] ??= 'plain';
        $data['message'] = $formatter->sanitize($data['message'], $data['message_format']);
        $message = $service->send($request->user(), $data);

        return back()->with('success', "Notification submitted for {$message->recipient_count} recipient(s). {$message->unreachable_count} customer(s) could not be reached. Delivery requires the notification queue worker.");
    }

    public function storeTemplate(Request $request, AuditRecorder $audit, NotificationMessageFormatter $formatter)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:notification_templates,name'],
            'subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
            'message_format' => ['sometimes', Rule::in(['plain', 'html'])],
        ]);
        $data['message_format'] ??= 'plain';
        $data['message'] = $formatter->sanitize($data['message'], $data['message_format']);
        DB::transaction(function () use ($data, $request, $audit): void {
            $template = NotificationTemplate::create([...$data, 'created_by' => $request->user()->id]);
            $audit->record('admin.notification_template.created', $request->user(), ['template_uuid' => $template->uuid], $template);
        });

        return back()->with('success', 'Notification template saved.');
    }

    public function updateTemplate(Request $request, string $template, AuditRecorder $audit, NotificationMessageFormatter $formatter)
    {
        $record = NotificationTemplate::where('uuid', $template)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('notification_templates', 'name')->ignore($record->id)],
            'subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
            'message_format' => ['sometimes', Rule::in(['plain', 'html'])],
        ]);
        $data['message_format'] ??= 'plain';
        $data['message'] = $formatter->sanitize($data['message'], $data['message_format']);
        DB::transaction(function () use ($data, $record, $request, $audit): void {
            $record->update($data);
            $audit->record('admin.notification_template.updated', $request->user(), ['template_uuid' => $record->uuid], $record);
        });

        return back()->with('success', 'Notification template updated.');
    }

    public function destroyTemplate(Request $request, string $template, AuditRecorder $audit)
    {
        $record = NotificationTemplate::where('uuid', $template)->firstOrFail();
        DB::transaction(function () use ($record, $request, $audit): void {
            $audit->record('admin.notification_template.deleted', $request->user(), ['template_uuid' => $record->uuid], $record);
            $record->delete();
        });

        return back()->with('success', 'Notification template deleted.');
    }
}
