<?php

namespace Tests\Feature;

use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementFrameworkAudit;
use App\Models\EntitlementPlanMapping;
use App\Models\EntitlementSourceGrant;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EntitlementFrameworkPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_framework_tables_migrate_and_roll_back_without_changing_existing_plan_data(): void
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $featureUuid = (string) Str::uuid();
        DB::table('plan_features')->insert([
            'uuid' => $featureUuid,
            'plan_id' => $plan->id,
            'feature_key' => 'feature.existing',
            'feature_name' => 'Existing feature',
            'enabled' => true,
            'limit_value' => '10',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $existingFeature = DB::table('plan_features')->where('uuid', $featureUuid)->first();

        $migration = require database_path('migrations/2026_11_03_000001_create_entitlement_framework_tables.php');
        $migration->down();

        foreach ($this->frameworkTables() as $table) {
            self::assertFalse(Schema::hasTable($table), $table);
        }

        $migration->up();

        foreach ($this->frameworkTables() as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        self::assertSame(
            (array) $existingFeature,
            (array) DB::table('plan_features')->where('uuid', $featureUuid)->first(),
        );
        self::assertFalse(Schema::hasColumn('entitlement_framework_audits', 'payload'));
        self::assertFalse(Schema::hasColumn('entitlement_framework_audits', 'metadata'));
    }

    public function test_catalog_mapping_revisions_are_unique_and_audit_records_are_immutable(): void
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $catalog = EntitlementCatalogRevision::query()->create([
            'product_id' => $product->id,
            'schema_version' => 1,
            'catalog_revision' => 1,
            'catalog' => ['schema_version' => 1, 'product_code' => $product->code, 'packages' => [], 'plans' => []],
            'catalog_digest' => str_repeat('a', 64),
            'published_at' => now(),
        ]);
        self::assertSame(1, $catalog->refresh()->catalog_revision);
        self::assertIsArray($catalog->catalog);

        EntitlementPlanMapping::query()->create([
            'plan_id' => $plan->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source_revision' => 1,
            'grants' => ['feature.existing' => ['type' => 'capability', 'enabled' => true]],
            'mapping_digest' => str_repeat('b', 64),
            'published_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        EntitlementPlanMapping::query()->create([
            'plan_id' => $plan->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source_revision' => 1,
            'grants' => [],
            'mapping_digest' => str_repeat('c', 64),
            'published_at' => now(),
        ]);
    }

    public function test_framework_audit_model_rejects_mutation(): void
    {
        $product = Product::factory()->create();
        $audit = EntitlementFrameworkAudit::query()->create([
            'product_id' => $product->id,
            'source' => 'product_platform',
            'operation' => 'grant.update',
            'outcome' => 'rejected',
            'error_code' => 'stale_source_revision',
            'occurred_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $audit->forceFill(['outcome' => 'accepted'])->save();
    }

    public function test_customer_source_grants_cannot_be_written_as_direct_grants(): void
    {
        $grant = new EntitlementSourceGrant(['source' => 'direct']);

        $this->expectException(\InvalidArgumentException::class);
        $grant->save();
    }

    /** @return list<string> */
    private function frameworkTables(): array
    {
        return [
            'entitlement_catalog_revisions',
            'entitlement_plan_mappings',
            'entitlement_source_grants',
            'entitlement_framework_audits',
        ];
    }
}
