<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\SpotifyService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $spotify_id
 * @property string|null $spotify_avatar
 * @property string|null $spotify_access_token
 * @property string|null $spotify_refresh_token
 * @property Carbon|null $spotify_token_expires_at
 * @property string|null $spotify_scopes
 * @property string|null $spotify_client_id
 * @property string|null $spotify_client_secret
 * @property string|null $recap_token
 * @property string|null $recap_timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'spotify_access_token', 'spotify_refresh_token', 'spotify_client_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Determine if the user has linked their Spotify account.
     */
    public function isConnectedToSpotify(): bool
    {
        return filled($this->spotify_refresh_token);
    }

    /**
     * Determine if the user supplied their own Spotify developer app.
     */
    public function hasCustomSpotifyApp(): bool
    {
        return filled($this->spotify_client_id) && filled($this->spotify_client_secret);
    }

    /**
     * Determine if the user granted access to create private playlists.
     */
    public function hasPlaylistScope(): bool
    {
        return $this->isConnectedToSpotify()
            && str_contains((string) $this->spotify_scopes, SpotifyService::PLAYLIST_SCOPE);
    }

    /**
     * The persisted listening history for the user.
     *
     * @return HasMany<Stream, $this>
     */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'spotify_access_token' => 'encrypted',
            'spotify_refresh_token' => 'encrypted',
            'spotify_client_secret' => 'encrypted',
            'spotify_token_expires_at' => 'datetime',
        ];
    }
}
