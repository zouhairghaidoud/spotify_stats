<?php

use App\Models\User;
use App\Services\SpotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.spotify.client_id' => 'env-client-id',
        'services.spotify.client_secret' => 'env-client-secret',
        'services.spotify.redirect' => 'http://localhost/auth/spotify/callback',
    ]);
});

it('detects a user with both custom spotify credentials', function () {
    $user = User::factory()->withCustomSpotifyApp()->create();

    expect($user->hasCustomSpotifyApp())->toBeTrue();

    $user->forceFill(['spotify_client_secret' => null])->save();

    expect($user->fresh()->hasCustomSpotifyApp())->toBeFalse();
});

it('stores the client secret encrypted at rest', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'spotify_client_id' => 'custom-client',
        'spotify_client_secret' => 'super-secret',
    ])->save();

    $raw = DB::table('users')->where('id', $user->id)->value('spotify_client_secret');

    expect($raw)->not->toBe('super-secret')
        ->and($user->fresh()->spotify_client_secret)->toBe('super-secret');
});

it('builds the authorization url with the user custom app', function () {
    $user = User::factory()->withCustomSpotifyApp()->create([
        'spotify_client_id' => 'custom-client-id',
    ]);

    parse_str(parse_url(app(SpotifyService::class)->authorizationUrl('state-1', $user), PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('custom-client-id')
        ->and($query['state'])->toBe('state-1');
});

it('falls back to the environment app for guests and users without custom credentials', function () {
    $user = User::factory()->create();

    parse_str(parse_url(app(SpotifyService::class)->authorizationUrl('guest-state'), PHP_URL_QUERY), $guestQuery);
    parse_str(parse_url(app(SpotifyService::class)->authorizationUrl('user-state', $user), PHP_URL_QUERY), $userQuery);

    expect($guestQuery['client_id'])->toBe('env-client-id')
        ->and($userQuery['client_id'])->toBe('env-client-id');
});

it('exchanges the authorization code with the user custom app credentials', function () {
    $user = User::factory()->withCustomSpotifyApp()->create([
        'spotify_client_id' => 'custom-client-id',
        'spotify_client_secret' => 'custom-client-secret',
    ]);

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(['access_token' => 'token']),
    ]);

    app(SpotifyService::class)->exchangeCode('authorization-code', $user);

    Http::assertSent(function (Request $request): bool {
        $expected = 'Basic '.base64_encode('custom-client-id:custom-client-secret');

        return str_contains($request->url(), 'accounts.spotify.com/api/token')
            && $request->hasHeader('Authorization', $expected)
            && $request['code'] === 'authorization-code';
    });
});

it('refreshes tokens with the user custom app credentials', function () {
    $user = User::factory()->connectedToSpotify()->withCustomSpotifyApp()->create([
        'spotify_client_id' => 'custom-client-id',
        'spotify_client_secret' => 'custom-client-secret',
        'spotify_refresh_token' => 'old-refresh-token',
    ]);

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    app(SpotifyService::class)->refreshAccessToken($user);

    Http::assertSent(function (Request $request): bool {
        $expected = 'Basic '.base64_encode('custom-client-id:custom-client-secret');

        return $request->hasHeader('Authorization', $expected)
            && $request['grant_type'] === 'refresh_token';
    });

    expect($user->fresh()->spotify_access_token)->toBe('new-access-token');
});

it('saves custom app credentials from the profile and disconnects spotify', function () {
    $user = User::factory()->connectedToSpotify()->create();

    $this->actingAs($user)
        ->post('/profile/spotify-app', [
            'spotify_client_id' => 'new-client-id',
            'spotify_client_secret' => 'new-client-secret',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user->refresh();

    expect($user->spotify_client_id)->toBe('new-client-id')
        ->and($user->spotify_client_secret)->toBe('new-client-secret')
        ->and($user->hasCustomSpotifyApp())->toBeTrue()
        ->and($user->isConnectedToSpotify())->toBeFalse()
        ->and($user->spotify_refresh_token)->toBeNull();
});

it('keeps the current spotify connection when the credentials do not change', function () {
    $user = User::factory()->connectedToSpotify()->withCustomSpotifyApp()->create([
        'spotify_client_id' => 'same-client-id',
        'spotify_client_secret' => 'same-client-secret',
    ]);

    $this->actingAs($user)
        ->post('/profile/spotify-app', [
            'spotify_client_id' => 'same-client-id',
            'spotify_client_secret' => 'same-client-secret',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->isConnectedToSpotify())->toBeTrue();
});

it('keeps the existing secret when the client id is unchanged and the secret is blank', function () {
    $user = User::factory()->withCustomSpotifyApp()->create([
        'spotify_client_id' => 'same-client-id',
        'spotify_client_secret' => 'existing-secret',
    ]);

    $this->actingAs($user)
        ->post('/profile/spotify-app', [
            'spotify_client_id' => 'same-client-id',
            'spotify_client_secret' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->spotify_client_secret)->toBe('existing-secret');
});

it('removes custom app credentials and disconnects spotify', function () {
    $user = User::factory()->connectedToSpotify()->withCustomSpotifyApp()->create();

    $this->actingAs($user)
        ->delete('/profile/spotify-app')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user->refresh();

    expect($user->spotify_client_id)->toBeNull()
        ->and($user->spotify_client_secret)->toBeNull()
        ->and($user->hasCustomSpotifyApp())->toBeFalse()
        ->and($user->isConnectedToSpotify())->toBeFalse();
});

it('validates custom app credentials', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/profile/spotify-app', ['spotify_client_id' => '', 'spotify_client_secret' => ''])
        ->assertSessionHasErrors(['spotify_client_id', 'spotify_client_secret']);
});

it('shares byok and import props with the profile page', function () {
    $user = User::factory()->withCustomSpotifyApp()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('spotifyApp.configured', true)
            ->where('spotifyApp.client_id', $user->spotify_client_id)
            ->where('spotifyApp.redirect_uri', 'http://localhost/auth/spotify/callback')
            ->where('importSummary.imported_plays', 0));
});

it('shares byok and import props with the disconnected dashboard', function () {
    $user = User::factory()->withCustomSpotifyApp()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('connected', false)
            ->where('spotifyApp.configured', true)
            ->where('importSummary.total_plays', 0));
});
