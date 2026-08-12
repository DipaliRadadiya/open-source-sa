<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things the user has already said no to.
 *
 * Without this, every sync re-lists the same items forever. On the box this
 * was developed against that is twenty-one vhosts the panel can never adopt —
 * and a list where the same twenty-one lines appear every time is a list
 * nobody reads, which costs the feature the one thing it is for.
 *
 * Keyed on the natural key rather than a model id, because the whole point is
 * to remember a decision about something that has no row in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_ignores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('resource_type');
            $table->string('resource_key');

            // Why, in the user's words. A decision without a reason is one
            // nobody dares reverse six months later.
            $table->string('note')->nullable();

            $table->timestamps();

            // One decision per thing. Saying "ignore" twice is not two
            // decisions, and the lookup is by this pair on every run.
            $table->unique(['resource_type', 'resource_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_ignores');
    }
};
