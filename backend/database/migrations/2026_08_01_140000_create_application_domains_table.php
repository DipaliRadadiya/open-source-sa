<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every name an application answers to.
 *
 * `applications.domain` stays where it is and keeps meaning "the primary
 * domain" — the vhost filename, the log filenames and half the resource are
 * built from it. This table is the source of truth; that column is a mirror of
 * whichever row is primary, so nothing downstream had to change to gain
 * multi-domain support.
 *
 * Three kinds of name, because conflating them is an SEO trap Plesk documents:
 * an alias *serves* the same site under a second name, so search engines index
 * it as a separate site and split the ranking between them. A redirect sends a
 * 301 and keeps the authority in one place. The panel makes that an explicit
 * choice rather than a default nobody notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Unique across the whole server, not per application: two sites
            // claiming one hostname is a race decided by whichever vhost the
            // web server reads first, which is not a thing to leave to chance.
            $table->string('domain')->unique();

            // primary | alias | redirect
            $table->string('type')->default('alias');

            // Where a redirect sends visitors, and with which status. Null for
            // primary and alias rows.
            $table->string('redirect_to')->nullable();
            $table->unsignedSmallInteger('redirect_status')->default(301);

            // A panel-issued nip.io name, which needs no DNS at all. Flagged
            // because it must never be sent to Let's Encrypt: nip.io is not on
            // the Public Suffix List, so every certificate for it in the world
            // shares one rate limit.
            $table->boolean('is_test')->default(false);

            // When this name was last seen pointing at this server. Null means
            // unverified, and certificate issuance is gated on it — Let's
            // Encrypt allows five authorisation failures per hostname per hour,
            // so a wrong DNS record that is retried locks the user out for an
            // hour. Cheaper to look first.
            $table->timestamp('dns_verified_at')->nullable();

            // What the last check actually saw, so the UI can say "points at
            // 1.2.3.4, this server is 5.6.7.8" instead of "failed".
            $table->string('dns_resolved_ip')->nullable();

            // Whether that address belongs to Cloudflare. Proxied DNS breaks
            // HTTP-01 validation, and it is the single most common SSL support
            // question on panels of this kind — worth naming rather than
            // letting the user discover it through a failed issuance.
            $table->boolean('behind_proxy')->default(false);

            $table->timestamps();

            $table->index(['application_id', 'type']);
        });

        // Existing sites already answer to `domain` and `www.domain` — both
        // names are hardcoded in every vhost template. Backfilling both keeps
        // what is being served identical; without it, the first re-render
        // would quietly drop www from live sites.
        foreach (DB::table('applications')->select('id', 'domain', 'created_at')->get() as $application) {
            if (blank($application->domain)) {
                continue;
            }

            $rows = [[
                'application_id' => $application->id,
                'domain' => $application->domain,
                'type' => 'primary',
                'created_at' => $application->created_at,
                'updated_at' => now(),
            ]];

            // Only for names that can take a www prefix — a bare `nip.io`
            // style test host or an existing `www.` name must not gain one.
            if (! str_starts_with($application->domain, 'www.') && substr_count($application->domain, '.') === 1) {
                $rows[] = [
                    'application_id' => $application->id,
                    'domain' => 'www.'.$application->domain,
                    'type' => 'alias',
                    'created_at' => $application->created_at,
                    'updated_at' => now(),
                ];
            }

            DB::table('application_domains')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_domains');
    }
};
