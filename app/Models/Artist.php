<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A snapshot of Spotify artist metadata used for genre analytics.
 *
 * @property int $id
 * @property string $spotify_artist_id
 * @property string $name
 * @property array<int, string>|null $genres
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['spotify_artist_id', 'name', 'genres'])]
class Artist extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'genres' => 'array',
        ];
    }
}
