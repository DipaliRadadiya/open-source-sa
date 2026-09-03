<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every application a primary row in `application_domains`.
     *
     * The Domains screen reads that table; `applications.domain` is only the
     * mirror of whichever row is primary. `CreateApplication` writes the row,
     * but `CloneManager` and `StagingManager` build their application record
     * directly and never did — so every cloned and staging site created before
     * this migration serves its domain with an empty Domains section.
     *
     * Both managers write the row now. This is for the sites that already
     * exist, because nothing else would ever give them one.
     *
     * It is not only cosmetic, which is why it is worth a migration rather
     * than leaving them to be recreated. `Application::serverNames()` falls
     * back to `applications.domain` *only while the relation is empty*, so
     * adding a single alias to one of these sites made the relation non-empty
     * without the primary in it — and the vhost written next dropped the
     * site's own domain.
     */
    public function up(): void
    {
        $missing = DB::table('applications')
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('application_domains')
                ->whereColumn('application_domains.application_id', 'applications.id'))
            ->get(['id', 'domain']);

        $now = now();

        $suffixes = (array) config('server.temporary_domain_suffixes', ['nip.io', 'sslip.io']);

        $temporary = function (string $domain) use ($suffixes): bool {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($domain, '.'.mb_strtolower((string) $suffix))) {
                    return true;
                }
            }

            return false;
        };

        foreach ($missing as $application) {
            $domain = mb_strtolower(trim((string) $application->domain));

            // A domain already claimed by another application cannot be
            // inserted — the column is unique — and that is a different fault
            // from this one. Skipped rather than failing the migration: a
            // deploy that stops here leaves every server unable to migrate,
            // and the site is no worse off than it was a moment ago.
            $taken = DB::table('application_domains')->where('domain', $domain)->exists();

            if ($taken) {
                continue;
            }

            DB::table('application_domains')->insert([
                'application_id' => $application->id,
                'domain' => $domain,
                'type' => 'primary',
                // The same rule as ApplicationDomain::looksTemporary(), read
                // from the same config rather than restated: a wider pattern
                // here would mark ordinary `.test` or `.local` names as
                // temporary, and the certificate actions filter on this flag —
                // so an invented rule would quietly refuse those sites a
                // certificate they are entitled to.
                'is_test' => (int) $temporary($domain),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deliberately nothing.
     *
     * The inserted rows are indistinguishable from ones a user created, and by
     * the time anyone rolls back they may have been edited, made primary over
     * something else, or had a certificate issued against them. Deleting them
     * would take a working Domains section away to undo a fix, and the state
     * they replaced — no row at all — is the bug.
     */
    public function down(): void {}
};
