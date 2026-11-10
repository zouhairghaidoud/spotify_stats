<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A queued (or finished) import of a Spotify history export.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property string $original_name
 * @property string $stored_path
 * @property int $imported
 * @property int $skipped
 * @property int $files
 * @property int $tracks
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'status',
    'original_name',
    'stored_path',
    'imported',
    'skipped',
    'files',
    'tracks',
    'error',
    'started_at',
    'finished_at',
])]
class HistoryImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported' => 'integer',
            'skipped' => 'integer',
            'files' => 'integer',
            'tracks' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The user who owns the import.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
