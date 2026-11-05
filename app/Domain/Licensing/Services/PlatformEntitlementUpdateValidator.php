<?php

namespace App\Domain\Licensing\Services;

use DateTimeZone;
use JsonException;
use stdClass;

final class PlatformEntitlementUpdateValidator
{
    /**
     * @return array{payload: stdClass|null, error: string|null}
     */
    public function decode(string $payloadJson): array
    {
        if ($this->containsDuplicateObjectKey($payloadJson)) {
            return ['payload' => null, 'error' => 'duplicate_key'];
        }

        try {
            $payload = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['payload' => null, 'error' => 'malformed_json'];
        }

        return is_object($payload)
            ? ['payload' => $payload, 'error' => null]
            : ['payload' => null, 'error' => 'malformed_grant'];
    }

    /**
     * @return array{definitions: array<string, array<string, mixed>>|null, error: string|null}
     */
    public function catalogDefinitions(array $catalog, string $productCode, int $catalogRevision): array
    {
        if (($catalog['schema_version'] ?? null) !== 1
            || strtoupper((string) ($catalog['product_code'] ?? '')) !== strtoupper($productCode)
            || ($catalog['catalog_revision'] ?? null) !== $catalogRevision
            || ! is_array($catalog['packages'] ?? null)
            || ! is_array($catalog['plans'] ?? null)) {
            return ['definitions' => null, 'error' => 'catalog_invalid'];
        }

        $definitions = [];
        foreach ($catalog['packages'] as $package) {
            if (! is_array($package) || ! is_array($package['modules'] ?? null)) {
                return ['definitions' => null, 'error' => 'catalog_invalid'];
            }
            foreach ($package['modules'] as $module) {
                if (! is_array($module) || ! is_array($module['capabilities'] ?? null)) {
                    return ['definitions' => null, 'error' => 'catalog_invalid'];
                }
                foreach ($module['capabilities'] as $capability) {
                    if (! is_array($capability)
                        || ! is_string($capability['key'] ?? null)
                        || ! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $capability['key'])
                        || isset($definitions[$capability['key']])) {
                        return ['definitions' => null, 'error' => 'catalog_invalid'];
                    }
                    if (($capability['type'] ?? null) === 'capability') {
                        $definitions[$capability['key']] = ['type' => 'capability'];

                        continue;
                    }
                    if (($capability['type'] ?? null) !== 'limit'
                        || ! $this->isIdentifier($capability['unit'] ?? null)
                        || ! $this->isIdentifier($capability['scope'] ?? null)) {
                        return ['definitions' => null, 'error' => 'catalog_invalid'];
                    }

                    $definition = [
                        'type' => 'limit',
                        'unit' => $capability['unit'],
                        'scope' => $capability['scope'],
                    ];
                    if (array_key_exists('period', $capability)) {
                        $period = $capability['period'];
                        if (! $this->isValidPeriod($period)) {
                            return ['definitions' => null, 'error' => 'catalog_invalid'];
                        }
                        $definition['period'] = $period;
                    }
                    $definitions[$capability['key']] = $definition;
                }
            }
        }

        return ['definitions' => $definitions, 'error' => null];
    }

    /** @return array{valid: bool, error: string|null} */
    public function validateCatalog(stdClass $catalog, string $productCode): array
    {
        if (! $this->hasOnlyProperties($catalog, ['schema_version', 'product_code', 'catalog_revision', 'packages', 'plans'])) {
            return $this->invalid('catalog_invalid');
        }
        if (($catalog->schema_version ?? null) !== 1) {
            return $this->invalid('unsupported_schema');
        }
        if (! is_string($catalog->product_code ?? null)
            || strtoupper($catalog->product_code) !== strtoupper($productCode)) {
            return $this->invalid('product_mismatch');
        }
        if (! is_int($catalog->catalog_revision ?? null) || $catalog->catalog_revision < 1) {
            return $this->invalid('catalog_invalid');
        }
        if (! is_array($catalog->packages ?? null) || ! is_array($catalog->plans ?? null)) {
            return $this->invalid('catalog_invalid');
        }

        $definitions = [];
        $packageKeys = [];
        $moduleKeys = [];
        foreach ($catalog->packages as $package) {
            if (! is_object($package)
                || ! $this->hasOnlyProperties($package, ['key', 'modules'])
                || ! $this->isIdentifier($package->key ?? null)
                || isset($packageKeys[$package->key])
                || ! is_array($package->modules ?? null)) {
                return $this->invalid('catalog_invalid');
            }
            $packageKeys[$package->key] = true;
            foreach ($package->modules as $module) {
                if (! is_object($module)
                    || ! $this->hasOnlyProperties($module, ['key', 'capabilities'])
                    || ! $this->isIdentifier($module->key ?? null)
                    || isset($moduleKeys[$module->key])
                    || ! is_array($module->capabilities ?? null)) {
                    return $this->invalid('catalog_invalid');
                }
                $moduleKeys[$module->key] = true;
                foreach ($module->capabilities as $capability) {
                    if (! is_object($capability)
                        || ! is_string($capability->key ?? null)
                        || ! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $capability->key)
                        || isset($definitions[$capability->key])) {
                        return $this->invalid('catalog_invalid');
                    }
                    if (($capability->description ?? null) !== null
                        && (! is_string($capability->description) || strlen($capability->description) > 500)) {
                        return $this->invalid('catalog_invalid');
                    }
                    if (($capability->type ?? null) === 'capability') {
                        if (! $this->hasOnlyProperties($capability, ['key', 'type', 'description'])) {
                            return $this->invalid('catalog_invalid');
                        }
                        $definitions[$capability->key] = ['type' => 'capability'];

                        continue;
                    }
                    if (($capability->type ?? null) !== 'limit'
                        || ! $this->hasOnlyProperties($capability, ['key', 'type', 'unit', 'scope', 'period', 'description'])
                        || ! $this->isIdentifier($capability->unit ?? null)
                        || ! $this->isIdentifier($capability->scope ?? null)) {
                        return $this->invalid('catalog_invalid');
                    }

                    $definition = [
                        'type' => 'limit',
                        'unit' => $capability->unit,
                        'scope' => $capability->scope,
                    ];
                    if (property_exists($capability, 'period')) {
                        if (! $this->isValidPeriod($capability->period)) {
                            return $this->invalid('catalog_invalid');
                        }
                        $definition['period'] = get_object_vars($capability->period);
                    }
                    $definitions[$capability->key] = $definition;
                }
            }
        }

        $planKeys = [];
        foreach ($catalog->plans as $plan) {
            if (! is_object($plan)
                || ! $this->hasOnlyProperties($plan, ['key', 'grants'])
                || ! $this->isIdentifier($plan->key ?? null)
                || isset($planKeys[$plan->key])
                || ! is_object($plan->grants ?? null)) {
                return $this->invalid('catalog_invalid');
            }
            $planKeys[$plan->key] = true;
            $error = $this->grantSetError($plan->grants, $definitions);
            if ($error !== null) {
                return $this->invalid('catalog_invalid');
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * @param  array{
     *     product_code: string,
     *     license_uuid: string,
     *     account_link_uuid: string,
     *     catalog_revision: int,
     *     current_source_revision: int,
     *     grant_definitions: array<string, array<string, mixed>>
     * }  $context
     * @return array{valid: bool, error: string|null}
     */
    public function validate(stdClass $payload, array $context): array
    {
        if (property_exists($payload, 'source')) {
            return $this->invalid('invalid_source');
        }
        if (! $this->hasOnlyProperties($payload, [
            'schema_version',
            'product_code',
            'license_uuid',
            'account_link_uuid',
            'catalog_revision',
            'source_revision',
            'idempotency_key',
            'grants',
        ])) {
            return $this->invalid('malformed_grant');
        }
        if (($payload->schema_version ?? null) !== 1) {
            return $this->invalid('unsupported_schema');
        }
        if (! is_string($payload->product_code ?? null)
            || strtoupper($payload->product_code) !== strtoupper($context['product_code'])) {
            return $this->invalid('product_mismatch');
        }
        if (($payload->license_uuid ?? null) !== $context['license_uuid']) {
            return $this->invalid('license_mismatch');
        }
        if (($payload->account_link_uuid ?? null) !== $context['account_link_uuid']) {
            return $this->invalid('account_link_mismatch');
        }
        if (! is_int($payload->catalog_revision ?? null)
            || $payload->catalog_revision !== $context['catalog_revision']) {
            return $this->invalid('catalog_revision_mismatch');
        }
        if (! is_int($payload->source_revision ?? null) || $payload->source_revision < 1) {
            return $this->invalid('malformed_grant');
        }
        if ($payload->source_revision <= $context['current_source_revision']) {
            return $this->invalid('stale_source_revision');
        }
        if (! is_string($payload->idempotency_key ?? null)
            || trim($payload->idempotency_key) === ''
            || strlen($payload->idempotency_key) > 128) {
            return $this->invalid('malformed_grant');
        }
        if (! is_object($payload->grants ?? null)) {
            return $this->invalid('malformed_grant');
        }

        foreach (get_object_vars($payload->grants) as $key => $grant) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key)) {
                return $this->invalid('malformed_grant');
            }
            $definition = $context['grant_definitions'][$key] ?? null;
            if (! is_array($definition)) {
                return $this->invalid('unknown_grant');
            }
            $error = $this->validateGrant($grant, $definition);
            if ($error !== null) {
                return $this->invalid($error);
            }
        }

        return ['valid' => true, 'error' => null];
    }

    private function validateGrant(mixed $grant, array $definition): ?string
    {
        if (! is_object($grant) || ! is_string($grant->type ?? null)) {
            return 'malformed_grant';
        }
        if (! in_array($grant->type, ['capability', 'limit'], true)) {
            return 'unknown_grant_type';
        }
        if ($grant->type === 'capability') {
            return ($definition['type'] ?? null) === 'capability'
                && $this->hasOnlyProperties($grant, ['type', 'enabled'])
                && is_bool($grant->enabled ?? null)
                    ? null
                    : 'malformed_grant';
        }
        if (($definition['type'] ?? null) !== 'limit'
            || ! in_array($grant->state ?? null, ['finite', 'unlimited'], true)) {
            return 'malformed_grant';
        }
        $allowed = $grant->state === 'finite'
            ? ['type', 'state', 'value', 'unit', 'scope', 'period']
            : ['type', 'state', 'unit', 'scope', 'period'];
        if (! $this->hasOnlyProperties($grant, $allowed)) {
            return 'malformed_grant';
        }
        if ($grant->state === 'finite'
            && ((! is_int($grant->value ?? null) && ! is_float($grant->value ?? null))
                || ! is_finite((float) ($grant->value ?? 0))
                || $grant->value < 0)) {
            return 'malformed_grant';
        }
        if (! is_string($grant->unit ?? null) || $grant->unit !== ($definition['unit'] ?? null)) {
            return 'invalid_unit';
        }
        if (! is_string($grant->scope ?? null) || $grant->scope !== ($definition['scope'] ?? null)) {
            return 'invalid_scope';
        }

        $hasPeriod = property_exists($grant, 'period');
        if (isset($definition['period'])) {
            if (! $hasPeriod || ! $this->isValidPeriod($grant->period)) {
                return 'invalid_period';
            }
            if ($grant->period->unit !== $definition['period']['unit']
                || $grant->period->reset !== $definition['period']['reset']
                || $grant->period->time_zone !== $definition['period']['time_zone']) {
                return 'invalid_period';
            }
        } elseif ($hasPeriod) {
            return 'invalid_period';
        }

        return null;
    }

    private function isValidPeriod(mixed $period): bool
    {
        if (is_object($period)) {
            $period = get_object_vars($period);
        }
        if (! is_array($period)
            || array_diff(array_keys($period), ['unit', 'reset', 'time_zone']) !== []
            || ! in_array($period['unit'] ?? null, ['day', 'week', 'month', 'year'], true)
            || ($period['reset'] ?? null) !== 'calendar'
            || ! is_string($period['time_zone'] ?? null)) {
            return false;
        }

        return in_array($period['time_zone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    private function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1;
    }

    public function grantSetError(stdClass $grants, array $definitions): ?string
    {
        foreach (get_object_vars($grants) as $key => $grant) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key)) {
                return 'malformed_grant';
            }
            $definition = $definitions[$key] ?? null;
            if (! is_array($definition)) {
                return 'unknown_grant';
            }
            $error = $this->validateGrant($grant, $definition);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function hasOnlyProperties(object $value, array $allowed): bool
    {
        foreach (array_keys(get_object_vars($value)) as $key) {
            if (! in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function containsDuplicateObjectKey(string $json): bool
    {
        $length = strlen($json);
        $objectKeys = [];
        for ($index = 0; $index < $length; $index++) {
            if ($json[$index] === '{') {
                $objectKeys[] = [];

                continue;
            }
            if ($json[$index] === '}') {
                array_pop($objectKeys);

                continue;
            }
            if ($json[$index] !== '"') {
                continue;
            }

            $start = $index;
            $escaped = false;
            for ($index++; $index < $length; $index++) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($json[$index] === '\\') {
                    $escaped = true;
                } elseif ($json[$index] === '"') {
                    break;
                }
            }
            $token = substr($json, $start, $index - $start + 1);
            $lookahead = $index + 1;
            while ($lookahead < $length && ctype_space($json[$lookahead])) {
                $lookahead++;
            }
            if (($json[$lookahead] ?? null) !== ':' || $objectKeys === []) {
                continue;
            }

            try {
                $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }
            $stackIndex = array_key_last($objectKeys);
            if (array_key_exists($key, $objectKeys[$stackIndex])) {
                return true;
            }
            $objectKeys[$stackIndex][$key] = true;
        }

        return false;
    }

    /** @return array{valid: false, error: string} */
    private function invalid(string $error): array
    {
        return ['valid' => false, 'error' => $error];
    }
}
