<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DemoRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FactoryCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_domain_factories_create_valid_model_graphs(): void
    {
        $models = [
            Product::factory()->create(), Plan::factory()->create(), Customer::factory()->create(),
            Lead::factory()->create(), DemoRequest::factory()->create(), Quotation::factory()->create(),
            Invoice::factory()->create(), Payment::factory()->create(), Subscription::factory()->create(),
            SoftwareLicense::factory()->create(), SupportTicket::factory()->create(),
        ];

        foreach ($models as $model) {
            $this->assertTrue($model->exists, $model::class.' factory did not persist a model.');
        }
        $license = collect($models)->first(fn ($model) => $model instanceof SoftwareLicense);
        $this->assertSame($license->subscription->customer_id, $license->customer_id);
        $this->assertSame($license->subscription->product_id, $license->product_id);
    }
}
