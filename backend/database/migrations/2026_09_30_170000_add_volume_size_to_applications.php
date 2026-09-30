<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a site's size is in its Docker volumes.
 *
 * `directory_size_bytes` becomes the site's TOTAL — document root plus volumes —
 * because it is the figure the sites list shows and sorts by, and for a container
 * site the old value was meaningless: measured on the test box, a Mattermost
 * install reported 6,468 bytes while its six volumes held about 284 MB. Sorting a
 * list of container sites by it ordered them by the length of their compose files.
 *
 * This column exists so the total is explainable. A combined number with no
 * breakdown invites "why is my compose file 284 MB", and the answer has to be on
 * the screen rather than in a support reply.
 *
 * Null, not zero, and the distinction carries meaning: null is "this site has no
 * volumes to measure" — every PHP, Node and static site — while 0 is a container
 * site whose volumes are genuinely empty. Rendering "0 B in volumes" on a
 * WordPress site would be answering a question nobody asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('volume_size_bytes')->nullable()->after('directory_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('volume_size_bytes');
        });
    }
};
