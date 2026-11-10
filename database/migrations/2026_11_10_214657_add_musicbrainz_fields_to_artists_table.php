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
        Schema::table('artists', function (Blueprint $table) {
            $table->string('musicbrainz_id', 36)->nullable()->after('genres');
            $table->timestamp('genres_synced_at')->nullable()->after('musicbrainz_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn(['musicbrainz_id', 'genres_synced_at']);
        });
    }
};
