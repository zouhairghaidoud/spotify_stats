# Spotify Stats

Personal Spotify listening analytics. Connect Spotify once and the app collects your listening
history, caches your top tracks and artists, and turns the stored plays into analytics that need no
Spotify connection to render.

- **Dashboard** — now playing, top tracks, top artists (4 weeks / 6 months / 1 year), recently played.
- **Analytics** — listening time, play counts, unique tracks and artists, average track length, top
  genres, a weekday × hour heatmap, discovery rate, and listening streaks.
- **Charts** — a 12-week trend line per top artist with weekly rank movement, and a side-by-side
  comparison of the last 4 weeks against the last year, drawn with Chart.js.
- **Stored history** — a `streams` table of every play collected from `/me/player/recently-played`.
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
8. [Data model](#data-model)
9. [Routes](#routes)
10. [Services reference](#services-reference)
11. [Commands and schedule](#commands-and-schedule)
12. [Analytics definitions](#analytics-definitions)
13. [Timezones](#timezones)
14. [Testing](#testing)
15. [Known limitations](#known-limitations)
16. [Project structure](#project-structure)

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
```

All access is read-only. Tokens are stored encrypted and can be revoked from the Spotify account
page at any time.

---

## Requirements

- PHP 8.3 with the extensions your database needs (`pdo_mysql`), plus `pdo_sqlite` if you want to run
  the test suite (`phpunit.xml` uses an in-memory SQLite database).
- Composer 2
- Node.js 20+ and npm
- A Spotify developer app (see below)

---

## Installation

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Configure the database and Spotify credentials in .env, then:
php artisan migrate
npm run build
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

No additional scopes are requested by the app; Spotify will show exactly the four listed above.

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
| `SPOTIFY_CLIENT_ID` | Spotify app client id |
| `SPOTIFY_CLIENT_SECRET` | Spotify app client secret |
| `SPOTIFY_REDIRECT_URI` | Must exactly match the redirect URI in the Spotify dashboard |

Laravel's cache, sessions, and queue tables are created by the standard migrations, so the database
drivers work without extra setup.

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
| `currentlyPlaying` | Live `/me/player/currently-playing` payload (always fetched live) |
| `topTracks` / `topArtists` | Cached items for the selected range |
| `recentlyPlayed` | Cached recently played items |

Top tracks, top artists, and recently played come from the cache service; now playing is a live call.
The page shows an amber banner when data is stale.

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
| `duration_ms` | unsigned int, nullable | Track length at the time of the play |
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
| `created_at` / `updated_at` | timestamps | |

The snapshot is built from `/me/top/artists` across all three ranges, upserted by
`spotify_artist_id`, and refreshed at most daily (cache key `spotify:user:{id}:artists-snapshot`).
Only artists that appear in one of those top lists get a row. A rarely played artist can therefore be
missing here; its plays still count toward totals, discovery, and the heatmap, but add no genre
weight.

### Framework tables

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` —
stock Laravel migrations.

---

## Routes

| Method | URI | Name | Middleware | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/` | — | web | Marketing page |
| GET | `/dashboard` | `dashboard` | `auth`, `verified` | Live stats and connect prompt |
| GET | `/analytics` | `analytics` | `auth`, `verified` | Stored-data analytics |
| GET | `/auth/spotify/redirect` | `spotify.redirect` | web | Start OAuth (login or connect) |
| GET | `/auth/spotify/callback` | `spotify.callback` | web | Finish OAuth |
| GET/PATCH/DELETE | `/profile` | `profile.*` | `auth` | Profile management |
| — | `/login`, `/register`, `/logout`, … | — | — | Standard Laravel auth routes |

`/analytics` accepts an optional `?timezone=` IANA identifier; an invalid value falls back to the
application timezone.

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

`range` values other than the three Spotify identifiers fall back to `short_term`. A `401` is retried
once after a token refresh.

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

- `sync(User $user): int` — persists new plays, backfills missing metadata, refreshes the artist
  snapshot, and returns the number of newly inserted rows.

### `App\Services\ListeningAnalyticsService`

- `forUser(User $user, ?string $timezone = null): array` — computes everything for the analytics page
  from the database only.
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
    'heatmap' => [
        'counts' => [/* 7 rows (Mon–Sun) × 24 hours */],
        'max' => 13,
    ],
    'discovery' => ['new_artists' => 31, 'plays' => 50, 'rate' => 0.62],
    'streaks' => ['current' => 4, 'longest' => 4],
]
```

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

---

## Commands and schedule

```bash
php artisan spotify:sync                # every connected account
php artisan spotify:sync --user=1       # a single user id
php artisan schedule:list               # show the registered schedule
php artisan schedule:work               # run the scheduler in development
```

| Command | Schedule | Notes |
| --- | --- | --- |
| `spotify:sync` | `*/15 * * * *` | `withoutOverlapping()`; failures are reported per account and the command exits non-zero if any account failed |

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
| Heatmap | Plays per weekday (Mon–Sun) × hour of day, in the reported timezone |
| Discovery rate | `unique artists ÷ total plays` — the share of plays that introduced a new artist |
| Weekly trend | Plays per ISO week (Monday start) in the report timezone for the six most played artists over 12 weeks |
| Rank movement | New rank subtracted from the previous week's rank; `new` when the artist had no plays the week before |
| 4 weeks vs 1 year | Per-artist share of plays in the last 28 days and the last 365 days, plus window totals |
| Current streak | Consecutive days with at least one play, ending today or yesterday |
| Longest streak | Longest run of consecutive days with at least one play |

Listening time uses the full track duration reported by Spotify, so a skipped track still counts its
full length. There is currently no partial-play data available from the API.

---

## Timezones

Heatmap cells and streaks depend on the listener's local day, not UTC. On boot the front end writes
the browser's IANA timezone to a `timezone` cookie (`resources/js/app.js`), which
`bootstrap/app.php` excludes from cookie encryption so it can be read as-is. `AnalyticsController`
accepts a valid `?timezone=` query parameter, then the cookie, then falls back to
`config('app.timezone')` (UTC).

Data is always stored in UTC; conversion happens only for display and aggregation.

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
| `tests/Feature/ListeningAnalyticsTest.php` | Overview totals, genre weighting, timezone heatmap, streaks, discovery, weekly trends and rank movement, window comparison, empty state, route props, timezone cookie, metadata capture |
| `tests/Feature/Auth/*`, `ProfileTest.php` | Standard auth and profile flows |

Format PHP before finishing changes:

```bash
vendor/bin/pint --dirty --format agent
```

---

## Known limitations

- **Top genres may be empty.** Spotify removed the `genres` field from artist objects and now returns
  `403 Forbidden` for `GET /v1/artists` to apps created after 27 November 2024. `/me/top/artists` no
  longer includes genres either. The genre aggregation is implemented and tested, and populates as
  soon as genre data exists in `artists`, but there is currently no Spotify source for it.
  Integrating Last.fm or MusicBrainz would fill it.
- **Only the last 50 plays are available.** `/me/player/recently-played` cannot be paginated, so a gap
  appears if more than 50 tracks are played between syncs. Running the scheduler every fifteen
  minutes keeps this unlikely.
- **Metadata backfill is windowed.** Plays stored before the analytics columns existed only get
  `duration_ms` and `artist_id` if they are still inside that 50-play window.
- **Now playing is always live.** It is deliberately not cached, so a dashboard request makes one
  Spotify call when the token is valid.
- **Artists are the primary artist.** Collaborations only credit `track.artists[0]` for unique-artist,
  discovery, and genre figures.
- **The scheduler must be running.** Without `schedule:work` or the cron entry, history only grows when
  the dashboard triggers a fallback sync.
- **No data pruning.** `streams` grows forever; aggregate the table or add a retention window if the
  history becomes large.

---

## Project structure

```
app/
  Console/Commands/SyncSpotifyStreams.php   Artisan sync command
  Http/
    Controllers/AnalyticsController.php     /analytics
    Controllers/DashboardController.php     /dashboard, fallback sync
    Controllers/SpotifyController.php       OAuth redirect + callback
    Middleware/HandleInertiaRequests.php    Shared Inertia props (auth, flash)
  Models/
    Artist.php                              Artist snapshot + genre cast
    Stream.php                              A single stored play
    User.php                                Spotify tokens, streams relation
  Services/
    ListeningAnalyticsService.php           All analytics aggregates
    SpotifyCacheService.php                 TTL cache + stale fallback
    SpotifyService.php                      Spotify Web API client
    StreamSyncService.php                   Persist plays, backfill, artist snapshot

database/migrations/
  ..._create_streams_table.php
  ..._create_artists_table.php
  ..._add_analytics_columns_to_streams_table.php

resources/js/
  app.js                                    Inertia boot + timezone cookie
  Layouts/AuthenticatedLayout.vue           Navigation (Dashboard, Analytics, Profile)
  Components/Chart.vue                      Chart.js wrapper (line and bar)
  chartTheme.js                             Shared chart palette, grid, and tooltip styling
  pages/Dashboard.vue                       Live Spotify stats
  pages/Analytics.vue                       Stored-data analytics
  pages/Welcome.vue                         Landing page

routes/
  web.php                                   Dashboard, analytics, OAuth, profile
  console.php                               spotify:sync schedule

tests/Feature/
  SpotifyCacheTest.php
  StreamSyncTest.php
  ListeningAnalyticsTest.php
```
