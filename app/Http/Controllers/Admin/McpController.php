<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class McpController extends Controller
{
    private const PRESETS = [
        'support_read' => [
            'label' => 'Read-only support',
            'description' => 'Search customers, inspect services, explain activation issues, and list activations.',
            'scopes' => ['mcp:read'],
        ],
        'onboarding_helper' => [
            'label' => 'Onboarding helper',
            'description' => 'Read customer services and generate setup values for package installation.',
            'scopes' => ['mcp:read'],
        ],
        'license_operator' => [
            'label' => 'License operator',
            'description' => 'Read support context and run guarded license issue/deactivation tools.',
            'scopes' => ['mcp:read', 'mcp:write'],
        ],
        'documentation_only' => [
            'label' => 'Documentation only',
            'description' => 'Use local MCP documentation tools without platform customer or license access.',
            'scopes' => ['mcp:docs'],
        ],
    ];

    public function index(Request $request): Response
    {
        $filters = [
            'tool' => (string) $request->query('tool', ''),
            'mutation' => (string) $request->query('mutation', ''),
            'credential' => (string) $request->query('credential', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $clients = ApiClient::query()
            ->with(['product:id,uuid,name,code', 'credentials' => fn ($query) => $query->latest()])
            ->latest()
            ->get()
            ->filter(fn (ApiClient $client) => $this->isMcpClient($client))
            ->values()
            ->map(fn (ApiClient $client) => [
                'uuid' => $client->uuid,
                'name' => $client->name,
                'product' => $client->product ? ['uuid' => $client->product->uuid, 'name' => $client->product->name, 'code' => $client->product->code] : null,
                'is_active' => $client->is_active,
                'rate_limit_per_minute' => $client->rate_limit_per_minute,
                'allowed_ips' => $client->allowed_ips ?? [],
                'scopes' => $client->scopes ?? [],
                'last_used_at' => $client->last_used_at?->toDateTimeString(),
                'credentials' => $client->credentials->map(fn (ApiClientCredential $credential) => [
                    'uuid' => $credential->uuid,
                    'name' => $credential->name,
                    'expires_at' => $credential->expires_at?->toDateString(),
                    'last_used_at' => $credential->last_used_at?->toDateTimeString(),
                    'revoked_at' => $credential->revoked_at?->toDateTimeString(),
                    'is_active' => $credential->revoked_at === null && ($credential->expires_at === null || $credential->expires_at->isFuture()),
                ]),
            ]);

        $auditQuery = DB::table('mcp_audit_logs')
            ->leftJoin('api_clients', 'api_clients.id', '=', 'mcp_audit_logs.api_client_id')
            ->leftJoin('api_client_credentials', 'api_client_credentials.id', '=', 'mcp_audit_logs.api_client_credential_id')
            ->leftJoin('products', 'products.id', '=', 'api_clients.product_id')
            ->select('mcp_audit_logs.*', 'api_clients.name as client_name', 'api_client_credentials.uuid as credential_uuid', 'api_client_credentials.name as credential_name', 'products.name as product_name', 'products.code as product_code')
            ->when($filters['tool'] !== '', fn ($query) => $query->where('mcp_audit_logs.tool_name', $filters['tool']))
            ->when($filters['credential'] !== '', fn ($query) => $query->where('api_client_credentials.uuid', $filters['credential']))
            ->when($filters['mutation'] !== '', fn ($query) => $query->where('mcp_audit_logs.mutation', $filters['mutation'] === 'true'))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('mcp_audit_logs.created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('mcp_audit_logs.created_at', '<=', $filters['date_to']))
            ->orderByDesc('mcp_audit_logs.created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'request_id' => $row->request_id,
                'tool_name' => $row->tool_name,
                'mutation' => (bool) $row->mutation,
                'target_type' => $row->target_type,
                'target_uuid' => $row->target_uuid,
                'client_name' => $row->client_name,
                'credential_uuid' => $row->credential_uuid,
                'credential_name' => $row->credential_name,
                'product' => $row->product_name ? $row->product_name.' ('.$row->product_code.')' : null,
                'ip_address' => $row->ip_address,
                'created_at' => $row->created_at,
            ]);

        return Inertia::render('Admin/Mcp/Index', [
            'products' => Product::query()->orderBy('name')->get(['uuid', 'name', 'code']),
            'clients' => $clients,
            'presets' => self::PRESETS,
            'filters' => $filters,
            'auditLogs' => $auditQuery,
            'toolOptions' => DB::table('mcp_audit_logs')->distinct()->orderBy('tool_name')->pluck('tool_name'),
            'credentialOptions' => $clients->flatMap(fn (array $client) => collect($client['credentials'])->map(fn (array $credential) => ['uuid' => $credential['uuid'], 'name' => $credential['name'].' - '.$client['name']]))->values(),
            'apiUrl' => url('/api/v1'),
            'issuedToken' => session('mcp_token'),
            'issuedCredential' => session('mcp_credential'),
        ]);
    }

    public function storeClient(Request $request, ApiCredentialService $credentials): RedirectResponse
    {
        $data = $request->validate([
            'product_uuid' => ['required', 'exists:products,uuid'],
            'preset' => ['required', 'in:'.implode(',', array_keys(self::PRESETS))],
            'name' => ['required', 'string', 'max:255'],
            'credential_name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:600'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::query()->where('uuid', $data['product_uuid'])->firstOrFail();
        $client = $credentials->createClient($product, $data['name'], [
            'rate_limit_per_minute' => (int) $data['rate_limit_per_minute'],
            'allowed_ips' => $this->parseAllowedIps($data['allowed_ips'] ?? ''),
            'scopes' => self::PRESETS[$data['preset']]['scopes'],
        ]);
        $issued = $credentials->issue($client, $data['credential_name'], $request->user(), $this->expiresAt($data['expires_at'] ?? null));

        return back()->with('success', 'MCP API client token issued. Copy it now; it will only be shown once.')->with('mcp_token', $issued['token'])->with('mcp_credential', ['client' => $client->name, 'credential' => $issued['credential']->name, 'product' => $product->code]);
    }

    public function rotateCredential(Request $request, ApiClientCredential $credential, ApiCredentialService $credentials): RedirectResponse
    {
        abort_unless($this->isMcpClient($credential->client), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'expires_at' => ['nullable', 'date']]);
        $issued = $credentials->rotate($credential, $data['name'], $request->user(), $this->expiresAt($data['expires_at'] ?? null));

        return back()->with('success', 'Credential rotated. Copy the replacement token now; it will only be shown once.')->with('mcp_token', $issued['token'])->with('mcp_credential', ['client' => $credential->client->name, 'credential' => $issued['credential']->name, 'product' => $credential->client->product?->code]);
    }

    public function revokeCredential(Request $request, ApiClientCredential $credential, ApiCredentialService $credentials): RedirectResponse
    {
        abort_unless($this->isMcpClient($credential->client), 404);
        $credentials->revoke($credential, $request->user());

        return back()->with('success', 'MCP credential revoked.');
    }

    private function isMcpClient(ApiClient $client): bool
    {
        return collect($client->scopes ?? [])->contains(fn (string $scope) => str_starts_with($scope, 'mcp:'));
    }

    private function parseAllowedIps(string $value): ?array
    {
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));

        return $ips === [] ? null : $ips;
    }

    private function expiresAt(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value)->endOfDay() : null;
    }
}

