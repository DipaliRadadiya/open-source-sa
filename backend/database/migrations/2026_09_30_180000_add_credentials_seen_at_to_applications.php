<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether anybody has confirmed they saved this site's generated credentials.
 *
 * A one-click container app generates its own admin password — Grafana's is the
 * clearest case — and until now the only way to it was a Reveal button on the
 * Container screen, two clicks into a site, which nobody thinks to look for on the
 * day they install something. A password generated and never shown is the same as
 * an account with no password anybody can use.
 *
 * **Set by an explicit acknowledgement, never by rendering the credentials.** If
 * displaying them marked them seen, a page refresh before somebody finished copying
 * would hide the card for good — and the values are unrotatable, because rewriting
 * one means changing it inside the running database too. So the column records "a
 * human said they have these", which is the only claim worth storing.
 *
 * Null forever on every site that generates nothing, which is every PHP, Node and
 * static site: those ask for an admin password on the create form, so the user
 * already has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->timestamp('credentials_seen_at')->nullable()->after('docker_secrets');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('credentials_seen_at');
        });
    }
};
