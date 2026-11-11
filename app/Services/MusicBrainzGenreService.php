<?php

namespace App\Services;

use App\Models\Artist;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Looks up artist genres from MusicBrainz.
 *
 * Spotify stopped returning genres to new applications, so this service maps
 * a Spotify artist id to its MusicBrainz entry and stores the community tags.
 */
class MusicBrainzGenreService
{
    private const GENRE_LIMIT = 5;

    /**
     * @return array{musicbrainz_id: string|null, genres: array<int, string>}
     */
    public function lookup(Artist $artist): array
    {
        $musicbrainzId = $artist->musicbrainz_id ?: $this->findMusicBrainzId($artist);

        if ($musicbrainzId === null) {
            return ['musicbrainz_id' => null, 'genres' => []];
        }

        $data = $this->lookupData("artist/{$musicbrainzId}?inc=genres+tags&fmt=json");

        $genres = $this->names($data['genres'] ?? []);

        if ($genres === []) {
            $genres = $this->names($data['tags'] ?? []);
        }

        return [
            'musicbrainz_id' => $musicbrainzId,
            'genres' => array_slice($genres, 0, self::GENRE_LIMIT),
        ];
    }

    private function findMusicBrainzId(Artist $artist): ?string
    {
        if (! str_starts_with($artist->spotify_artist_id, 'local_')) {
            $resource = 'https://open.spotify.com/artist/'.$artist->spotify_artist_id;
            $data = $this->lookupData('url?resource='.rawurlencode($resource).'&inc=artist-rels&fmt=json');

            foreach ($data['relations'] ?? [] as $relation) {
                $id = $relation['artist']['id'] ?? null;

                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        }

        $data = $this->lookupData(
            'artist?query='.rawurlencode('artist:"'.$artist->name.'"').'&limit=5&fmt=json',
        );

        $candidates = array_values(array_filter(
            (array) ($data['artists'] ?? []),
            fn (mixed $candidate): bool => is_array($candidate) && filled($candidate['id'] ?? null),
        ));

        $wanted = mb_strtolower(trim($artist->name));

        foreach ($candidates as $candidate) {
            $name = is_string($candidate['name'] ?? null) ? mb_strtolower(trim($candidate['name'])) : null;

            if ($name === $wanted) {
                return (string) $candidate['id'];
            }
        }

        $id = $candidates[0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Fetch a MusicBrainz resource, treating a 404 as "not found" instead of
     * aborting the whole backfill.
     *
     * @return array<string, mixed>
     */
    private function lookupData(string $path): array
    {
        $attempts = 0;

        while (true) {
            try {
                return $this->get($path);
            } catch (RequestException $exception) {
                $status = $exception->response->status();

                if ($status === 404) {
                    return [];
                }

                if ($status === 503 && $attempts < 2) {
                    $attempts++;
                    $sleep = (int) config('services.musicbrainz.retry_sleep_seconds', 2);

                    if ($sleep > 0) {
                        sleep($sleep * $attempts);
                    }

                    continue;
                }

                throw $exception;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $base = rtrim((string) config('services.musicbrainz.base_url', 'https://musicbrainz.org/ws/2'), '/');

        $response = Http::withHeaders([
            'User-Agent' => (string) config('services.musicbrainz.user_agent'),
        ])->acceptJson()->timeout(20)->get("{$base}/{$path}");

        $this->pause();

        $response->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @return array<int, string>
     */
    private function names(array $values): array
    {
        $names = [];

        usort($values, fn (array $left, array $right): int => (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0));

        foreach ($values as $value) {
            $name = $value['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $names[mb_strtolower(trim($name))] = true;
        }

        return array_keys($names);
    }

    private function pause(): void
    {
        $delay = (int) config('services.musicbrainz.delay_ms', 1500);

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }
}
