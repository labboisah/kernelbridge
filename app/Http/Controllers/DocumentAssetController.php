<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Security\Contracts\FileScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DocumentAssetController extends Controller
{
    public function update(Request $request, string $kind, FileScanner $scanner, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'super_admin']), 403);
        $request->validate(['asset' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $scanner->assertClean($request->file('asset'));
        $path = $request->file('asset')->store('document-assets', 'local');
        if ($path === false) {
            throw new RuntimeException('Unable to store the document image.');
        }
        try {
            $oldPath = DB::transaction(function () use ($kind, $path, $request, $audit): ?string {
                $column = $kind.'_path';
                $settings = DB::table('document_assets')->where('id', 1)->lockForUpdate()->first();
                if (! $settings) {
                    throw new RuntimeException('Document asset settings are missing.');
                }
                DB::table('document_assets')->where('id', 1)->update([$column => $path]);
                $audit->record('document_asset.updated', $request->user(), ['kind' => $kind]);

                return $settings->$column;
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($path)) {
                report(new RuntimeException('Unable to clean up an unused document image.'));
            }
            throw $exception;
        }
        $this->deleteFile($oldPath);

        return redirect()->route('profile.edit')->with('success', ucfirst($kind).' updated for official documents.');
    }

    public function destroy(Request $request, string $kind, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'super_admin']), 403);
        $oldPath = DB::transaction(function () use ($kind, $request, $audit): ?string {
            $column = $kind.'_path';
            $settings = DB::table('document_assets')->where('id', 1)->lockForUpdate()->first();
            if (! $settings) {
                throw new RuntimeException('Document asset settings are missing.');
            }
            DB::table('document_assets')->where('id', 1)->update([$column => null]);
            $audit->record('document_asset.removed', $request->user(), ['kind' => $kind]);

            return $settings->$column;
        });
        $this->deleteFile($oldPath);

        return redirect()->route('profile.edit')->with('success', ucfirst($kind).' removed from official documents.');
    }

    private function deleteFile(?string $path): void
    {
        if ($path && ! Storage::disk('local')->delete($path)) {
            report(new RuntimeException('Unable to remove a replaced document image.'));
        }
    }
}
