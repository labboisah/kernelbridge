<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Licensing\Services\PlatformEntitlementUpdateValidator;
use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementPlanMapping;
use App\Models\Plan;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class DirectPlanEntitlementMappingService
{
    public function __construct(private readonly PlatformEntitlementUpdateValidator $validator) {}

    /**
     * @param  array<string, mixed>  $grants
     */
    public function publish(Plan $plan, array $grants): EntitlementPlanMapping
    {
        return DB::transaction(function () use ($plan, $grants): EntitlementPlanMapping {
            $plan = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $product = $plan->product;
            if (! in_array($product->licensing_mode, ['direct', 'hybrid'], true)) {
                throw ValidationException::withMessages([
                    'grants' => 'Direct plan mappings are available only for Direct or Hybrid products.',
                ]);
            }

            $catalog = EntitlementCatalogRevision::query()
                ->where('product_id', $product->id)
                ->orderByDesc('catalog_revision')
                ->lockForUpdate()
                ->first();
            if (! $catalog) {
                throw ValidationException::withMessages([
                    'grants' => 'Publish a product entitlement catalog before mapping this plan.',
                ]);
            }

            $definitions = $this->validator->catalogDefinitions(
                $catalog->catalog,
                (string) $product->code,
                $catalog->catalog_revision,
            );
            $grantObject = json_decode(json_encode((object) $grants, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            if ($definitions['error'] !== null || ! $grantObject instanceof stdClass
                || ($error = $this->validator->grantSetError($grantObject, $definitions['definitions'] ?? [])) !== null) {
                throw ValidationException::withMessages([
                    'grants' => 'The plan grants must match the latest published catalog and its capability or limit definitions.',
                ]);
            }

            $revision = ((int) EntitlementPlanMapping::query()
                ->where('plan_id', $plan->id)
                ->max('source_revision')) + 1;
            $digest = CanonicalJson::digest($grantObject);

            return EntitlementPlanMapping::query()->create([
                'plan_id' => $plan->id,
                'entitlement_catalog_revision_id' => $catalog->id,
                'source_revision' => $revision,
                'grants' => json_decode(json_encode($grantObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                'mapping_digest' => $digest,
                'published_at' => now(),
            ]);
        });
    }
}
