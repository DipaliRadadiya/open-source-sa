<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A database user belongs to exactly ONE database (ServerAvatar's model —
     * one-to-many, no cross-db grants). `connection_preference` drives remote
     * access: `localhost` (local only), `remote` (a specific `host` IP/CIDR,
     * firewall opened), `anywhere` (`%`). `password` is encrypted at rest but
     * decryptable so the panel can show the connection string.
     */
    public function up(): void
    {
        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->string('username');
            $table->text('password'); // encrypted cast
            $table->string('connection_preference')->default('localhost'); // localhost | remote | anywhere
            $table->string('host')->default('localhost');
            $table->timestamps();

            $table->unique(['database_id', 'username', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_users');
    }
};
