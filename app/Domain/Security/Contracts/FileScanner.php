<?php

namespace App\Domain\Security\Contracts;

use Illuminate\Http\UploadedFile;

interface FileScanner
{
    /** Throw when a file is unsafe or cannot be scanned under the configured policy. */
    public function assertClean(UploadedFile $file): void;
}
