<?php

namespace App\Jobs;

use App\Models\HistoryImport;
use App\Services\HistoryImportService;
use App\Services\TrackMetadataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public HistoryImport $historyImport) {}

    public function handle(HistoryImportService $imports, TrackMetadataService $metadata): void
    {
        $import = $this->historyImport;
        $import->forceFill([
            'status' => HistoryImport::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $path = Storage::disk('local')->path($import->stored_path);

        try {
            $file = new UploadedFile($path, $import->original_name, null, null, true);

            $result = $imports->import(
                $import->user,
                $file,
                function (int $imported, int $skipped, int $files) use ($import): void {
                    $import->forceFill([
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'files' => $files,
                    ])->save();
                },
            );

            try {
                $metadata->enrich($import->user);
            } catch (Throwable $exception) {
                report($exception);
            }

            $import->forceFill([
                'status' => HistoryImport::STATUS_COMPLETED,
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'files' => $result['files'],
                'tracks' => $result['tracks'],
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $import->forceFill([
                'status' => HistoryImport::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ])->save();
        } finally {
            Storage::disk('local')->delete($import->stored_path);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->historyImport->forceFill([
            'status' => HistoryImport::STATUS_FAILED,
            'error' => Str::limit($exception->getMessage(), 500),
            'finished_at' => now(),
        ])->save();
    }
}
