<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per deploy.
 *
 * Until now a deploy left two facts on the application — the last commit and
 * the time — so running it twice erased the first. When someone asks "what
 * changed this afternoon and why did it break?", there was nothing to look at.
 *
 * Deliberately bounded: a site with auto-deploy on a busy repository deploys
 * dozens of times a day, and each row carries command output. Unpruned, this is
 * the table that quietly fills a self-hosted SQLite database — so the newest N
 * per application are kept and the rest are deleted as they are written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Who asked for it. Null for a webhook — nobody pressed anything,
            // and inventing an actor would be a lie the activity log already
            // refuses to tell.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // manual · webhook · redeploy
            $table->string('trigger')->default('manual');

            // queued · running · succeeded · failed
            $table->string('status')->default('queued');

            $table->string('branch')->nullable();

            // What was actually deployed. Read back off the checkout rather
            // than taken from the request: the branch tip moves, and the commit
            // recorded has to be the one on disk.
            $table->string('commit_hash')->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author')->nullable();

            // Which steps ran, in order, and which one stopped it.
            $table->json('steps')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('reference')->nullable();

            // The command output, redacted. The single most useful thing on the
            // screen — "build failed" without it is an invitation to guess.
            //
            // Scrubbed before it is written: a failed clone can echo a URL
            // carrying a token, and package managers print registry
            // credentials. Everything else here keeps secrets out of logs;
            // storing raw output would undo that in one column.
            $table->longText('output')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // The list is always "this application, newest first".
            $table->index(['application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
