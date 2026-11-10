<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Imports Spotify "Extended streaming history" and "Account data" exports.
 *
 * The importer never calls the Spotify API. It writes the same `streams`,
 * `tracks`, and (for local ids) `artists` rows that a normal sync produces,
 * so analytics and recaps work for users who never connect OAuth.
 */
class HistoryImportService
{
    /**
     * Plays shorter than this are considered skips and ignored.
     */
    public const MIN_PLAY_MS = 30000;

    /**
     * Marker stored in `streams.context` for imported rows.
     */
    public const CONTEXT = 'json_import';

    /**
     * Rows written per insert statement.
     */
    private const CHUNK_SIZE = 1000;

    /**
     * Import a JSON or ZIP export and return counters for the UI.
     *
     * @param  callable(int, int, int): void|null  $onProgress
     * @return array{imported: int, skipped: int, files: int, tracks: int}
     */
    public function import(User $user, UploadedFile $file, ?callable $onProgress = null): array
    {
        $imported = 0;
        $skipped = 0;
        $files = 0;
        $pendingRows = [];
        $trackMeta = [];
        $artistMeta = [];
        $localTrackArtists = [];
        $now = now();

        foreach ($this->jsonPayloads($file) as $payload) {
            $files++;

            foreach ($this->entriesFromJson($payload) as $entry) {
                $row = $this->mapEntry($entry, $user, $now);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $trackId = $row['spotify_track_id'];
                $pendingRows[$trackId.'|'.$row['played_at']] = $row;

                $trackMeta[$trackId] ??= [
                    'spotify_track_id' => $trackId,
                    'name' => $row['track_name'],
                    'artist_name' => $row['artist_name'],
                    'album_name' => $row['album_name'],
                    'duration_ms' => $row['duration_ms'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (str_starts_with($trackId, 'local_') && filled($row['artist_id'])) {
                    $localTrackArtists[$trackId] = $row['artist_id'];
                    $artistMeta[$row['artist_id']] = [
                        'spotify_artist_id' => $row['artist_id'],
                        'name' => (string) $row['artist_name'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (count($pendingRows) >= self::CHUNK_SIZE) {
                    $imported += $this->insertStreams($pendingRows);
                    $pendingRows = [];

                    if ($onProgress !== null) {
                        $onProgress($imported, $skipped, $files);
                    }
                }
            }

            if ($onProgress !== null) {
                $onProgress($imported, $skipped, $files);
            }
        }

        if ($pendingRows !== []) {
            $imported += $this->insertStreams($pendingRows);
        }

        $this->upsertTracks(array_values($trackMeta));
        $this->upsertArtists(array_values($artistMeta));
        $this->assignLocalTrackArtists($localTrackArtists, $now);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'files' => $files,
            'tracks' => count($trackMeta),
        ];
    }

    /**
     * Cheaply confirm that an upload looks like a readable Spotify export.
     *
     * Runs before the file is queued so obviously broken files still return a
     * normal validation error on the current request.
     */
    public function assertReadable(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'zip') {
            $zip = new ZipArchive;

            if ($zip->open($file->getRealPath()) !== true) {
                throw new RuntimeException('The uploaded ZIP file could not be opened.');
            }

            $hasJson = false;

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);

                    if (is_string($name) && str_ends_with(strtolower($name), '.json')) {
                        $hasJson = true;

                        break;
                    }
                }
            } finally {
                $zip->close();
            }

            if (! $hasJson) {
                throw new InvalidArgumentException('The uploaded ZIP does not contain any JSON files.');
            }

            return;
        }

        $contents = @file_get_contents($file->getRealPath());

        if (! is_string($contents) || ! is_array(json_decode($contents, true))) {
            throw new InvalidArgumentException('The uploaded file is not valid JSON.');
        }
    }

    /**
     * Recent imports for the status panel.
     *
     * @return array<int, array{id: int, status: string, original_name: string, imported: int, skipped: int, files: int, tracks: int, error: string|null, created_at: string|null, started_at: string|null, finished_at: string|null}>
     */
    public function recent(User $user, int $limit = 5): array
    {
        return $user->historyImports()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($import): array => [
                'id' => $import->id,
                'status' => $import->status,
                'original_name' => $import->original_name,
                'imported' => $import->imported,
                'skipped' => $import->skipped,
                'files' => $import->files,
                'tracks' => $import->tracks,
                'error' => $import->error,
                'created_at' => $import->created_at?->toIso8601String(),
                'started_at' => $import->started_at?->toIso8601String(),
                'finished_at' => $import->finished_at?->toIso8601String(),
            ])
            ->all();
    }

    public function hasActiveImport(User $user): bool
    {
        return $user->historyImports()
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * @return array{imported_plays: int, total_plays: int, last_imported_at: string|null}
     */
    public function summary(User $user): array
    {
        $lastImportedAt = $user->streams()
            ->where('context', self::CONTEXT)
            ->max('created_at');

        return [
            'imported_plays' => (int) $user->streams()->where('context', self::CONTEXT)->count(),
            'total_plays' => (int) $user->streams()->count(),
            'last_imported_at' => filled($lastImportedAt)
                ? Carbon::parse($lastImportedAt)->toIso8601String()
                : null,
        ];
    }

    /**
     * Map one export entry to the exact `streams` schema.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function mapEntry(array $entry, User $user, Carbon $now): ?array
    {
        $msPlayed = $entry['ms_played'] ?? $entry['msPlayed'] ?? null;

        if (! is_numeric($msPlayed) || (int) $msPlayed < self::MIN_PLAY_MS) {
            return null;
        }

        $trackName = $this->text($entry['master_metadata_track_name'] ?? $entry['trackName'] ?? null);

        if ($trackName === null) {
            return null;
        }

        $artistName = $this->text($entry['master_metadata_album_artist_name'] ?? $entry['artistName'] ?? null);
        $albumName = $this->text($entry['master_metadata_album_album_name'] ?? $entry['albumName'] ?? null);
        $uri = $this->text($entry['spotify_track_uri'] ?? $entry['spotifyTrackUri'] ?? null);
        $timestamp = $entry['ts'] ?? $entry['endTime'] ?? null;

        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            $playedAt = Carbon::parse($timestamp, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }

        $trackId = $this->trackId($uri, $artistName, $trackName);
        $isLocal = str_starts_with($trackId, 'local_');

        return [
            'user_id' => $user->id,
            'spotify_track_id' => $trackId,
            'duration_ms' => (int) $msPlayed,
            'artist_id' => $isLocal ? $this->localArtistId($artistName) : null,
            'played_at' => $playedAt->format('Y-m-d H:i:s.v'),
            'context' => self::CONTEXT,
            'created_at' => $now,
            'updated_at' => $now,
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function insertStreams(array $rows): int
    {
        $payload = array_map(
            fn (array $row): array => [
                'user_id' => $row['user_id'],
                'spotify_track_id' => $row['spotify_track_id'],
                'duration_ms' => $row['duration_ms'],
                'artist_id' => $row['artist_id'],
                'played_at' => $row['played_at'],
                'context' => $row['context'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            array_values($rows),
        );

        return (int) Stream::query()->insertOrIgnore($payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertTracks(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Track::query()->upsert(
                $chunk,
                ['spotify_track_id'],
                ['name', 'artist_name', 'album_name', 'duration_ms', 'updated_at'],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertArtists(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Artist::query()->upsert(
                $chunk,
                ['spotify_artist_id'],
                ['name', 'updated_at'],
            );
        }
    }

    /**
     * Give tracks from the standard export (which have no Spotify id) a local
     * artist id so artist trends and comparisons work before enrichment runs.
     *
     * @param  array<string, string>  $trackArtists
     */
    private function assignLocalTrackArtists(array $trackArtists, Carbon $now): void
    {
        $byArtist = [];

        foreach ($trackArtists as $trackId => $artistId) {
            $byArtist[$artistId][] = $trackId;
        }

        foreach ($byArtist as $artistId => $trackIds) {
            foreach (array_chunk($trackIds, 500) as $chunk) {
                Track::query()
                    ->whereIn('spotify_track_id', $chunk)
                    ->update(['artist_id' => $artistId, 'updated_at' => $now]);
            }
        }
    }

    /**
     * @return iterable<int, string>
     */
    private function jsonPayloads(UploadedFile $file): iterable
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'zip') {
            yield from $this->zipPayloads($file);
        } else {
            yield (string) file_get_contents($file->getRealPath());
        }
    }

    /**
     * @return iterable<int, string>
     */
    private function zipPayloads(UploadedFile $file): iterable
    {
        $path = $file->getRealPath();
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded ZIP file could not be opened.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);

                if (! is_string($name) || ! str_ends_with(strtolower($name), '.json')) {
                    continue;
                }

                if (is_array($stat) && ($stat['size'] ?? 0) > 100 * 1024 * 1024) {
                    continue;
                }

                $contents = $zip->getFromIndex($index);

                if (is_string($contents) && $contents !== '') {
                    yield $contents;
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entriesFromJson(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The uploaded file is not valid JSON.');
        }

        if (isset($decoded['items']) && is_array($decoded['items'])) {
            $decoded = $decoded['items'];
        } elseif (! array_is_list($decoded)) {
            $decoded = [$decoded];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function trackId(?string $uri, ?string $artistName, string $trackName): string
    {
        if ($uri !== null && str_starts_with($uri, 'spotify:track:')) {
            $id = trim(substr($uri, strlen('spotify:track:')));

            if ($id !== '') {
                return substr($id, 0, 32);
            }
        }

        $fingerprint = strtolower(($artistName ?? '').'|'.$trackName);

        return 'local_'.substr(md5($fingerprint), 0, 22);
    }

    private function localArtistId(?string $artistName): ?string
    {
        if ($artistName === null) {
            return null;
        }

        return 'local_artist_'.substr(md5(strtolower(trim($artistName))), 0, 18);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
