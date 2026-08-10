<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The old CentralConnection model used Sanctum tokens. The new design
        // stores a single raw token in settings.central_token. The table is
        // dead code on fresh installs and has no data on existing installs.
        Schema::dropIfExists('central_connections');
    }

    public function down(): void
    {
        // Not needed — this table should never exist with the new design.
    }
};
