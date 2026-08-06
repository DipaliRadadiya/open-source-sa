<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-site additions to the built-in AI bot list, in both directions —
     * the same one-table, two-types shape `application_waf_rules` uses:
     *
     * - `block`: a user agent this site blocks on top of whatever its policy
     *   already blocks. Not only AI crawlers — the SEO and scraper bots
     *   people actually complain about (SemrushBot, AhrefsBot, BLEXBot) fit
     *   here too.
     * - `allow`: a user agent from the built-in list this site does *not*
     *   want blocked. Without it, a site that needs one of the 23 training
     *   crawlers has to switch the whole policy off to get it back.
     *
     * The pair is unique per application: adding the same agent twice is a
     * no-op, not two identical alternations in the vhost regex.
     */
    public function up(): void
    {
        Schema::create('application_bot_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // block | allow
            $table->string('value');
            $table->timestamps();

            $table->unique(['application_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_bot_rules');
    }
};
