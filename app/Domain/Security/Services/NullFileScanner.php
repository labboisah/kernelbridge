<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\Contracts\FileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class NullFileScanner implements FileScanner
{
    public function assertClean(UploadedFile $file): void
    {
        // Extension point for ClamAV or a managed malware-scanning provider.
        if (! $file->isValid() || ! is_readable($file->getRealPath())) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be safely inspected.']);
        }
    }
}
