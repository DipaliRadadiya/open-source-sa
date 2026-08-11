<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            // A backup taken automatically just before a restore overwrites
            // the application. It is the only way back from a restore that
            // turns out to be wrong, so it must never be deleted to make room
            // for a scheduled one — retention skips it entirely.
            $table->boolean('is_safety')->default(false)->index();
            $table->string('status', 16)->index();
            $table->json('manifest')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('reference')->nullable()->index();
            $table->string('log_key', 40)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status']);
            // This is the one table here that grows without bound — a row per
            // site per night, forever, since retention prunes the artefacts on
            // the destination rather than this history. A date-range query
            // over an unindexed `created_at` is a full scan that gets
            // measurably slower every night.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
