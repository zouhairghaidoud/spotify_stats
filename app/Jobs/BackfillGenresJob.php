<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GenreBackfillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillGenresJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public User $user,
        public int $limit = 50,
        public bool $force = false,
    ) {}

    public function handle(GenreBackfillService $genres): void
    {
        $genres->backfill($this->user, $this->limit, $this->force);
    }
}
