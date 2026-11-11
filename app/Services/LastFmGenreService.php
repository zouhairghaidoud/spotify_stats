<?php

namespace App\Services;

use App\Models\Artist;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up artist genres from Last.fm top tags.
 *
 * MusicBrainz does not tag every artist, especially newer or regional acts.
 * Last.fm is used as a fallback whenever MusicBrainz returns no genres.
 */
class LastFmGenreService
{
    /**
     * Tags that describe listeners rather than the music itself.
     *
     * @var array<int, string>
     */
    private const IGNORED_TAGS = [
        'seen live',
        'favorites',
        'favourite',
        'favourites',
        'spotify',
        'love',
        'awesome',
        'good',
        'under 2000 listeners',
    ];

    /**
     * @return array<int, string>
     */
    public function lookup(Artist $artist): array
    {
        $apiKey = config('services.lastfm.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get(rtrim((string) config('services.lastfm.base_url', 'https://ws.audioscrobbler.com/2.0'), '/').'/', [
                    'method' => 'artist.gettoptags',
                    'artist' => $artist->name,
                    'api_key' => $apiKey,
                    'format' => 'json',
                    'autocorrect' => 1,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        $tags = data_get($data, 'toptags.tag', []);

        if (! is_array($tags)) {
            return [];
        }

        return $this->names($tags);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tags
     * @return array<int, string>
     */
    private function names(array $tags): array
    {
        $tags = array_values(array_filter(
            $tags,
            fn (mixed $tag): bool => is_array($tag) && filled($tag['name'] ?? null),
        ));

        usort($tags, fn (array $left, array $right): int => (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0));

        $names = [];

        foreach ($tags as $tag) {
            $name = mb_strtolower(trim((string) $tag['name']));

            if ($name === '' || in_array($name, self::IGNORED_TAGS, true)) {
                continue;
            }

            $names[$name] = true;
        }

        $limit = max(1, (int) config('services.lastfm.tag_limit', 5));

        return array_slice(array_keys($names), 0, $limit);
    }
}
