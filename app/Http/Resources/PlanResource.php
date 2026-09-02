<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'product_id' => $this->product?->uuid, 'name' => $this->name, 'slug' => $this->slug,
            'description' => $this->description, 'billing_cycle' => $this->billing_cycle->value, 'price' => $this->price,
            'currency' => $this->currency, 'setup_fee' => $this->setup_fee, 'trial_days' => $this->trial_days,
            'grace_period_days' => $this->grace_period_days, 'is_active' => $this->is_active, 'is_public' => $this->is_public,
            'sort_order' => $this->sort_order, 'metadata' => $this->metadata,
            'features' => $this->whenLoaded('features'), 'limits' => $this->whenLoaded('limits'),
        ];
    }
}
