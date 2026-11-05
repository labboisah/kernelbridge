<?php

namespace App\Support;

use JsonException;
use stdClass;

final class CanonicalJson
{
    /** @throws JsonException */
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(
            self::sortObjectKeys($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $canonical = new stdClass;
            foreach ($properties as $key => $property) {
                $canonical->{$key} = self::sortObjectKeys($property);
            }

            return $canonical;
        }
        if (is_array($value)) {
            return array_map(self::sortObjectKeys(...), $value);
        }

        return $value;
    }
}
