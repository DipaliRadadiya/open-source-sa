<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_databases', function (Blueprint $table): void {
            $table->id();

            // The name the user gives it, and also the compose service alias other
            // containers reach it by on a shared network. Unique because a network
            // resolves one name to one address.
            $table->string('name');

            // `postgres`, `mysql`, `mariadb`, `mongodb`, `redis`, `valkey`. A string
            // rather than an enum column: the catalog of engines lives in config so
            // an operator can pin or add one without a migration, which an enum
            // would make impossible.
            $table->string('engine', 32);

            // The version key from that catalog — '17', '8.4' — not the image. The
            // image is looked up, so a pinned tag can be corrected in one place
            // without rewriting every row.
            $table->string('version', 32);

            // The loopback port the panel allocated. Unique, and shared with the
            // applications' range: `PortAllocator` reads both tables, or a database
            // and a site could be handed the same number.
            $table->unsignedInteger('port')->unique();

            // Credentials, encrypted as one document — the same cast and the same
            // two lessons as `registries` and `storage_destinations`. Nothing inside
            // may be pre-encrypted.
            $table->text('credentials');

            // The panel network it joins, so container sites can reach it by name.
            // Null means Docker's default bridge, where they cannot — a real answer
            // for a database only ever reached over the loopback port.
            $table->string('docker_network')->nullable();

            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_databases');
    }
};
