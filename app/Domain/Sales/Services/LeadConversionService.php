<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Customers\Services\CustomerManager;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class LeadConversionService
{
    public function __construct(private CustomerManager $customers, private AuditRecorder $audit) {}

    public function convert(Lead $lead, User $actor): Customer
    {
        return DB::transaction(function () use ($lead, $actor): Customer {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($lead->converted_customer_id) {
                return Customer::query()->findOrFail($lead->converted_customer_id);
            }

            $customer = Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)])->first();
            if (! $customer) {
                $customer = $this->customers->create([
                    'type' => $lead->organization ? 'organization' : 'individual', 'name' => $lead->organization ?: $lead->name,
                    'email' => $lead->email, 'phone' => $lead->phone, 'website' => null, 'tax_id' => null,
                    'billing_address' => $lead->location, 'city' => null, 'state' => null, 'country' => null,
                    'status' => 'active', 'source' => $lead->source, 'assigned_to' => $lead->assigned_to,
                    'metadata' => ['converted_from_lead_uuid' => $lead->uuid], 'product_ids' => array_filter([$lead->product_id]),
                    'contacts' => [['name' => $lead->name, 'job_title' => null, 'email' => $lead->email, 'phone' => $lead->phone, 'is_primary' => true]], 'addresses' => [],
                ], $actor);
            } elseif ($lead->product_id) {
                $customer->products()->syncWithoutDetaching([$lead->product_id]);
            }

            $lead->update(['converted_customer_id' => $customer->id, 'converted_at' => now(), 'status' => 'won']);
            $lead->activities()->create(['actor_id' => $actor->id, 'type' => 'converted', 'description' => "Lead converted to customer {$customer->customer_number}.", 'metadata' => ['customer_uuid' => $customer->uuid]]);
            $this->audit->record('lead.converted', $actor, ['lead_uuid' => $lead->uuid, 'customer_uuid' => $customer->uuid]);

            return $customer;
        });
    }
}
