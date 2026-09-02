<?php

namespace App\Domain\Customers\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CustomerManager
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($data, $actor): Customer {
            $customer = Customer::query()->create($this->attributes($data));
            $customer->forceFill(['customer_number' => sprintf('KBT-CUS-%06d', $customer->id)])->save();
            $this->synchronize($customer, $data);
            $this->audit->record('customer.created', $actor, ['customer_uuid' => $customer->uuid, 'after' => $this->snapshot($customer)]);

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor): Customer {
            $before = $this->snapshot($customer);
            $customer->update($this->attributes($data));
            $this->synchronize($customer, $data);
            $this->audit->record('customer.updated', $actor, ['customer_uuid' => $customer->uuid, 'before' => $before, 'after' => $this->snapshot($customer)]);

            return $customer;
        });
    }

    public function delete(Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor): void {
            $snapshot = $this->snapshot($customer);
            $uuid = $customer->uuid;
            $customer->delete();
            $this->audit->record('customer.deleted', $actor, ['customer_uuid' => $uuid, 'before' => $snapshot]);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::except($data, ['contacts', 'addresses', 'product_ids']);
    }

    /** @param array<string, mixed> $data */
    private function synchronize(Customer $customer, array $data): void
    {
        $customer->products()->sync($data['product_ids'] ?? []);
        $customer->contacts()->delete();
        foreach ($data['contacts'] ?? [] as $contact) {
            $customer->contacts()->create($contact);
        }
        $customer->addresses()->delete();
        foreach ($data['addresses'] ?? [] as $address) {
            $address['country'] = strtoupper($address['country']);
            $customer->addresses()->create($address);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Customer $customer): array
    {
        return $customer->fresh()->load(['contacts', 'addresses', 'products'])->toArray();
    }
}
