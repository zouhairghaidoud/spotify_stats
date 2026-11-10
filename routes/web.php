<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\HistoryDataController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ListeningDetailController;
use App\Http\Controllers\NowPlayingController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicRecapController;
use App\Http\Controllers\RecapController;
use App\Http\Controllers\SpotifyAppController;
use App\Http\Controllers\SpotifyController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/analytics', AnalyticsController::class)
    ->middleware(['auth', 'verified'])
    ->name('analytics');

Route::get('/recap', [RecapController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('recap');

Route::get('/albums/{album}', [ListeningDetailController::class, 'album'])
    ->where('album', '.*')
    ->middleware(['auth', 'verified'])
    ->name('albums.show');

Route::get('/artists/{artistId}', [ListeningDetailController::class, 'artist'])
    ->middleware(['auth', 'verified'])
    ->name('artists.show');

Route::post('/import', [ImportController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('import.store');

Route::get('/history/export', [HistoryDataController::class, 'export'])
    ->middleware(['auth', 'verified'])
    ->name('history.export');

Route::delete('/history/imported', [HistoryDataController::class, 'destroyImported'])
    ->middleware(['auth', 'verified'])
    ->name('history.destroy-imported');

Route::post('/genres/backfill', [GenreController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('genres.backfill');

Route::get('/now-playing', NowPlayingController::class)
    ->middleware(['auth', 'verified'])
    ->name('now-playing');

Route::get('/playlists', [PlaylistController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('playlists.index');

Route::post('/playlists/top-tracks', [PlaylistController::class, 'topTracks'])
    ->middleware(['auth', 'verified'])
    ->name('playlists.top-tracks');

Route::post('/playlists/forgotten', [PlaylistController::class, 'forgotten'])
    ->middleware(['auth', 'verified'])
    ->name('playlists.forgotten');

Route::get('/u/{token}', [PublicRecapController::class, 'show'])->name('recap.public');
Route::get('/u/{token}/og.png', [PublicRecapController::class, 'image'])->name('recap.image');

Route::get('/auth/spotify/redirect', [SpotifyController::class, 'redirect'])->name('spotify.redirect');
Route::get('/auth/spotify/callback', [SpotifyController::class, 'callback'])->name('spotify.callback');

Route::middleware('auth')->group(function () {
    Route::post('/recap/share', [RecapController::class, 'share'])->name('recap.share');
    Route::delete('/recap/share', [RecapController::class, 'revoke'])->name('recap.revoke');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/profile/spotify-app', [SpotifyAppController::class, 'update'])
        ->name('profile.spotify-app.update');
    Route::delete('/profile/spotify-app', [SpotifyAppController::class, 'destroy'])
        ->name('profile.spotify-app.destroy');
});

require __DIR__.'/auth.php';
