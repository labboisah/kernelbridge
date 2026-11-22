<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Customers\Services\CustomerManager;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class LeadConversionService
{
    public function __construct(private CustomerManager $customers, private AuditRecorder $audit) {}

    public function convert(Lead $lead, User $actor, ?int $partnerOwnerId = null): Customer
    {
        return DB::transaction(function () use ($lead, $actor, $partnerOwnerId): Customer {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($lead->converted_customer_id) {
                $customer = Customer::query()->findOrFail($lead->converted_customer_id);
                $this->assignPartnerOwner($customer, $partnerOwnerId);

                return $customer;
            }

            $customer = $lead->email
                ? Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)])->first()
                : null;
            if (! $customer) {
                $customer = $this->customers->create([
                    'type' => $lead->organization ? 'organization' : 'individual', 'name' => $lead->organization ?: $lead->name,
                    'email' => $lead->email, 'phone' => $lead->phone, 'website' => null, 'tax_id' => null,
                    'billing_address' => $lead->location, 'city' => null, 'state' => null, 'country' => null,
                    'status' => 'active', 'source' => $lead->source, 'assigned_to' => $lead->assigned_to,
                    'metadata' => [
                        'converted_from_lead_uuid' => $lead->uuid,
                        'organization_type' => $lead->organization_type,
                        'estimated_number_of_users' => $lead->number_of_users,
                    ], 'product_ids' => array_filter([$lead->product_id]),
                    'contacts' => [['name' => $lead->name, 'job_title' => null, 'email' => $lead->email, 'phone' => $lead->phone, 'is_primary' => true]], 'addresses' => [],
                ], $actor);
            } elseif ($lead->product_id) {
                $customer->products()->syncWithoutDetaching([$lead->product_id]);
            }
            $this->assignPartnerOwner($customer, $partnerOwnerId);

            $lead->update(['converted_customer_id' => $customer->id, 'converted_at' => now(), 'status' => 'won']);
            $lead->activities()->create(['actor_id' => $actor->id, 'type' => 'converted', 'description' => "Lead converted to customer {$customer->customer_number}.", 'metadata' => ['customer_uuid' => $customer->uuid]]);
            $this->audit->record('lead.converted', $actor, ['lead_uuid' => $lead->uuid, 'customer_uuid' => $customer->uuid]);

            return $customer;
        });
    }

    private function assignPartnerOwner(Customer $customer, ?int $partnerOwnerId): void
    {
        if ($partnerOwnerId === null) {
            return;
        }

        if ($customer->partner_user_id !== null && $customer->partner_user_id !== $partnerOwnerId) {
            throw ValidationException::withMessages([
                'status' => 'This email address is already associated with another partner’s client account. Ask the company team to review the conversion.',
            ]);
        }

        if ($customer->partner_user_id === null) {
            $customer->forceFill(['partner_user_id' => $partnerOwnerId])->save();
        }
    }
}
