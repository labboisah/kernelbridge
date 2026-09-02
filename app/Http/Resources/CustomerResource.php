<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'customer_number' => $this->customer_number, 'type' => $this->type->value,
            'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'website' => $this->website,
            'tax_id' => $this->tax_id, 'billing_address' => $this->billing_address, 'city' => $this->city,
            'state' => $this->state, 'country' => $this->country, 'status' => $this->status->value, 'source' => $this->source,
            'assigned_to' => $this->assigned_to, 'assigned_user' => $this->whenLoaded('assignedUser'), 'metadata' => $this->metadata,
            'contacts' => $this->whenLoaded('contacts'), 'addresses' => $this->whenLoaded('addresses'),
            'products' => $this->whenLoaded('products'), 'notes' => $this->whenLoaded('notes'),
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
