<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backup on SFTP or FTP, copied to the panel so the browser can download it.
 *
 * Those providers have no link a browser can follow, so Download used to
 * answer "not available for this destination". The panel now fetches the
 * archive on the queue and serves the local copy for an hour.
 *
 * One row per backup (unique): a second click while one is being prepared, or
 * is ready, gets the same copy rather than a second download of the archive.
 * `reference` names the copy's directory and is what support asks for when
 * one fails. Cascades with the backup; the copy on disk is removed by the
 * backup's `deleting` hook and, for anything that slips past it, by
 * `backups:prune-downloads`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_download_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->string('status', 16); // preparing | ready | failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('reason', 64)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_download_preparations');
    }
};
