<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single playback reported by Spotify's recently played endpoint.
 *
 * @property int $id
 * @property int $user_id
 * @property string $spotify_track_id
 * @property int|null $duration_ms
 * @property string|null $artist_id
 * @property Carbon $played_at
 * @property string|null $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'spotify_track_id', 'duration_ms', 'artist_id', 'played_at', 'context'])]
class Stream extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
        ];
    }

    /**
     * The user who listened to the track.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
