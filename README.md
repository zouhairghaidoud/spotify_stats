# Spotify Stats

Personal Spotify listening analytics. Connect Spotify once or upload your Spotify data export, and the
app turns the stored plays into analytics that need no Spotify connection to render.

- **Dashboard** — live now playing (polled every five seconds), top tracks, top artists (4 weeks / 6 months / 1 year), top albums, recently played.
- **Analytics** — listening time, play counts, unique tracks and artists, average track length, top
  genres, top albums, a weekday × hour heatmap, discovery rate, listening streaks, and
  completion/skip rates from imported plays.
- **Filters** — narrow analytics by genre, artist, and playback context (playlist, album, radio, import, …).
- **Charts** — a 12-week trend line per top artist with weekly rank movement, and a side-by-side
  comparison of the last 4 weeks against the last year, drawn with Chart.js.
- **Recap** — a wrapped-style `/recap` story with your top five tracks, top artist, minutes, genres,
  and peak listening hour, exportable as a PNG from the browser. Optional **year-vs-year comparison**.
- **Detail pages** — per-artist and per-album play history with monthly timelines, top tracks, and albums.
- **Genres** — MusicBrainz lookup with a Last.fm fallback, plus manual per-artist genre overrides.
- **History import** — upload a Spotify *Extended streaming history* JSON or ZIP in the background, then
  delete imported plays without touching synced history.
- **Bring your own Spotify app** — users can save their own client id/secret from the profile page;
  the environment app remains the fallback.
- **Sharing** — an optional tokenized `/u/{token}` page with a server-rendered Open Graph card.
- **Playlists** — create a private playlist from your current top tracks or from forgotten favorites you
  played repeatedly but have not touched in two weeks.
- **Stored history** — a `streams` table of every play collected from `/me/player/recently-played` or
  imported from a data export.
- **Resilience** — per-user, per-range caching with stale-data fallback when Spotify is slow, down,
  or rate limiting.

---

## Contents

1. [Stack](#stack)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Spotify app setup](#spotify-app-setup)
5. [Environment variables](#environment-variables)
6. [Running the app](#running-the-app)
7. [How it works](#how-it-works)
8. [History import](#history-import)
9. [Data model](#data-model)
10. [Routes](#routes)
11. [Services reference](#services-reference)
12. [Commands and schedule](#commands-and-schedule)
13. [Analytics definitions](#analytics-definitions)
14. [Recap and sharing](#recap-and-sharing)
15. [Playlist tools](#playlist-tools)
16. [Timezones](#timezones)
17. [Testing](#testing)
18. [Known limitations](#known-limitations)
19. [Project structure](#project-structure)

---

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3, Laravel 13 |
| Frontend | Inertia.js 2 + Vue 3, Tailwind CSS |
| Charts | Chart.js 4 through `resources/js/Components/Chart.vue` |
| Build | `vp` (Vite+) via `npm run build` / `npm run dev` |
| Database | MySQL in local development; SQLite works too |
| Cache | Database cache store (`CACHE_STORE=database`) |
| Tests | Pest |

Spotify access uses the OAuth 2.0 authorization-code flow with these scopes:

```
user-read-email
user-top-read
user-read-recently-played
user-read-currently-playing
playlist-modify-private
```

Reading is all the dashboard needs; the playlist scope is used only when you press one of the create
buttons. Tokens are stored encrypted and can be revoked from the Spotify account page at any time.

---

## Requirements

- PHP 8.3 with the extensions your database needs (`pdo_mysql`), plus `pdo_sqlite` if you want to run
  the test suite (`phpunit.xml` uses an in-memory SQLite database).
- Composer 2
- Node.js 20+ and npm
- A Spotify developer app (see below)
- A queue worker for background imports and genre backfills (the default `database` queue works out
  of the box; run `php artisan queue:work`)

---

## Installation

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Configure the database, Spotify, and optional MusicBrainz/Last.fm values in .env, then:
php artisan migrate
npm run build

# Background imports and genre backfills use the database queue:
php artisan queue:work
```

Serve the application:

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # front-end dev server with hot reload
# or run both Laravel and Vite together:
composer run dev
```

`composer run dev` delegates to `php artisan dev`, which starts the registered development
processes (HTTP server, queue listener, Vite). If the front end does not pick up changes, rebuild
with `npm run build` or restart `npm run dev`.

---

## Spotify app setup

1. Open the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard) and create an app.
2. Add a **Redirect URI** that matches `SPOTIFY_REDIRECT_URI` exactly, for example
   `http://127.0.0.1:8000/auth/spotify/callback`. `localhost` and `127.0.0.1` are different URIs;
   use the one configured in `.env`.
3. While the app is in development mode, add the Spotify account(s) you will sign in with under
   **User Management**.
4. Copy the **Client ID** and **Client Secret** into `.env`.

No additional scopes are requested by the app; Spotify will show exactly the scopes listed above.

Users can also save their own Spotify app credentials from **Profile → Spotify app**. A custom client
id/secret is used for that user's authorization and token refresh; changing or removing it clears the
user's tokens because tokens issued by one Spotify app cannot be refreshed by another. Guests and
users without custom credentials use the environment app.

---

## Environment variables

The application reads these values (see `config/services.php` and `.env.example`):

| Variable | Purpose |
| --- | --- |
| `APP_NAME` | Shown in page titles and the UI |
| `APP_URL` | Base URL Laravel generates links from |
| `DB_CONNECTION` | `mysql` (current local setup) or `sqlite` |
| `CACHE_STORE` | Cache driver. `database` works out of the box |
| `SESSION_DRIVER` | `database` by default |
| `QUEUE_CONNECTION` | `database` by default; `sync` runs imports inline |
| `SPOTIFY_CLIENT_ID` | Spotify app client id |
| `SPOTIFY_CLIENT_SECRET` | Spotify app client secret |
| `SPOTIFY_REDIRECT_URI` | Must exactly match the redirect URI in the Spotify dashboard |
| `MUSICBRAINZ_USER_AGENT` | User agent sent to MusicBrainz (recommended when using genre lookups) |
| `MUSICBRAINZ_DELAY_MS` | Delay between MusicBrainz calls, default `1500` |
| `MUSICBRAINZ_RETRY_SLEEP_SECONDS` | Backoff for MusicBrainz `503` responses, default `2` |
| `LASTFM_API_KEY` | Optional Last.fm API key; enables the genre fallback when MusicBrainz has no tags |

Laravel's cache, sessions, and queue tables are created by the standard migrations, so the database
drivers work without extra setup. MusicBrainz needs no key, but Last.fm does; without
`LASTFM_API_KEY` the fallback is skipped and only MusicBrainz and manual overrides apply.

---

## Running the app

### Web server and assets

`php artisan serve` plus `npm run dev` (or `composer run dev`) is enough for day-to-day work.

### Scheduler

Listening history is collected by the `spotify:sync` command, which is scheduled every fifteen
minutes in `routes/console.php` with `withoutOverlapping()`.

In development:

```bash
php artisan schedule:work
```

In production add a single cron entry:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Nothing is collected while the scheduler is not running. As a safety net the dashboard also triggers
a sync when it is visited and the stored history is stale (see below).

---

## How it works

### 1. Spotify sign-in and account linking

`GET /auth/spotify/redirect` (`SpotifyController@redirect`) stores a random `state` in the session and
redirects to Spotify.

The intent depends on the current session:

- **Guest** → intent `login`: the callback finds an existing user by `spotify_id` or email, or creates
  one, stores the tokens, logs them in, and syncs their recent plays.
- **Authenticated** → intent `connect`: the callback links the Spotify tokens to the current account
  and syncs their recent plays.

The callback validates `state`, handles a user-cancelled authorization, exchanges the code, fetches
the profile, and persists `spotify_id`, avatar, access token, refresh token, and expiry. An
`isConnectedToSpotify()` check on the user decides whether the dashboard shows stats or the connect
prompt.

Access tokens are refreshed lazily: `SpotifyService::accessToken()` refreshes when the token is
missing or expires within five minutes, and every API call retries once after a `401`.

### 2. Dashboard

`GET /dashboard` (`DashboardController`) renders `resources/js/pages/Dashboard.vue` with:

| Prop | Meaning |
| --- | --- |
| `connected` | Whether Spotify is linked |
| `range` | `short_term`, `medium_term`, or `long_term` (default `short_term`) |
| `spotifyError` | Message when Spotify could not be read and no cached data exists |
| `spotifyStale` | `true` when any section is being served from stale cache |
| `currentlyPlaying` | Initial `/me/player/currently-playing` payload |
| `topTracks` / `topArtists` | Cached items for the selected range |
| `topAlbums` | Albums aggregated from the selected range's top tracks |
| `recentlyPlayed` | Cached recently played items |
| `importSummary` / `imports` | History import counters and recent import rows |

Top tracks, top artists, and recently played come from the cache service. The now-playing card starts
from the dashboard payload and then polls `GET /now-playing` every five seconds (skipped while the tab
is hidden), advancing the progress bar locally between polls and switching to a paused state when
Spotify reports `is_playing: false`. The page shows an amber banner when data is stale.

### 3. Listening history sync

`StreamSyncService::sync(User $user)`:

1. Reads `/me/player/recently-played` through `SpotifyCacheService` (so the dashboard's
   recently-played panel and the sync share one request).
2. Maps each item to `user_id`, `spotify_track_id`, `duration_ms`, primary `artist_id`, `played_at`
   (UTC, millisecond precision), and `context` (the Spotify context type — playlist, album, artist).
3. Deduplicates in-memory, then inserts with `insertOrIgnore` against the unique key
   `(user_id, spotify_track_id, played_at)`. Re-syncing never double counts.
4. Backfills `duration_ms` and `artist_id` on existing rows that are still missing them (plays stored
   before those columns existed). It compares within the same second to stay correct on MySQL and
   SQLite alike.
5. Refreshes the artist snapshot at most once per day.

Sync triggers:

- `php artisan spotify:sync` every fifteen minutes from the scheduler.
- Immediately after a successful Spotify login/connection.
- On dashboard load, but only when the newest stored play is older than fifteen minutes **and** a
  five-minute per-user `Cache::add` lock permits it. That keeps page loads from hammering Spotify and
  from re-syncing every request.

### 4. Caching and resilience

`SpotifyCacheService` wraps all read endpoints used by the UI:

| Data | Cache key | Fresh for | Kept for |
| --- | --- | --- | --- |
| Top tracks | `spotify:user:{id}:top-tracks:{range}` | 15 minutes | 7 days |
| Top artists | `spotify:user:{id}:top-artists:{range}` | 15 minutes | 7 days |
| Recently played | `spotify:user:{id}:recently-played` | 5 minutes | 7 days |

Each entry stores `items` and `cached_at`. A request inside the freshness window is served from
cache. When the entry is stale the service refetches; if Spotify throws (outage, 5xx, 429, revoked
token) and a stale entry exists, the stale entry is returned with `stale: true` and the exception is
reported. If nothing is cached at all the exception propagates and the dashboard shows
`spotifyError`.

Because the cache store is the database, cached snapshots survive restarts and deploys.

### 5. Recap and sharing

`GET /recap` renders `resources/js/pages/Recap.vue` from the stored plays only. `RecapService`
combines the analytics aggregates (minutes, unique counts, genres, heatmap) with track metadata from
the `tracks` table to produce the top five tracks, the top artist and their share of plays, and the
peak hour.

Sharing is opt-in:

1. `POST /recap/share` creates a 48-character `recap_token` on the user (or keeps the existing one)
   and stores the timezone the recap was viewed in as `recap_timezone`.
2. `GET /u/{token}` renders the same story read-only without authentication, using the stored
   timezone so the shared numbers match what the owner saw. A wrong or revoked token returns `404`.
3. `GET /u/{token}/og.png` renders a 1200×630 PNG with GD for link previews.
4. `DELETE /recap/share` clears the token and timezone; the old URL stops working immediately.

`resources/views/app.blade.php` adds the Open Graph and Twitter card tags only when the page passes
an `og` prop, so ordinary pages stay untouched. The recap page can also be exported client-side with
`html-to-image` (`toPng` at 2× pixel ratio), which needs no server round trip.

### 6. Playlist tools

`GET /playlists` lists forgotten favorites from the stored plays and offers two write actions: a
playlist from the cached top tracks for a chosen range, and a playlist from the forgotten list. Both
create a private playlist and add the tracks in batches; neither action runs during a normal page
load. The OAuth callback stores the scopes Spotify granted in `users.spotify_scopes`, and
`User::hasPlaylistScope()` gates the buttons so a connection made before the scope existed asks for a
reconnect instead of failing with a `403`.

---

## History import

Spotify's Web API only exposes the last 50 plays, so the app also ingests the full
[*Extended streaming history*](https://support.spotify.com/us/article/data-rights-and-privacy-settings/)
export.

1. `POST /import` (`ImportController@store`) accepts a `.json` or `.zip` file up to 50 MB and checks
   that it can be read as Spotify history.
2. The file is stored on the `local` disk and a `history_imports` row is created with status
   `pending`; `ImportHistoryJob` is queued.
3. `HistoryImportService` reads every entry, skips plays under `MIN_PLAY_MS` (30 seconds) and entries
   without a track name or timestamp, and writes rows with `context = json_import`. Plays are
   deduplicated by `(spotify_track_id, played_at)` and inserted with `insertOrIgnore` in chunks.
4. Track titles, artists, albums, and durations are upserted into `tracks`; entries with a local
   Spotify id also get an `artist` row.
5. `TrackMetadataService` then enriches the most played unresolved tracks with covers and artist ids
   from Spotify (batched `/v1/tracks` lookups plus an oEmbed fallback).
6. The import row records `imported`, `skipped`, `files`, and `tracks`, and the page polls or reloads
   to show progress and errors.

Imported plays are real listening data: `streams.duration_ms` stores the `ms_played` value from the
export, which is what powers completion/skip analytics. Synced plays store the full track duration
instead, so they are excluded from those metrics.

`GET /history/export` downloads every stored play as CSV. `DELETE /history/imported` deletes only rows
with `context = json_import` (and their import records), leaving synced history untouched.

---

## Data model

### `users`

Standard Laravel user columns plus Spotify link data:

| Column | Notes |
| --- | --- |
| `spotify_id` | Unique Spotify user id |
| `spotify_avatar` | Profile image URL |
| `spotify_access_token` | Encrypted, hidden |
| `spotify_refresh_token` | Encrypted, hidden |
| `spotify_token_expires_at` | Access token expiry |
| `spotify_scopes` | Space-separated scopes granted at the last authorization |
| `spotify_client_id` | Optional per-user Spotify app client id (BYOK) |
| `spotify_client_secret` | Optional per-user client secret, encrypted and hidden |
| `recap_token` | 48-character public recap token, unique, nullable |
| `recap_timezone` | IANA timezone captured when the recap link was created |

Accounts created through Spotify sign-in get a random password (so they cannot use the password
login form until one is set) and leave `email_verified_at` empty. Verification is not enforced
because the `User` model does not implement `MustVerifyEmail`.

### `streams`

One row per Spotify play.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `user_id` | FK → `users` | Cascades on delete |
| `spotify_track_id` | string(32) | |
| `duration_ms` | unsigned int, nullable | Full track length for synced plays; actual `ms_played` for imported plays |
| `artist_id` | string(32), nullable, indexed | Primary artist (`track.artists[0].id`) |
| `played_at` | timestamp(3), UTC | Spotify's `played_at` |
| `context` | string(32), nullable | Context type: `playlist`, `album`, `artist`, … |
| `created_at` / `updated_at` | timestamps | |

Indexes: unique `(user_id, spotify_track_id, played_at)`, and `(user_id, played_at)` for timeline
scans.

### `artists`

Artist metadata snapshot used for genre aggregation.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `spotify_artist_id` | string(32), unique | |
| `name` | string | |
| `genres` | JSON, nullable | Cast to `array` on the model |
| `musicbrainz_id` | string(36), nullable | Resolved MusicBrainz artist id |
| `genres_synced_at` | timestamp, nullable | Last automatic genre lookup |
| `genres_overridden` | boolean | `true` when edited manually; backfills skip the artist |
| `created_at` / `updated_at` | timestamps | |

The snapshot is built from `/me/top/artists` across all three ranges, upserted by
`spotify_artist_id`, and refreshed at most daily (cache key `spotify:user:{id}:artists-snapshot`).
Artists also get rows from imported local tracks and from the genre backfill. A rarely played artist
can still be missing here; its plays count toward totals, discovery, and the heatmap, but add no
genre weight.

Genres are resolved in this order:

1. **Manual override** — saved from the artist detail page and never overwritten automatically.
2. **MusicBrainz** — the Spotify artist URL is mapped to a MusicBrainz id (or a name search is used),
   then community genres/tags are stored. A `404` means "no match", and `503` responses are retried.
3. **Last.fm** — when MusicBrainz returns no genres and `LASTFM_API_KEY` is set, top tags are used,
   with listener-only tags such as `seen live` filtered out.

The backfill is available from the analytics page (`POST /genres/backfill`, queued) and from the
`genres:backfill` command. It processes the user's most played artists first and respects
`genres_overridden` even with `--force`.

### `tracks`

Track metadata snapshot used to name and illustrate the top tracks in a recap.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `spotify_track_id` | string(32), unique | |
| `name` | string | |
| `artist_id` | string(32), nullable, indexed | Primary artist |
| `artist_name` | string, nullable | Denormalised so a recap needs no artist lookup |
| `album_name` | string, nullable | |
| `album_image_url` | text, nullable | Spotify cover art (`i.scdn.co`) |
| `duration_ms` | unsigned int, nullable | |
| `created_at` / `updated_at` | timestamps | |

Rows are upserted by `spotify_track_id` during every sync and every history import. `TrackMetadataService`
then fills missing covers, artist ids, and full durations for the most played imported tracks. Titles
and covers therefore only exist for tracks inside the 50-play sync window or an uploaded export; the
recap falls back to `Unknown track` and a placeholder for plays that were never enriched.

### `history_imports`

One row per uploaded Spotify data export.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `user_id` | FK → `users` | Cascades on delete |
| `status` | string(20) | `pending`, `running`, `completed`, or `failed` |
| `original_name` | string | Uploaded filename |
| `stored_path` | string | Path on the `local` disk |
| `imported` / `skipped` | unsigned int | Play counters |
| `files` / `tracks` | unsigned int | Files read and track rows created |
| `error` | text, nullable | Failure message |
| `started_at` / `finished_at` | timestamps, nullable | Job timing |
| `created_at` / `updated_at` | timestamps | |

### Framework tables

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` —
stock Laravel migrations.

---

## Routes

| Method | URI | Name | Middleware | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/` | — | web | Marketing page |
| GET | `/dashboard` | `dashboard` | `auth`, `verified` | Live stats and connect prompt |
| GET | `/analytics` | `analytics` | `auth`, `verified` | Stored-data analytics, filters, and history import |
| GET | `/recap` | `recap` | `auth`, `verified` | Wrapped-style recap with share controls and year comparison |
| GET | `/albums/{album}` | `albums.show` | `auth`, `verified` | Album detail page |
| GET | `/artists/{artistId}` | `artists.show` | `auth`, `verified` | Artist detail page and genre editor |
| POST | `/artists/{artistId}/genres` | `artists.genres.update` | `auth`, `verified` | Save a manual genre override |
| POST | `/import` | `import.store` | `auth`, `verified` | Queue a Spotify history export import |
| GET | `/history/export` | `history.export` | `auth`, `verified` | Download all stored plays as CSV |
| DELETE | `/history/imported` | `history.destroy-imported` | `auth`, `verified` | Delete imported plays only |
| POST | `/genres/backfill` | `genres.backfill` | `auth`, `verified` | Queue a MusicBrainz/Last.fm genre backfill |
| GET | `/now-playing` | `now-playing` | `auth`, `verified` | Compact current playback JSON for polling |
| GET | `/playlists` | `playlists.index` | `auth`, `verified` | Playlist tools and forgotten favorites |
| POST | `/playlists/top-tracks` | `playlists.top-tracks` | `auth`, `verified` | Create a playlist from top tracks |
| POST | `/playlists/forgotten` | `playlists.forgotten` | `auth`, `verified` | Create a playlist from forgotten favorites |
| POST | `/recap/share` | `recap.share` | `auth` | Create (or keep) the public link |
| DELETE | `/recap/share` | `recap.revoke` | `auth` | Revoke the public link |
| GET | `/u/{token}` | `recap.public` | web | Public read-only recap |
| GET | `/u/{token}/og.png` | `recap.image` | web | 1200×630 Open Graph card |
| GET | `/auth/spotify/redirect` | `spotify.redirect` | web | Start OAuth (login or connect) |
| GET | `/auth/spotify/callback` | `spotify.callback` | web | Finish OAuth |
| GET/PATCH/DELETE | `/profile` | `profile.*` | `auth` | Profile management |
| — | `/login`, `/register`, `/logout`, … | — | — | Standard Laravel auth routes |

`/analytics` and `/recap` accept an optional `?timezone=` IANA identifier, then the `timezone`
cookie; an invalid value falls back to the application timezone. The `/u/{token}` routes are public
and use the timezone stored when the link was created.

`/analytics` also accepts `range` (`7d`, `30d`, `90d`, `1y`, `all`), `search`, and the additive
`genre`, `artist` (artist id), and `context` filters. `/recap` accepts `year` and the optional
`compare` year; comparison renders only when both years are valid and different.

---

## Services reference

### `App\Services\SpotifyService`

Thin Spotify Web API client with token management.

| Method | Description |
| --- | --- |
| `authorizationUrl(string $state)` | Build the authorize URL with the required scopes |
| `exchangeCode(string $code)` | Exchange an authorization code for tokens |
| `refreshAccessToken(User $user)` | Refresh and persist tokens |
| `accessToken(User $user)` | Return a valid token, refreshing within 5 minutes of expiry |
| `getProfile(string $accessToken)` | `/me` profile |
| `getTopTracks(User $user, string $range, int $limit)` | `/me/top/tracks`, limit capped at 50 |
| `getTopArtists(User $user, string $range, int $limit)` | `/me/top/artists`, limit capped at 50 |
| `getRecentlyPlayed(User $user, int $limit)` | `/me/player/recently-played` |
| `getCurrentlyPlaying(User $user)` | `/me/player/currently-playing`, `null` on 204 |
| `createPlaylist(User $user, string $name, string $description)` | `POST /me/playlists`, always private |
| `addTracksToPlaylist(User $user, string $playlistId, array $uris)` | `POST /playlists/{id}/items`, 100 URIs per request |

`range` values other than the three Spotify identifiers fall back to `short_term`. A `401` is retried
once after a token refresh, including on write requests. `createPlaylist()` and
`addTracksToPlaylist()` use the endpoints that replaced Spotify's removed February 2026 playlist
routes.

### `App\Services\SpotifyCacheService`

Caches the read endpoints described above. Each method returns:

```php
[
    'items' => array<int, mixed>,
    'stale' => bool,
    'cached_at' => string|null, // ISO-8601
]
```

Methods: `topTracks(User $user, string $range)`, `topArtists(User $user, string $range)`,
`recentlyPlayed(User $user)`.

### `App\Services\StreamSyncService`

- `sync(User $user): int` — persists new plays, backfills missing metadata, upserts `tracks`
  metadata, refreshes the artist snapshot, and returns the number of newly inserted rows.

### `App\Services\HistoryImportService`

- `import(User $user, UploadedFile $file, ?callable $onProgress = null): array` — imports a Spotify
  JSON or ZIP export in chunks and returns `imported`, `skipped`, `files`, and `tracks`.
- `summary(User $user): array` — imported play count, total plays, and last import timestamp.
- `recent(User $user): array` — the latest `history_imports` rows for the progress list.
- `assertReadable(UploadedFile $file): void` — validates the upload before it is queued.

Constants: `MIN_PLAY_MS = 30_000` and `CONTEXT = 'json_import'`.

### `App\Services\TrackMetadataService`

- `enrich(User $user, int $limit = 200): int` — fills missing covers, artist ids, albums, and full
  durations for the user's most played imported tracks. Uses batched `/v1/tracks` lookups first and
  oEmbed as a fallback for up to 25 tracks.

### `App\Services\ListeningDetailService`

- `album(User $user, string $albumName, ?string $artistName = null): ?array` — album overview,
  12-month timeline, and top tracks.
- `artist(User $user, string $artistId): ?array` — artist overview, genres, monthly timeline, top
  tracks, and top albums.

### `App\Services\MusicBrainzGenreService`

- `lookup(Artist $artist): array` — resolves `musicbrainz_id` (Spotify URL relation first, then an
  exact-name search with a fallback to the top result) and returns up to five normalized genres. A
  `404` is treated as no match; `503` responses are retried.

### `App\Services\LastFmGenreService`

- `lookup(Artist $artist): array` — returns up to five normalized `artist.gettoptags` tags, filtering
  listener-only tags. Returns `[]` when `LASTFM_API_KEY` is empty or the request fails.

### `App\Services\GenreBackfillService`

- `backfill(User $user, int $limit = 50, bool $force = false): array` — processes the user's most
  played artists, skips manually overridden artists, and falls back from MusicBrainz to Last.fm.
  Returns `checked`, `updated`, and `failed` counters.

### `App\Services\ListeningAnalyticsService`

- `forUser(User $user, ?string $timezone = null, ?string $search = null, ?Carbon $from = null, ?Carbon $to = null, array $filters = []): array`
  — computes everything for the analytics page from the database only. `$filters` accepts
  `genre`, `artist_id`, and `context`, and every aggregate respects them.
- `filterOptions(User $user): array` — genres, top artists, and contexts available for the filter bar.
- `artistTrends(User $user, ?string $timezone = null): array` — twelve weekly buckets (Monday starts)
  and the play counts of the six most played artists, plus weekly rank movement for the top five.
- `artistComparison(User $user): array` — totals and top artists, with their share of plays, for the
  last 28 days and the last 365 days.

Returned shape:

```php
[
    'timezone' => 'Europe/Berlin',
    'overview' => [
        'plays' => 50,
        'minutes' => 160,
        'unique_tracks' => 50,
        'unique_artists' => 31,
        'average_track_seconds' => 192,
        'first_played_at' => '2026-11-08T08:12:00+00:00',
        'last_played_at' => '2026-11-08T13:40:00+00:00',
    ],
    'top_genres' => [
        ['genre' => 'indie pop', 'plays' => 12],
    ],
    'top_albums' => [
        [
            'album_name' => '…',
            'artist_name' => '…',
            'cover_url' => 'https://i.scdn.co/…',
            'plays' => 9,
            'minutes' => 30,
            'unique_tracks_played' => 7,
        ],
    ],
    'heatmap' => [
        'counts' => [/* 7 rows (Mon–Sun) × 24 hours */],
        'max' => 13,
    ],
    'discovery' => ['new_artists' => 31, 'plays' => 50, 'rate' => 0.62],
    'streaks' => ['current' => 4, 'longest' => 4],
    'completion' => [
        'plays' => 120,
        'completed' => 84,
        'skipped' => 22,
        'rate' => 0.7,
        'skipped_tracks' => [
            [
                'spotify_track_id' => '…',
                'name' => '…',
                'artist_name' => '…',
                'plays' => 6,
                'skips' => 5,
                'skip_rate' => 0.8333,
            ],
        ],
    ],
    'tracks_leaderboard' => [/* exact play counts per track */],
]
```

`completion` only considers imported plays (`context = json_import`), because their `duration_ms` is
the real listening time. A play counts as completed at ≥ 90% of the track duration and as a skip
below 50%; `skipped_tracks` needs at least two plays.

`artistTrends()` returns chart-ready data:

```php
[
    'weeks' => ['2026-08-17', /* … 12 ISO week starts … */ '2026-11-02'],
    'series' => [
        ['artist_id' => '…', 'name' => 'Nessyou', 'plays' => [2, 0, 5, /* … 12 weeks … */]],
    ],
    'movement' => [
        ['artist_id' => '…', 'name' => 'Nessyou', 'plays' => 10, 'previous_plays' => 4, 'rank' => 1, 'change' => 2],
    ],
]
```

`change` is `previous rank − current rank` for the last seven days versus the seven before, or `null`
when the artist did not appear in the previous week.

`artistComparison()` returns one summary per window:

```php
[
    'recent' => [
        'plays' => 50,
        'minutes' => 160,
        'unique_artists' => 31,
        'top_artists' => [
            ['artist_id' => '…', 'name' => 'Nessyou', 'plays' => 10, 'share' => 0.2],
        ],
    ],
    'year' => [/* same shape over the last 365 days */],
]
```

### `App\Services\RecapService`

- `availableYears(User $user): array` — the inclusive year range present in stored plays.
- `forUser(User $user, ?string $timezone = null, ?int $year = null): array` — builds the recap from
  the stored plays and the `tracks` snapshot, optionally limited to a single year:

```php
[
    'timezone' => 'Europe/Berlin',
    'period' => ['from' => '2026-11-05T07:12:00+00:00', 'to' => '2026-11-08T13:40:00+00:00'],
    'overview' => ['plays' => 50, 'minutes' => 160, 'unique_artists' => 31, 'unique_tracks' => 48],
    'top_tracks' => [
        [
            'spotify_track_id' => '…',
            'name' => 'TEFLON',
            'artist_name' => "L'morphine",
            'album_name' => '…',
            'cover_url' => 'https://i.scdn.co/…',
            'duration_ms' => 205000,
            'plays' => 7,
        ],
    ],
    'top_artist' => ['artist_id' => '…', 'name' => 'Nessyou', 'plays' => 10, 'share' => 0.2],
    'top_genres' => [['genre' => 'indie pop', 'plays' => 12]],
    'peak_hour' => ['hour' => 17, 'plays' => 13],
]
```

`top_tracks` holds at most five tracks ordered by play count. `top_artist.share` is that artist's
plays divided by every play with an attributed artist. `peak_hour` sums the heatmap columns and is
`null` when there are no plays.

### `App\Services\RecapImageService`

- `render(array $recap): string` — returns the PNG bytes for the 1200×630 Open Graph card. It uses the
  first available TrueType font (`resources/fonts/Figtree-Bold.ttf`, then Arial, DejaVu Sans, or
  Liberation Sans) and falls back to GD's built-in bitmap font when none can be found. It throws a
  `RuntimeException` when the GD extension is unavailable.

### `App\Services\PlaylistService`

- `createFromTopTracks(User $user, string $range): array` — reads the cached top tracks for the range,
  keeps up to 30 unique track URIs, creates a private playlist, and appends the tracks.
- `createFromForgottenFavorites(User $user): array` — the same flow using the forgotten list.
- `forgottenFavorites(User $user, int $limit = 30): array` — groups `streams` by track, keeps tracks
  with at least two plays whose most recent play is older than 14 days, orders by play count, and
  joins `tracks` for title, artist, album, cover, and duration.

Constants: `TOP_TRACKS = 30`, `FORGOTTEN_LIMIT = 30`, `FORGOTTEN_DAYS = 14`,
`FORGOTTEN_MIN_PLAYS = 2`. Each create method returns
`['id' => …, 'name' => …, 'url' => …, 'track_count' => …]`.

---

## Commands and schedule

```bash
php artisan spotify:sync                    # every connected account
php artisan spotify:sync --user=1           # a single user id
php artisan genres:backfill                 # MusicBrainz + Last.fm for every user
php artisan genres:backfill --user=1 --limit=100 --force
php artisan schedule:list                   # show the registered schedule
php artisan schedule:work                   # run the scheduler in development
php artisan queue:work                      # process imports and genre jobs
```

| Command | Schedule | Notes |
| --- | --- | --- |
| `spotify:sync` | `*/15 * * * *` | `withoutOverlapping()`; failures are reported per account and the command exits non-zero if any account failed |
| `genres:backfill` | — | `--user`, `--limit` (default 50), and `--force` rechecks already-synced artists; manual overrides are always kept |

---

## Analytics definitions

Everything on `/analytics` is derived from the `streams` table, so the page loads without calling
Spotify.

| Metric | Definition |
| --- | --- |
| Plays | `COUNT(*)` of stored plays |
| Listening time | `SUM(duration_ms)`, displayed as minutes / hours |
| Unique tracks | `COUNT(DISTINCT spotify_track_id)` |
| Unique artists | `COUNT(DISTINCT artist_id)` — the **primary** artist of each play |
| Average track length | `AVG(duration_ms)` across stored plays |
| Top genres | Artist genres weighted by plays, top 8 |
| Top albums | Plays grouped by album, top 12, tie-broken by listening time |
| Filters | `genre`, `artist` (id), and `context` are applied to every aggregate on the page |
| Heatmap | Plays per weekday (Mon–Sun) × hour of day, in the reported timezone |
| Discovery rate | `unique artists ÷ total plays` — the share of plays that introduced a new artist |
| Weekly trend | Plays per ISO week (Monday start) in the report timezone for the six most played artists over 12 weeks |
| Rank movement | New rank subtracted from the previous week's rank; `new` when the artist had no plays the week before |
| 4 weeks vs 1 year | Per-artist share of plays in the last 28 days and the last 365 days, plus window totals |
| Current streak | Consecutive days with at least one play, ending today or yesterday |
| Longest streak | Longest run of consecutive days with at least one play |
| Completion rate | Imported plays whose duration is ≥ 90% of the track length, divided by all measurable imported plays |
| Skip rate | Imported plays whose duration is < 50% of the track length |
| Most skipped tracks | Imported tracks with at least two plays, ordered by skip count and skip rate |

Listening time uses `streams.duration_ms`. Synced plays record the full track duration, so a skipped
track still counts its full length. Imported plays record the actual `ms_played`, which is why only
those rows are used for completion and skip metrics. The range filter and the genre/artist/context
filters apply before any metric is computed.

---

## Recap and sharing

The recap is a single scrollable story built from the same stored plays as the analytics page:

| Section | Source |
| --- | --- |
| Minutes, plays, artists, tracks | `ListeningAnalyticsService::forUser()` overview |
| Top artist and their share of plays | Most played `artist_id` |
| Top 5 tracks | Play counts joined with `tracks` for title, artist, album, cover, duration |
| Top 3 genres | Aggregated artist genres |
| Most active hour | Column totals of the weekday × hour heatmap |

**Owner view (`/recap`).** Shows the story plus the download button and share controls. An empty
history renders an empty state instead of zeroed sections. A year selector limits the recap to one
calendar year, and the optional **Compare** selector renders `RecapComparison.vue` with the selected
year beside another year: plays, minutes, artists, tracks, top artist, album, track, and genre.

**Image export.** `html-to-image`'s `toPng` renders the cover card at 2× pixel ratio and downloads
`spotify-stats-recap.png`; no server call is involved. Failures are reported inline.

**Share link.** Creating a link stores a 48-character `recap_token` (regenerated only after a revoke)
and the current timezone on the user. The link is reusable and read-only until revoked; revoking
clears both columns and immediately breaks the URL and its image.

**OG image.** `/u/{token}/og.png` is drawn server-side by `RecapImageService` with GD: a dark card
with the minutes, play/artist/track counts, top artist, top track, and peak hour. It is sent as
`image/png` with `Cache-Control: public, max-age=3600`, so a link preview may lag behind new plays by
up to an hour. The public page also emits `og:*` and `twitter:*` tags in the Blade shell because
Inertia SSR is disabled.

---

## Playlist tools

The `/playlists` page turns listening data into private playlists:

| Action | Source | Selection |
| --- | --- | --- |
| Top tracks | Cached `/me/top/tracks` for the chosen range | Up to 30 unique tracks |
| Forgotten favorites | Stored `streams` | Played at least twice, no play for 14 days, up to 30 tracks |

**Forgotten favorites.** The definition is deliberately strict: a track needs two or more stored plays
and its *most recent* play must be older than 14 days. Using `MAX(played_at)` means one recent listen
removes a track even if it has months of history behind it. After a fresh install the list stays empty
until enough history accumulates, and the page explains that instead of showing a dead button.

**Playlist creation.** Both actions create an empty playlist with `POST /me/playlists` and
`public: false`, then append tracks with `POST /playlists/{id}/items` in batches of 100. Spotify's
February 2026 Web API changes removed `POST /users/{user_id}/playlists` and
`POST /playlists/{id}/tracks`; the app uses the replacement endpoints. The created playlist is
flashed back to the page with an **Open in Spotify** link.

**Permissions.** Creating private playlists requires the `playlist-modify-private` scope, which is
requested during OAuth and stored in `users.spotify_scopes` when Spotify returns it. Connections made
before this scope was added have no stored scope; the page shows a **Reconnect Spotify** prompt and
`User::hasPlaylistScope()` blocks the write actions server-side. A `403` or `429` from Spotify is
reported as a flash message rather than a stack trace.

---

## Timezones

Heatmap cells and streaks depend on the listener's local day, not UTC. On boot the front end writes
the browser's IANA timezone to a `timezone` cookie (`resources/js/app.js`), which
`bootstrap/app.php` excludes from cookie encryption so it can be read as-is. `AnalyticsController`
accepts a valid `?timezone=` query parameter, then the cookie, then falls back to
`config('app.timezone')` (UTC).

Data is always stored in UTC; conversion happens only for display and aggregation.

A shared recap keeps the timezone it was created in (`users.recap_timezone`), so `/u/{token}` and its
OG image reproduce the owner's hours and days even when a visitor is in a different zone. Revoking
the link clears the stored timezone.

The application timezone itself is set in `config/app.php`.

---

## Testing

The suite uses Pest. `phpunit.xml` points tests at an in-memory SQLite database, so the PHP build
needs `pdo_sqlite`:

```bash
php -d extension=pdo_sqlite vendor/bin/pest
```

`php artisan test` spawns a child PHP process that does not inherit the `-d` flag, so on a build where
`pdo_sqlite` is not enabled in `php.ini` it fails with *could not find driver*. Enable the extension
in `php.ini` (Laragon ships `ext/php_pdo_sqlite.dll`) to use `php artisan test` directly.

Focused runs:

```bash
php -d extension=pdo_sqlite vendor/bin/pest tests/Feature/ListeningAnalyticsTest.php
php -d extension=pdo_sqlite vendor/bin/pest --filter="streak"
```

Coverage highlights:

| Test file | Covers |
| --- | --- |
| `tests/Feature/SpotifyCacheTest.php` | Per-user/per-range caching, stale fallback, error state, dashboard with stale data |
| `tests/Feature/StreamSyncTest.php` | Play persistence, deduplication, invalid items, command sync, metadata backfill, dashboard sync throttling |
| `tests/Feature/ListeningAnalyticsTest.php` | Overview totals, genre weighting, genre/artist/context filters, completion and skip rates, timezone heatmap, streaks, discovery, weekly trends and rank movement, window comparison, empty state, route props, timezone cookie, metadata capture |
| `tests/Feature/ListeningDetailTest.php` | Album and artist detail pages, timelines, not-found handling |
| `tests/Feature/RecapTest.php` | Recap data and empty state, year selection and year-vs-year comparison, share/revoke, public page and OG tags, PNG signature, track metadata snapshot |
| `tests/Feature/HistoryImportTest.php` | JSON/ZIP parsing, 30-second skip threshold, duplicate handling, local artists, queued imports, progress, metadata enrichment |
| `tests/Feature/HistoryDataTest.php` | CSV history export and deleting imported plays only |
| `tests/Feature/GenreBackfillTest.php` | MusicBrainz lookups and retries, exact-name preference, Last.fm fallback, manual overrides, override route and authorization |
| `tests/Feature/PlaylistTest.php` | Scope request and storage, top-track and forgotten playlists, 100-URI batching, forgotten selection rules, page props, missing-scope guard, Spotify `403` |
| `tests/Feature/ByokTest.php` | Custom Spotify app detection, encrypted secrets, authorization fallback, token clearing |
| `tests/Feature/NowPlayingTest.php` | Polling payload shape, no playback, Spotify failure, disconnected user, authentication |
| `tests/Feature/Auth/*`, `ProfileTest.php` | Standard auth and profile flows |

Format PHP before finishing changes:

```bash
vendor/bin/pint --dirty --format agent
```

---

## Known limitations

- **Some artists still have no genres.** Spotify no longer returns genres, so the app uses MusicBrainz
  with a Last.fm fallback and manual overrides. Artists missing from both databases stay untagged until
  you edit them. Without `LASTFM_API_KEY`, only MusicBrainz and manual edits apply.
- **Imports skip short plays.** Spotify history entries under 30 seconds are ignored, matching the
  threshold used by the importer, so very brief skips never reach analytics.
- **Completion metrics cover imports only.** Synced plays store the full track duration rather than the
  time actually played, so they are excluded from completion and skip rates. Upload a history export
  to populate those metrics.
- **Only the last 50 plays are available.** `/me/player/recently-played` cannot be paginated, so a gap
  appears if more than 50 tracks are played between syncs. Running the scheduler every fifteen
  minutes keeps this unlikely.
- **Metadata backfill is windowed.** Plays stored before the analytics columns existed only get
  `duration_ms` and `artist_id` if they are still inside that 50-play window.
- **Now playing polls.** The dashboard asks `GET /now-playing` every five seconds while the tab is
  visible, and pauses polling in the background. The endpoint is not cached, so each open dashboard
  makes one Spotify call per poll; the progress bar is interpolated between responses.
- **Playlists need new consent.** Existing Spotify connections do not have
  `playlist-modify-private`; the page asks for a reconnect before the create buttons work.
- **Forgotten favorites need history.** With only a few days of stored plays the list is usually
  empty; it needs tracks with at least two plays that have gone two weeks without a listen.
- **Artists are the primary artist.** Collaborations only credit `track.artists[0]` for unique-artist,
  discovery, and genre figures.
- **The scheduler and queue worker must be running.** Without `schedule:work` or the cron entry, history
  only grows when the dashboard triggers a fallback sync; without `queue:work`, imports and genre
  backfills stay queued.
- **No data pruning.** `streams` grows forever; aggregate the table or add a retention window if the
  history becomes large.
- **Recap images need GD.** The OG card uses GD with FreeType. Without the extension
  `/u/{token}/og.png` returns a `500`, though the public page itself still works.
- **Track metadata only covers the sync window.** `/me/player/recently-played` returns the last 50
  plays, so older plays have no stored title or cover and appear as `Unknown track` with a
  placeholder.
- **Share links are bearer URLs.** Anyone with the URL can view the recap and its image until the
  owner revokes it. There is no password, expiry, or per-link analytics.
- **Client export can miss covers.** `html-to-image` inlines the card, but Spotify's `i.scdn.co`
  images can refuse CORS; the export still downloads with a blank cover slot instead of failing.

---

## Project structure

```
app/
  Console/Commands/
    SyncSpotifyStreams.php                  spotify:sync
    BackfillGenres.php                      genres:backfill
  Http/
    Controllers/AnalyticsController.php     /analytics, range + filters + import summary
    Controllers/RecapController.php         /recap, year + comparison, share + revoke
    Controllers/PublicRecapController.php   /u/{token}, OG image
    Controllers/DashboardController.php     /dashboard, fallback sync, merged genres
    Controllers/NowPlayingController.php    /now-playing polling payload
    Controllers/PlaylistController.php      /playlists, create actions
    Controllers/ListeningDetailController.php  album and artist detail pages
    Controllers/ArtistGenreController.php   manual genre overrides
    Controllers/GenreController.php         queued genre backfill
    Controllers/ImportController.php        queued history import
    Controllers/HistoryDataController.php   CSV export and imported-play deletion
    Controllers/SpotifyController.php       OAuth redirect + callback
    Controllers/SpotifyAppController.php    per-user Spotify app credentials
    Controllers/Concerns/ResolvesTimezone.php  Query/cookie timezone resolution
    Middleware/HandleInertiaRequests.php    Shared Inertia props (auth, flash)
  Jobs/
    ImportHistoryJob.php                    Background history import
    BackfillGenresJob.php                   Background genre backfill
  Models/
    Artist.php                              Artist snapshot, genres, override flag
    HistoryImport.php                       Import status and counters
    Stream.php                              A single stored play
    Track.php                               Track metadata snapshot
    User.php                                Spotify tokens, streams, recap token
  Services/
    ListeningAnalyticsService.php           Analytics aggregates, filters, completion
    ListeningDetailService.php              Album and artist detail data
    RecapService.php                        Recap story and year data
    RecapImageService.php                   GD Open Graph card
    PlaylistService.php                     Top-track and forgotten-favorite playlists
    HistoryImportService.php                Spotify export parser and importer
    TrackMetadataService.php                Cover/artist/duration enrichment
    MusicBrainzGenreService.php             Primary genre lookup
    LastFmGenreService.php                  Genre fallback
    GenreBackfillService.php                Backfill orchestration and overrides
    SpotifyCacheService.php                 TTL cache + stale fallback
    SpotifyService.php                      Spotify Web API client
    StreamSyncService.php                   Persist plays, backfill, track + artist snapshots

database/migrations/
  ..._create_streams_table.php
  ..._create_artists_table.php
  ..._create_tracks_table.php
  ..._add_analytics_columns_to_streams_table.php
  ..._add_recap_to_users_table.php
  ..._add_spotify_scopes_to_users_table.php
  ..._add_spotify_app_credentials_to_users_table.php
  ..._create_history_imports_table.php
  ..._add_musicbrainz_fields_to_artists_table.php
  ..._add_genres_overridden_to_artists_table.php

resources/js/
  app.js                                    Inertia boot + timezone cookie
  Layouts/AuthenticatedLayout.vue           Navigation (Dashboard, Analytics, Recap, Playlists, Profile)
  Components/Chart.vue                      Chart.js wrapper (line and bar)
  Components/HistoryImport.vue              Upload, progress, delete controls
  Components/RecapStory.vue                 Recap sections, export, share controls
  Components/RecapComparison.vue            Year-vs-year comparison
  chartTheme.js                             Shared chart palette, grid, and tooltip styling
  pages/Dashboard.vue                       Live Spotify stats
  pages/Analytics.vue                       Analytics, filters, completion, import panel
  pages/AlbumDetail.vue                     Album play history
  pages/ArtistDetail.vue                    Artist play history and genre editor
  pages/Recap.vue                           Owner and public recap shell
  pages/Playlists.vue                       Playlist tools and forgotten favorites
  pages/Welcome.vue                         Landing page

routes/
  web.php                                   Dashboard, analytics, details, imports, genres, recap, playlists, OAuth, profile
  console.php                               spotify:sync schedule

tests/Feature/
  SpotifyCacheTest.php
  StreamSyncTest.php
  ListeningAnalyticsTest.php
  ListeningDetailTest.php
  RecapTest.php
  HistoryImportTest.php
  HistoryDataTest.php
  GenreBackfillTest.php
  PlaylistTest.php
  ByokTest.php
  NowPlayingTest.php
```
