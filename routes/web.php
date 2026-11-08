<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NowPlayingController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicRecapController;
use App\Http\Controllers\RecapController;
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
});

require __DIR__.'/auth.php';
