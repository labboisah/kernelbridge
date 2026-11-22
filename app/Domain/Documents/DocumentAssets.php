<?php

namespace App\Domain\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DocumentAssets
{
    /** @return array{stamp: ?string, signature: ?string} */
    public function images(): array
    {
        $settings = DB::table('document_assets')->where('id', 1)->first();
        if (! $settings) {
            throw new RuntimeException('Document asset settings are missing.');
        }

        $images = [];
        foreach (['stamp', 'signature'] as $kind) {
            $path = $settings->{$kind.'_path'};
            if (! $path) {
                $images[$kind] = null;

                continue;
            }
            $disk = Storage::disk('local');
            if (! $disk->exists($path) || ! in_array($mime = $disk->mimeType($path), ['image/png', 'image/jpeg'], true)) {
                throw new RuntimeException("The configured document $kind is missing or invalid.");
            }
            $images[$kind] = 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
        }

        return $images;
    }
}
