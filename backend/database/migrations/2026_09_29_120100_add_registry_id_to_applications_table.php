<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            // Which stored credential to pull this site's image with. Null is
            // the common case and means "anonymous", not "broken" — every
            // public image and all fifteen one-click apps stay null forever.
            //
            // `nullOnDelete` rather than restrict: deleting a registry must not
            // be blocked by a site that used it, and the site is still a site
            // without it — it fails at the next pull with a named reason, which
            // is a better outcome than an undeletable row. Cascading the delete
            // would remove the site, which is catastrophically wrong for a
            // credential change.
            $table->foreignId('registry_id')
                ->nullable()
                ->after('image')
                ->constrained('registries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('registry_id');
        });
    }
};
