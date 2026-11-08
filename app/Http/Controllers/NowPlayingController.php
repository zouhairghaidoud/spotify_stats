<?php

namespace App\Http\Controllers;

use App\Services\SpotifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NowPlayingController extends Controller
{
    /**
     * Return the user's current playback in a compact shape for polling.
     */
    public function __invoke(Request $request, SpotifyService $spotify): JsonResponse
    {
        $user = $request->user();

        $payload = [
            'item' => null,
            'progress_ms' => 0,
            'is_playing' => false,
            'fetched_at' => now()->toIso8601String(),
        ];

        if (! $user->isConnectedToSpotify()) {
            return $this->respond($payload);
        }

        try {
            $playback = $spotify->getCurrentlyPlaying($user);
        } catch (Throwable $exception) {
            report($exception);

            return $this->respond($payload);
        }

        if (! is_array($playback)) {
            return $this->respond($payload);
        }

        $item = $playback['item'] ?? null;

        if (! is_array($item) || ($item['type'] ?? null) !== 'track') {
            return $this->respond($payload);
        }

        $payload['item'] = [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? '',
            'uri' => $item['uri'] ?? null,
            'duration_ms' => (int) ($item['duration_ms'] ?? 0),
            'external_url' => data_get($item, 'external_urls.spotify'),
            'album' => [
                'name' => data_get($item, 'album.name'),
                'images' => array_slice((array) data_get($item, 'album.images', []), 0, 2),
            ],
            'artists' => collect($item['artists'] ?? [])
                ->map(fn (mixed $artist): array => ['name' => data_get($artist, 'name')])
                ->values()
                ->all(),
        ];
        $payload['progress_ms'] = (int) ($playback['progress_ms'] ?? 0);
        $payload['is_playing'] = (bool) ($playback['is_playing'] ?? false);

        return $this->respond($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, private');
    }
}
