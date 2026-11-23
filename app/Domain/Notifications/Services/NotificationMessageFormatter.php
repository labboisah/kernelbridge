<?php

namespace App\Domain\Notifications\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Validation\ValidationException;

final class NotificationMessageFormatter
{
    public function sanitize(string $message, string $format): string
    {
        if ($format === 'plain') {
            return $message;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,ul,ol,li,h1,h2,h3,a[href|title]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $clean = (new HTMLPurifier($config))->purify($message);
        if (trim(preg_replace('/[\s\x{00a0}]+/u', ' ', html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '') {
            throw ValidationException::withMessages(['message' => 'The message must contain text.']);
        }

        return $clean;
    }
}
