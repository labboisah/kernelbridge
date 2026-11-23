<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Jobs\DeliverAdminNotification;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminNotificationService
{
    public function __construct(private AuditRecorder $audit) {}

    public function recipientsForRoles(array $roles, bool $includeEmailOnly): array
    {
        $includesCustomers = in_array(UserRole::Customer->value, $roles, true);
        $users = $includesCustomers
            ? User::where(fn ($query) => $query->role($roles)->orWhereHas('customers'))->orderBy('id')->get()
            : User::role($roles)->orderBy('id')->get();
        $customers = collect();
        $unreachable = 0;
        if ($includesCustomers) {
            $emails = $users->pluck('email')->filter()->map(fn ($email) => mb_strtolower(trim($email)))->flip()->all();
            foreach (Customer::whereDoesntHave('users')->orderBy('id')->get(['id', 'name', 'email']) as $customer) {
                if (! $includeEmailOnly || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                    $unreachable++;

                    continue;
                }
                $email = mb_strtolower(trim($customer->email));
                if (! isset($emails[$email])) {
                    $customers->push($customer);
                    $emails[$email] = true;
                }
            }
        }

        return ['users' => $users, 'customers' => $customers, 'unreachable' => $unreachable];
    }

    public function send(User $actor, array $data): AdminNotification
    {
        foreach ($data['channels'] as $channel) {
            if (! config("notifications.channels.$channel", true)) {
                throw ValidationException::withMessages(['channels' => "The $channel channel is disabled."]);
            }
        }

        $users = collect();
        $customers = collect();
        $unreachable = 0;
        if ($data['audience_type'] === 'user') {
            $users->push(User::where('public_id', $data['audience_identifier'])->firstOrFail());
            $audienceLabel = 'User: '.$users->first()->name.' ('.$users->first()->email.')';
        } elseif ($data['audience_type'] === 'customer') {
            $customer = Customer::where('uuid', $data['audience_identifier'])->whereDoesntHave('users')->firstOrFail();
            if (! in_array('mail', $data['channels'], true) || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['audience_identifier' => 'A customer without an account requires a valid email address and the email channel.']);
            }
            $customers->push($customer);
            $audienceLabel = 'Customer: '.$customer->name.' ('.$customer->email.')';
        } else {
            $roles = $data['roles'] ?? [$data['audience_identifier']];
            $data['audience_identifier'] = implode(',', $roles);
            $audienceLabel = (count($roles) === 1 ? 'Role: ' : 'Roles: ').implode(', ', array_map(fn ($role) => str_replace('_', ' ', $role), $roles));
            $resolved = $this->recipientsForRoles($roles, in_array('mail', $data['channels'], true));
            $users = $resolved['users'];
            $customers = $resolved['customers'];
            $unreachable = $resolved['unreachable'];
        }
        if ($users->isEmpty() && $customers->isEmpty()) {
            throw ValidationException::withMessages(['audience_identifier' => 'No recipients are reachable with the selected audience and channels.']);
        }
        if (in_array('mail', $data['channels'], true) && $users->contains(fn (User $user) => ! filter_var($user->email, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['channels' => 'A selected user has an invalid email address. Correct it or send in-app only.']);
        }

        $database = in_array('database', $data['channels'], true);
        $mail = in_array('mail', $data['channels'], true);
        $mailUsers = $mail ? $users->unique(fn (User $user) => mb_strtolower(trim($user->email)))->values() : collect();
        $recipientCount = ($database ? $users->count() : $mailUsers->count()) + $customers->count();

        return DB::transaction(function () use ($actor, $data, $users, $mailUsers, $customers, $unreachable, $audienceLabel, $database, $mail, $recipientCount): AdminNotification {
            $message = AdminNotification::create([
                'created_by' => $actor->id,
                'subject' => $data['subject'],
                'message' => $data['message'],
                'audience_type' => $data['audience_type'],
                'audience_identifier' => $data['audience_identifier'],
                'audience_label' => $audienceLabel,
                'channels' => $data['channels'],
                'recipient_count' => $recipientCount,
                'email_only_count' => $customers->count(),
                'unreachable_count' => $unreachable,
                'delivery_tracking' => true,
            ]);
            if ($database) {
                foreach ($users as $user) {
                    $this->queueDelivery($message, 'database', $user->id, null);
                }
            }
            if ($mail) {
                foreach ($mailUsers as $user) {
                    $this->queueDelivery($message, 'mail', $user->id, null);
                }
                foreach ($customers as $customer) {
                    $this->queueDelivery($message, 'mail', null, $customer->email);
                }
            }
            $this->audit->record('admin.notification.submitted', $actor, [
                'notification_uuid' => $message->uuid, 'audience_type' => $message->audience_type,
                'audience_identifier' => $message->audience_identifier, 'channels' => $message->channels,
                'recipient_count' => $message->recipient_count, 'email_only_count' => $message->email_only_count,
                'unreachable_count' => $message->unreachable_count,
            ], $message);

            return $message;
        });
    }

    private function queueDelivery(AdminNotification $message, string $channel, ?int $userId, ?string $email): void
    {
        $id = DB::table('admin_notification_deliveries')->insertGetId([
            'admin_notification_id' => $message->id, 'channel' => $channel, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DeliverAdminNotification::dispatch($id, $userId, $email, $channel, $message->subject, $message->message, $message->uuid);
    }
}
