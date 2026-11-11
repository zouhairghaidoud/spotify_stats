<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds listening analytics from the persisted streams.
 *
 * All figures are computed from the database only, so opening the analytics
 * page never depends on the Spotify API being available.
 */
class ListeningAnalyticsService
{
    private const TOP_GENRE_LIMIT = 8;

    private const TREND_WEEKS = 12;

    private const TREND_ARTISTS = 6;

    private const MOVEMENT_ARTISTS = 5;

    private const COMPARISON_ARTISTS = 5;

    /**
     * Window used to decide whether an artist was recently discovered.
     */
    private const DISCOVERY_DAYS = 28;

    private const LEADERBOARD_LIMIT = 50;

    private const TOP_ALBUM_LIMIT = 12;

    /**
     * Share of the full track duration that counts as a completed play.
     */
    private const COMPLETION_RATIO = 0.9;

    /**
     * Share of the full track duration below which a play counts as a skip.
     */
    private const SKIP_RATIO = 0.5;

    private const SKIPPED_TRACK_LIMIT = 8;

    private const SKIPPED_TRACK_MIN_PLAYS = 2;

    /**
     * @param  array{genre?: string|null, artist_id?: string|null, context?: string|null}  $filters
     * @return array{
     *     timezone: string,
     *     overview: array{
     *         plays: int,
     *         minutes: int,
     *         unique_tracks: int,
     *         unique_artists: int,
     *         average_track_seconds: int|null,
     *         first_played_at: string|null,
     *         last_played_at: string|null
     *     },
     *     top_genres: array<int, array{genre: string, plays: int}>,
     *     top_albums: array<int, array{album_name: string, artist_name: string|null, cover_url: string|null, plays: int, minutes: int, unique_tracks_played: int}>,
     *     heatmap: array{counts: array<int, array<int, int>>, max: int},
     *     discovery: array{new_artists: int, plays: int, rate: float},
     *     streaks: array{current: int, longest: int},
     *     completion: array{plays: int, skipped: int, completed: int, rate: float, skipped_tracks: array<int, array{spotify_track_id: string, name: string, artist_name: string|null, plays: int, skips: int, skip_rate: float}>},
     *     tracks_leaderboard: array<int, array{spotify_track_id: string, name: string, artist_id: string|null, artist_name: string|null, album_image_url: string|null, plays: int, minutes: int}>
     * }
     */
    public function forUser(
        User $user,
        ?string $timezone = null,
        ?string $search = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        array $filters = [],
    ): array {
        $timezone = $this->timezone($timezone);
        $genreArtistIds = $this->genreArtistIds($filters['genre'] ?? null);

        $totals = $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(duration_ms), 0) as duration_ms')
            ->selectRaw('AVG(duration_ms) as average_duration_ms')
            ->selectRaw('COUNT(DISTINCT spotify_track_id) as tracks')
            ->selectRaw('COUNT(DISTINCT artist_id) as artists')
            ->toBase()
            ->first();

        $plays = (int) ($totals->plays ?? 0);
        $uniqueArtists = (int) ($totals->artists ?? 0);
        $durationMs = (int) ($totals->duration_ms ?? 0);
        $averageDurationMs = $totals->average_duration_ms ?? null;

        [$heatmap, $days] = $this->heatmapAndDays($user, $timezone, $from, $to, $filters, $genreArtistIds);

        return [
            'timezone' => $timezone,
            'overview' => [
                'plays' => $plays,
                'minutes' => intdiv($durationMs, 60000),
                'unique_tracks' => (int) ($totals->tracks ?? 0),
                'unique_artists' => $uniqueArtists,
                'average_track_seconds' => $averageDurationMs === null
                    ? null
                    : (int) round((float) $averageDurationMs / 1000),
                'first_played_at' => $this->timestamp($this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)->min('played_at')),
                'last_played_at' => $this->timestamp($this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)->max('played_at')),
            ],
            'top_genres' => $this->topGenres($user, $from, $to, $filters, $genreArtistIds),
            'top_albums' => $this->topAlbums($user, $from, $to, $filters, $genreArtistIds),
            'heatmap' => [
                'counts' => $heatmap,
                'max' => $this->heatmapMax($heatmap),
            ],
            'discovery' => $this->discovery($user, $from, $to, $filters, $genreArtistIds),
            'streaks' => $this->streaks($days, $timezone),
            'completion' => $this->completion($user, $from, $to, $filters, $genreArtistIds),
            'tracks_leaderboard' => $this->tracksLeaderboard($user, $search, $from, $to, $filters, $genreArtistIds),
        ];
    }

    /**
     * Apply the genre/artist/context filters to a stream query.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder<Stream>
     *
     * @param  TQuery  $query
     * @param  array{genre?: string|null, artist_id?: string|null, context?: string|null}  $filters
     * @param  array<int, string>|null  $genreArtistIds
     * @return TQuery
     */
    private function scopeFilters($query, array $filters, ?array $genreArtistIds)
    {
        return $query
            ->when($genreArtistIds !== null, fn ($query) => $query->whereIn('streams.artist_id', $genreArtistIds === [] ? ['__no_genre__'] : $genreArtistIds))
            ->when(filled($filters['artist_id'] ?? null), fn ($query) => $query->where('streams.artist_id', $filters['artist_id']))
            ->when(filled($filters['context'] ?? null), fn ($query) => $query->where('streams.context', $filters['context']));
    }

    /**
     * Resolve the artist ids that carry a genre, or null when no genre filter is active.
     *
     * @return array<int, string>|null
     */
    private function genreArtistIds(?string $genre): ?array
    {
        if ($genre === null || trim($genre) === '') {
            return null;
        }

        $wanted = mb_strtolower(trim($genre));

        return Artist::query()
            ->whereNotNull('genres')
            ->get(['spotify_artist_id', 'genres'])
            ->filter(fn (Artist $artist): bool => in_array($wanted, array_map('mb_strtolower', $artist->genres ?? []), true))
            ->pluck('spotify_artist_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Options available for the analytics filters.
     *
     * @return array{
     *     genres: array<int, string>,
     *     artists: array<int, array{artist_id: string, name: string, plays: int}>,
     *     contexts: array<int, string>
     * }
     */
    public function filterOptions(User $user): array
    {
        $artistRows = $user->streams()
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->orderByDesc('plays')
            ->toBase()
            ->get();

        $artists = Artist::query()
            ->whereIn('spotify_artist_id', $artistRows->pluck('artist_id')->all())
            ->get(['spotify_artist_id', 'name', 'genres'])
            ->keyBy('spotify_artist_id');

        $genres = [];

        foreach ($artists as $artist) {
            foreach ($artist->genres ?? [] as $genre) {
                if (is_string($genre) && $genre !== '') {
                    $genres[$genre] = true;
                }
            }
        }

        $genreList = array_keys($genres);
        sort($genreList);

        $artistList = $artistRows
            ->take(80)
            ->map(fn (object $row): array => [
                'artist_id' => (string) $row->artist_id,
                'name' => $artists->get($row->artist_id)?->name ?? 'Unknown artist',
                'plays' => (int) $row->plays,
            ])
            ->values()
            ->all();

        $contexts = $user->streams()
            ->whereNotNull('context')
            ->where('context', '!=', '')
            ->distinct()
            ->orderBy('context')
            ->pluck('context')
            ->map(fn (mixed $context): string => (string) $context)
            ->values()
            ->all();

        return [
            'genres' => $genreList,
            'artists' => $artistList,
            'contexts' => $contexts,
        ];
    }

    /**
     * Apply an inclusive time window to a stream query.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder<Stream>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function windowed($query, ?Carbon $from, ?Carbon $to)
    {
        return $query
            ->when($from !== null, fn ($query) => $query->where('played_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('played_at', '<', $to));
    }

    /**
     * Exact play counts per song, optionally filtered by title or artist.
     *
     * @return array<int, array{spotify_track_id: string, name: string, artist_id: string|null, artist_name: string|null, album_image_url: string|null, plays: int, minutes: int}>
     */
    private function tracksLeaderboard(User $user, ?string $search, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        $query = $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->leftJoin('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
            ->leftJoin('artists', 'artists.spotify_artist_id', '=', 'streams.artist_id')
            ->selectRaw('streams.spotify_track_id as spotify_track_id')
            ->selectRaw("COALESCE(tracks.name, 'Unknown track') as name")
            ->selectRaw('COALESCE(tracks.artist_id, streams.artist_id) as artist_id')
            ->selectRaw('COALESCE(tracks.artist_name, artists.name) as artist_name')
            ->selectRaw('tracks.album_image_url as album_image_url')
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('ROUND(COALESCE(SUM(streams.duration_ms), 0) / 60000.0) as minutes')
            ->groupBy('streams.spotify_track_id', 'tracks.name', 'tracks.artist_id', 'streams.artist_id', 'tracks.artist_name', 'artists.name', 'tracks.album_image_url')
            ->orderByDesc('plays')
            ->orderBy('streams.spotify_track_id')
            ->limit(self::LEADERBOARD_LIMIT);

        if (filled($search)) {
            $term = '%'.$search.'%';

            $query->where(function ($query) use ($term): void {
                $query->where('tracks.name', 'like', $term)
                    ->orWhere('tracks.artist_name', 'like', $term)
                    ->orWhere('artists.name', 'like', $term);
            });
        }

        return $query
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'spotify_track_id' => (string) $row->spotify_track_id,
                'name' => (string) $row->name,
                'artist_id' => filled($row->artist_id) ? (string) $row->artist_id : null,
                'artist_name' => filled($row->artist_name) ? (string) $row->artist_name : null,
                'album_image_url' => filled($row->album_image_url) ? (string) $row->album_image_url : null,
                'plays' => (int) $row->plays,
                'minutes' => (int) $row->minutes,
            ])
            ->all();
    }

    /**
     * Most played albums from stored history.
     *
     * @return array<int, array{album_name: string, artist_name: string|null, cover_url: string|null, plays: int, minutes: int, unique_tracks_played: int}>
     */
    private function topAlbums(User $user, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        return $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->join('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
            ->leftJoin('artists', 'artists.spotify_artist_id', '=', 'tracks.artist_id')
            ->whereNotNull('tracks.album_name')
            ->where('tracks.album_name', '!=', '')
            ->selectRaw('tracks.album_name as album_name')
            ->selectRaw('COALESCE(tracks.artist_name, artists.name) as artist_name')
            ->selectRaw('MAX(tracks.album_image_url) as cover_url')
            ->selectRaw('COUNT(streams.id) as plays')
            ->selectRaw('COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT streams.spotify_track_id) as unique_tracks_played')
            ->groupBy('tracks.album_name', 'tracks.artist_name', 'artists.name')
            ->orderByDesc('plays')
            ->orderByDesc('duration_ms')
            ->orderBy('tracks.album_name')
            ->limit(self::TOP_ALBUM_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'album_name' => (string) $row->album_name,
                'artist_name' => filled($row->artist_name) ? (string) $row->artist_name : null,
                'cover_url' => filled($row->cover_url) ? (string) $row->cover_url : null,
                'plays' => (int) $row->plays,
                'minutes' => (int) round((int) $row->duration_ms / 60000),
                'unique_tracks_played' => (int) $row->unique_tracks_played,
            ])
            ->all();
    }

    /**
     * Share of recent plays that introduced an artist for the first time.
     *
     * Only artists whose very first stored play happened inside the discovery
     * window count, so a long-time favorite is never reported as new again.
     *
     * @return array{new_artists: int, plays: int, rate: float}
     */
    private function discovery(User $user, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        $windowStart = $from ?? now()->subDays(self::DISCOVERY_DAYS);

        $plays = (int) $this->scopeFilters($this->windowed($user->streams(), $windowStart, $to), $filters, $genreArtistIds)->count();

        $newArtists = $this->scopeFilters($user->streams()->whereNotNull('streams.artist_id'), $filters, $genreArtistIds)
            ->selectRaw('streams.artist_id, MIN(streams.played_at) as first_played')
            ->groupBy('streams.artist_id')
            ->havingRaw('MIN(streams.played_at) >= ?', [$windowStart->copy()->utc()->format('Y-m-d H:i:s')])
            ->when($to !== null, fn ($query) => $query->havingRaw('MIN(streams.played_at) < ?', [$to->copy()->utc()->format('Y-m-d H:i:s')]))
            ->toBase()
            ->get()
            ->count();

        return [
            'new_artists' => $newArtists,
            'plays' => $plays,
            'rate' => $plays > 0 ? round($newArtists / $plays, 4) : 0.0,
        ];
    }

    /**
     * Completion and skip rate for imported plays.
     *
     * Only plays imported from the streaming history are considered because
     * their duration_ms stores the seconds actually played. Synced plays store
     * the full track duration, so they can never be measured for completion.
     *
     * @return array{plays: int, skipped: int, completed: int, rate: float, skipped_tracks: array<int, array{spotify_track_id: string, name: string, artist_name: string|null, plays: int, skips: int, skip_rate: float}>}
     */
    private function completion(User $user, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        $completed = (int) round(self::COMPLETION_RATIO * 10);
        $skipDivisor = (int) round(1 / self::SKIP_RATIO);

        $evaluable = fn () => $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->where('streams.context', HistoryImportService::CONTEXT)
            ->join('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
            ->whereNotNull('tracks.duration_ms')
            ->where('tracks.duration_ms', '>', 0);

        $totals = $evaluable()
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw("SUM(CASE WHEN streams.duration_ms * 10 >= tracks.duration_ms * {$completed} THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN streams.duration_ms * {$skipDivisor} < tracks.duration_ms THEN 1 ELSE 0 END) as skipped")
            ->toBase()
            ->first();

        $plays = (int) ($totals->plays ?? 0);
        $completedPlays = (int) ($totals->completed ?? 0);
        $skippedPlays = (int) ($totals->skipped ?? 0);

        $skippedTracks = $evaluable()
            ->leftJoin('artists', 'artists.spotify_artist_id', '=', 'streams.artist_id')
            ->selectRaw('streams.spotify_track_id as spotify_track_id')
            ->selectRaw("COALESCE(MAX(tracks.name), 'Unknown track') as name")
            ->selectRaw('MAX(COALESCE(tracks.artist_name, artists.name)) as artist_name')
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw("SUM(CASE WHEN streams.duration_ms * {$skipDivisor} < tracks.duration_ms THEN 1 ELSE 0 END) as skips")
            ->groupBy('streams.spotify_track_id')
            ->havingRaw('COUNT(*) >= ?', [self::SKIPPED_TRACK_MIN_PLAYS])
            ->havingRaw("SUM(CASE WHEN streams.duration_ms * {$skipDivisor} < tracks.duration_ms THEN 1 ELSE 0 END) > 0")
            ->orderByDesc('skips')
            ->orderBy('streams.spotify_track_id')
            ->limit(self::SKIPPED_TRACK_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'spotify_track_id' => (string) $row->spotify_track_id,
                'name' => (string) $row->name,
                'artist_name' => filled($row->artist_name) ? (string) $row->artist_name : null,
                'plays' => (int) $row->plays,
                'skips' => (int) $row->skips,
                'skip_rate' => round((int) $row->skips / max(1, (int) $row->plays), 4),
            ])
            ->all();

        return [
            'plays' => $plays,
            'skipped' => $skippedPlays,
            'completed' => $completedPlays,
            'rate' => $plays > 0 ? round($completedPlays / $plays, 4) : 0.0,
            'skipped_tracks' => $skippedTracks,
        ];
    }

    /**
     * Weekly play counts for the user's most played artists, plus how their
     * ranking moved over the last week compared with the week before.
     *
     * @return array{
     *     weeks: array<int, string>,
     *     series: array<int, array{artist_id: string, name: string, plays: array<int, int>}>,
     *     movement: array<int, array{artist_id: string, name: string, plays: int, previous_plays: int, rank: int, change: int|null}>
     * }
     */
    public function artistTrends(User $user, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $now = Carbon::now($timezone);
        $firstWeek = $now->copy()->startOfWeek()->subWeeks(self::TREND_WEEKS - 1);

        $weeks = [];

        for ($index = 0; $index < self::TREND_WEEKS; $index++) {
            $weeks[] = $firstWeek->copy()->addWeeks($index)->toDateString();
        }

        $weekIndex = array_flip($weeks);
        $counts = [];
        $totals = [];

        $user->streams()
            ->where('played_at', '>=', $firstWeek->copy()->setTimezone('UTC'))
            ->whereNotNull('artist_id')
            ->orderBy('played_at')
            ->select(['played_at', 'artist_id'])
            ->cursor()
            ->each(function (Stream $stream) use (&$counts, &$totals, $weekIndex, $timezone): void {
                $week = $stream->played_at->copy()->setTimezone($timezone)->startOfWeek()->toDateString();

                if (! isset($weekIndex[$week])) {
                    return;
                }

                $artistId = (string) $stream->artist_id;
                $index = $weekIndex[$week];

                $counts[$artistId][$index] = ($counts[$artistId][$index] ?? 0) + 1;
                $totals[$artistId] = ($totals[$artistId] ?? 0) + 1;
            });

        arsort($totals);

        $topArtists = array_slice(array_keys($totals), 0, self::TREND_ARTISTS);
        $names = $this->artistNames($topArtists);
        $series = [];

        foreach ($topArtists as $artistId) {
            $plays = [];

            for ($index = 0; $index < self::TREND_WEEKS; $index++) {
                $plays[] = $counts[$artistId][$index] ?? 0;
            }

            $series[] = [
                'artist_id' => (string) $artistId,
                'name' => $names[$artistId] ?? 'Unknown artist',
                'plays' => $plays,
            ];
        }

        return [
            'weeks' => $weeks,
            'series' => $series,
            'movement' => $this->rankMovement($user),
        ];
    }

    /**
     * Compare the top artists and totals of the last four weeks with the last year.
     *
     * @return array{
     *     recent: array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>},
     *     year: array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>}
     * }
     */
    public function artistComparison(User $user): array
    {
        $now = now();

        return [
            'recent' => $this->windowSummary($user, $now->copy()->subDays(28)),
            'year' => $this->windowSummary($user, $now->copy()->subDays(365)),
        ];
    }

    /**
     * @return array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>}
     */
    private function windowSummary(User $user, Carbon $from): array
    {
        $totals = $user->streams()
            ->where('played_at', '>=', $from)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(duration_ms), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT artist_id) as artists')
            ->toBase()
            ->first();

        $plays = (int) ($totals->plays ?? 0);
        $durationMs = (int) ($totals->duration_ms ?? 0);
        $uniqueArtists = (int) ($totals->artists ?? 0);

        $rows = $user->streams()
            ->where('played_at', '>=', $from)
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->toBase()
            ->get()
            ->sortByDesc('plays')
            ->take(self::COMPARISON_ARTISTS)
            ->values();

        $names = $this->artistNames($rows->pluck('artist_id')->all());

        return [
            'plays' => $plays,
            'minutes' => intdiv($durationMs, 60000),
            'unique_artists' => $uniqueArtists,
            'top_artists' => $rows
                ->map(fn (object $row): array => [
                    'artist_id' => (string) $row->artist_id,
                    'name' => $names[$row->artist_id] ?? 'Unknown artist',
                    'plays' => (int) $row->plays,
                    'share' => $plays > 0 ? round((int) $row->plays / $plays, 4) : 0.0,
                ])
                ->all(),
        ];
    }

    /**
     * Rank the top artists of the last seven days and mark how each moved
     * compared with the seven days before.
     *
     * @return array<int, array{artist_id: string, name: string, plays: int, previous_plays: int, rank: int, change: int|null}>
     */
    private function rankMovement(User $user): array
    {
        $now = now();

        $current = $this->artistRankings($user, $now->copy()->subDays(7), $now);
        $previous = $this->artistRankings($user, $now->copy()->subDays(14), $now->copy()->subDays(7));

        $top = array_slice($current, 0, self::MOVEMENT_ARTISTS, preserve_keys: true);
        $names = $this->artistNames(array_keys($top));
        $movement = [];

        foreach ($top as $artistId => $row) {
            $previousRank = $previous[$artistId]['rank'] ?? null;

            $movement[] = [
                'artist_id' => (string) $artistId,
                'name' => $names[$artistId] ?? 'Unknown artist',
                'plays' => $row['plays'],
                'previous_plays' => $previous[$artistId]['plays'] ?? 0,
                'rank' => $row['rank'],
                'change' => $previousRank === null ? null : $previousRank - $row['rank'],
            ];
        }

        return $movement;
    }

    /**
     * @return array<string, array{plays: int, rank: int}>
     */
    private function artistRankings(User $user, Carbon $from, Carbon $to): array
    {
        $rows = $user->streams()
            ->where('played_at', '>=', $from)
            ->where('played_at', '<', $to)
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->toBase()
            ->get()
            ->sortBy([['plays', 'desc'], ['artist_id', 'asc']])
            ->values();

        $rankings = [];

        foreach ($rows as $index => $row) {
            $rankings[(string) $row->artist_id] = [
                'plays' => (int) $row->plays,
                'rank' => $index + 1,
            ];
        }

        return $rankings;
    }

    /**
     * @param  array<int, string>  $artistIds
     * @return array<string, string>
     */
    private function artistNames(array $artistIds): array
    {
        if ($artistIds === []) {
            return [];
        }

        return Artist::query()
            ->whereIn('spotify_artist_id', array_values($artistIds))
            ->pluck('name', 'spotify_artist_id')
            ->all();
    }

    /**
     * Count plays per local weekday (Monday first) and hour of day.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<string, true>}
     */
    private function heatmapAndDays(User $user, string $timezone, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $days = [];

        $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->orderBy('played_at')
            ->select(['played_at'])
            ->cursor()
            ->each(function (Stream $stream) use (&$heatmap, &$days, $timezone): void {
                $playedAt = $stream->played_at->copy()->setTimezone($timezone);

                $heatmap[$playedAt->isoWeekday() - 1][$playedAt->hour]++;
                $days[$playedAt->toDateString()] = true;
            });

        return [$heatmap, $days];
    }

    /**
     * Weight artist genres by how often the artist was played.
     *
     * @return array<int, array{genre: string, plays: int}>
     */
    private function topGenres(User $user, ?Carbon $from = null, ?Carbon $to = null, array $filters = [], ?array $genreArtistIds = null): array
    {
        $artistPlays = $this->scopeFilters($this->windowed($user->streams(), $from, $to), $filters, $genreArtistIds)
            ->whereNotNull('streams.artist_id')
            ->selectRaw('streams.artist_id as artist_id, COUNT(*) as plays')
            ->groupBy('streams.artist_id')
            ->toBase()
            ->get()
            ->pluck('plays', 'artist_id');

        if ($artistPlays->isEmpty()) {
            return [];
        }

        $genres = Artist::query()
            ->whereIn('spotify_artist_id', $artistPlays->keys()->all())
            ->get(['spotify_artist_id', 'genres'])
            ->mapWithKeys(fn (Artist $artist): array => [
                (string) $artist->spotify_artist_id => $artist->genres ?? [],
            ]);

        $counts = [];

        foreach ($artistPlays as $artistId => $playCount) {
            foreach (array_unique($genres->get((string) $artistId, [])) as $genre) {
                $counts[$genre] = ($counts[$genre] ?? 0) + (int) $playCount;
            }
        }

        arsort($counts);

        $top = [];

        foreach (array_slice($counts, 0, self::TOP_GENRE_LIMIT, preserve_keys: true) as $genre => $playCount) {
            $top[] = ['genre' => (string) $genre, 'plays' => $playCount];
        }

        return $top;
    }

    /**
     * @param  array<string, true>  $days
     * @return array{current: int, longest: int}
     */
    private function streaks(array $days, string $timezone): array
    {
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach (array_keys($days) as $day) {
            $date = Carbon::parse($day, $timezone)->startOfDay();

            $run = $previous !== null && $previous->copy()->addDay()->isSameDay($date)
                ? $run + 1
                : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        $today = Carbon::now($timezone)->startOfDay();
        $cursor = isset($days[$today->toDateString()])
            ? $today
            : (isset($days[$today->copy()->subDay()->toDateString()]) ? $today->copy()->subDay() : null);

        $current = 0;

        while ($cursor !== null && isset($days[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->copy()->subDay();
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * @param  array<int, array<int, int>>  $heatmap
     */
    private function heatmapMax(array $heatmap): int
    {
        $max = 0;

        foreach ($heatmap as $row) {
            $max = max($max, ...$row);
        }

        return $max;
    }

    private function timezone(?string $timezone): string
    {
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    private function timestamp(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toIso8601String() : null;
    }
}
