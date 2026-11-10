<?php

namespace App\Http\Controllers;

use App\Services\HistoryImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryDataController extends Controller
{
    /**
     * Download every stored play as a CSV file.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $filename = 'spotify-history-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($user): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'played_at',
                'track_name',
                'artist_name',
                'album_name',
                'duration_ms',
                'spotify_track_id',
                'context',
            ]);

            $user->streams()
                ->leftJoin('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
                ->select([
                    'streams.played_at',
                    'streams.duration_ms',
                    'streams.spotify_track_id',
                    'streams.context',
                    'tracks.name as track_name',
                    'tracks.artist_name',
                    'tracks.album_name',
                ])
                ->orderBy('streams.played_at')
                ->toBase()
                ->cursor()
                ->each(function (object $row) use ($handle): void {
                    fputcsv($handle, [
                        $row->played_at,
                        $row->track_name ?? 'Unknown track',
                        $row->artist_name ?? '',
                        $row->album_name ?? '',
                        $row->duration_ms ?? '',
                        $row->spotify_track_id,
                        $row->context ?? '',
                    ]);
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Delete only plays that came from an uploaded export.
     */
    public function destroyImported(Request $request): RedirectResponse
    {
        $user = $request->user();

        $plays = $user->streams()
            ->where('context', HistoryImportService::CONTEXT)
            ->delete();

        $user->historyImports()->delete();

        return back()->with(
            'success',
            "Deleted {$plays} imported ".($plays === 1 ? 'play' : 'plays').'. Your Spotify-synced history was kept.',
        );
    }
}
