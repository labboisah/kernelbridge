<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'lead_number' => $this->lead_number, 'name' => $this->name, 'organization' => $this->organization,
            'email' => $this->email, 'phone' => $this->phone, 'location' => $this->location, 'organization_type' => $this->organization_type,
            'number_of_users' => $this->number_of_users, 'source' => $this->source, 'status' => $this->status->value,
            'follow_up_date' => $this->follow_up_date?->toDateString(), 'assigned_to' => $this->assigned_to,
            'product_id' => $this->product_id, 'product' => $this->whenLoaded('product'), 'assigned_user' => $this->whenLoaded('assignedUser'),
            'converted_customer' => $this->whenLoaded('convertedCustomer'), 'demo_requests' => $this->whenLoaded('demoRequests'),
            'activities' => $this->whenLoaded('activities'), 'notes' => $this->whenLoaded('notes'), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
