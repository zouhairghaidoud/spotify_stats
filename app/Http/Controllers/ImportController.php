<?php

namespace App\Http\Controllers;

use App\Jobs\ImportHistoryJob;
use App\Models\HistoryImport;
use App\Services\HistoryImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportController extends Controller
{
    /**
     * Queue a Spotify data export for background import.
     */
    public function store(
        Request $request,
        HistoryImportService $imports,
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'extensions:json,zip', 'max:51200'],
        ]);

        $file = $request->file('file');

        try {
            $imports->assertReadable($file);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'That file could not be read as Spotify history. Upload the original JSON or ZIP export.',
            ]);
        }

        $path = $file->store('imports', 'local');

        $import = $request->user()->historyImports()->create([
            'status' => HistoryImport::STATUS_PENDING,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
        ]);

        ImportHistoryJob::dispatch($import);

        return back()->with(
            'success',
            'Your history export is queued. This page will update as it imports.',
        );
    }
}
