<?php

namespace App\Http\Controllers;

use App\Services\HistoryImportService;
use App\Services\TrackMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportController extends Controller
{
    /**
     * Import a Spotify data export and enrich its track metadata.
     */
    public function store(
        Request $request,
        HistoryImportService $imports,
        TrackMetadataService $metadata,
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'extensions:json,zip', 'max:51200'],
        ]);

        $user = $request->user();

        try {
            $result = $imports->import($user, $request->file('file'));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'That file could not be read as Spotify history. Upload the original JSON or ZIP export.',
            ]);
        }

        try {
            $metadata->enrich($user);
        } catch (Throwable $exception) {
            report($exception);
        }

        $message = "Imported {$result['imported']} plays from {$result['files']} file(s).";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']} short or invalid entries.";
        }

        return back()
            ->with('success', $message)
            ->with('import_result', $result);
    }
}
