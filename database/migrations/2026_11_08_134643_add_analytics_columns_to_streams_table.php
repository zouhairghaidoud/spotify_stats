<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('spotify_track_id');
            $table->string('artist_id', 32)->nullable()->after('duration_ms')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropIndex(['artist_id']);
            $table->dropColumn(['duration_ms', 'artist_id']);
        });
    }
};
