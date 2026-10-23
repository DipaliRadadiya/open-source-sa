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

            // Per-instance limits, nullable with no default. Databases used to take
            // both from one server-wide config value, so every engine on the box got
            // the same 512m whether it was a Redis cache or the primary Postgres.
            // Sizes are the product here, so they belong on the row. Null means
            // "fall back to the configured default at render time": a default on a
            // constraint column is a value asserted retroactively about every
            // existing row, and the templates emit `cpus:` only when a value is set.
            //
            // Strings, not numbers: `cpu_limit` holds '0.5' and '1.5' (cores, as
            // Docker counts them) and `memory_limit` holds Docker's own shorthand
            // ('512m', '2g'). Both are handed to Compose as written, so they are
            // kept as written.
            $table->string('cpu_limit', 16)->nullable();
            $table->string('memory_limit', 20)->nullable();

            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_databases');
    }
};
