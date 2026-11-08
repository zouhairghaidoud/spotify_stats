<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns listening data into private Spotify playlists.
 */
class PlaylistService
{
    /**
     * Maximum number of top tracks added to a generated playlist.
     */
    public const TOP_TRACKS = 30;

    /**
     * Maximum number of forgotten favorites listed and added.
     */
    public const FORGOTTEN_LIMIT = 30;

    /**
     * A track must not have been played for this many days to be forgotten.
     */
    public const FORGOTTEN_DAYS = 14;

    /**
     * A track must have at least this many plays to count as a favorite.
     */
    public const FORGOTTEN_MIN_PLAYS = 2;

    /**
     * Spotify time ranges mapped to playlist-friendly labels.
     *
     * @var array<string, string>
     */
    public const RANGES = [
        'short_term' => 'Last 4 Weeks',
        'medium_term' => 'Last 6 Months',
        'long_term' => 'Last Year',
    ];

    public function __construct(
        private readonly SpotifyService $spotify,
        private readonly SpotifyCacheService $cache,
    ) {}

    /**
     * Create a private playlist from the user's current top tracks.
     *
     * @return array{id: string, name: string, url: string, track_count: int}
     */
    public function createFromTopTracks(User $user, string $range): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : 'short_term';

        $items = $this->cache->topTracks($user, $range)['items'];

        $uris = collect($items)
            ->map(function (mixed $item): ?string {
                $uri = data_get($item, 'uri');

                if (filled($uri)) {
                    return (string) $uri;
                }

                $id = data_get($item, 'id');

                return filled($id) ? 'spotify:track:'.$id : null;
            })
            ->filter()
            ->unique()
            ->take(self::TOP_TRACKS)
            ->values()
            ->all();

        if ($uris === []) {
            throw new RuntimeException('Spotify did not return any top tracks yet.');
        }

        $label = self::RANGES[$range];

        return $this->create(
            $user,
            'My Top Tracks · '.$label,
            'My top tracks from the '.strtolower($label).' on Spotify Stats · '.now()->format('j M Y'),
            $uris,
        );
    }

    /**
     * Create a private playlist from tracks the user has stopped playing.
     *
     * @return array{id: string, name: string, url: string, track_count: int}
     */
    public function createFromForgottenFavorites(User $user): array
    {
        $tracks = $this->forgottenFavorites($user);

        if ($tracks === []) {
            throw new RuntimeException('There are no forgotten favorites to add yet.');
        }

        return $this->create(
            $user,
            'Forgotten Favorites · '.now()->format('M Y'),
            'Tracks I used to play a lot but have not heard in '.self::FORGOTTEN_DAYS.' days · Spotify Stats',
            array_column($tracks, 'uri'),
        );
    }

    /**
     * Tracks played at least twice, but not in the recent window.
     *
     * `MAX(played_at)` is compared in the HAVING clause so a track that was
     * played again recently never appears, even if it has many older plays.
     *
     * @return array<int, array{
     *     spotify_track_id: string,
     *     uri: string,
     *     name: string,
     *     artist_name: string|null,
     *     album_name: string|null,
     *     cover_url: string|null,
     *     duration_ms: int|null,
     *     plays: int,
     *     last_played_at: string
     * }>
     */
    public function forgottenFavorites(User $user, int $limit = self::FORGOTTEN_LIMIT): array
    {
        $cutoff = now()->subDays(self::FORGOTTEN_DAYS);

        $rows = $user->streams()
            ->selectRaw('spotify_track_id, COUNT(*) as plays, MAX(played_at) as last_played')
            ->groupBy('spotify_track_id')
            ->havingRaw('COUNT(*) >= ?', [self::FORGOTTEN_MIN_PLAYS])
            ->havingRaw('MAX(played_at) < ?', [$cutoff->format('Y-m-d H:i:s')])
            ->orderByDesc('plays')
            ->orderByDesc('last_played')
            ->limit($limit)
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $tracks = Track::query()
            ->whereIn('spotify_track_id', $rows->pluck('spotify_track_id')->all())
            ->get()
            ->keyBy('spotify_track_id');

        return $rows
            ->map(function (object $row) use ($tracks): array {
                $track = $tracks->get($row->spotify_track_id);

                return [
                    'spotify_track_id' => (string) $row->spotify_track_id,
                    'uri' => 'spotify:track:'.$row->spotify_track_id,
                    'name' => $track->name ?? 'Unknown track',
                    'artist_name' => $track->artist_name ?? null,
                    'album_name' => $track->album_name ?? null,
                    'cover_url' => $track->album_image_url ?? null,
                    'duration_ms' => $track->duration_ms ?? null,
                    'plays' => (int) $row->plays,
                    'last_played_at' => Carbon::parse($row->last_played)->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $uris
     * @return array{id: string, name: string, url: string, track_count: int}
     */
    private function create(User $user, string $name, string $description, array $uris): array
    {
        $playlist = $this->spotify->createPlaylist($user, $name, Str::limit($description, 280));

        $id = (string) ($playlist['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Spotify did not return a playlist id.');
        }

        $this->spotify->addTracksToPlaylist($user, $id, $uris);

        return [
            'id' => $id,
            'name' => (string) ($playlist['name'] ?? $name),
            'url' => (string) (data_get($playlist, 'external_urls.spotify') ?? 'https://open.spotify.com/playlist/'.$id),
            'track_count' => count(array_unique($uris)),
        ];
    }
}
