<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A stable, filesystem-safe identity for each application.
     *
     * The web-server config used to be named after the domain, which is both
     * mutable and not unique: two applications could claim the same domain and
     * silently overwrite each other's vhost, and a domain change orphaned the
     * old file. The name is what the user actually calls the site, so the
     * config is named after that instead — via a slug, because a name is free
     * text and a filename is not.
     *
     * Both columns are made unique. The name because the user asked for names
     * to identify a site, and the slug because two names can slug to the same
     * string ("My Blog" and "my-blog"), which would put two sites in one file.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        $this->backfill();

        Schema::table('applications', function (Blueprint $table) {
            $table->unique('name');
            $table->unique('slug');
        });
    }

    /**
     * Existing rows have no slug, and may well have duplicate names — nothing
     * stopped them until now. Both have to be resolved *before* the unique
     * indexes are added, or the migration fails on the first real server that
     * happens to have two sites called "Blog".
     */
    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('applications')->orderBy('id')->get(['id', 'name']) as $row) {
            $name = $this->unique((string) $row->name ?: 'application', $taken['name'] ?? []);
            $slug = $this->unique(Str::slug($name) ?: 'application', $taken['slug'] ?? []);

            $taken['name'][] = $name;
            $taken['slug'][] = $slug;

            DB::table('applications')
                ->where('id', $row->id)
                ->update(['name' => $name, 'slug' => $slug]);
        }
    }

    /**
     * @param  array<int, string>  $taken
     */
    private function unique(string $base, array $taken): string
    {
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
