<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A snapshot of Spotify track metadata used for top tracks and recaps.
 *
 * @property int $id
 * @property string $spotify_track_id
 * @property string $name
 * @property string|null $artist_id
 * @property string|null $artist_name
 * @property string|null $album_name
 * @property string|null $album_image_url
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'spotify_track_id',
    'name',
    'artist_id',
    'artist_name',
    'album_name',
    'album_image_url',
    'duration_ms',
])]
class Track extends Model {}
