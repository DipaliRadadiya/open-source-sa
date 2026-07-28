<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-engine admin/maintenance connection the panel uses to run DDL.
     * Configurable + testable in the UI (never `.env`). One row per engine.
     * Fields are connection-type based: `tcp` uses host+port, `socket` uses
     * the socket path; `password` is encrypted at rest (null for socket/peer
     * auth).
     */
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            $table->string('engine')->unique(); // mysql | mariadb | mongodb
            $table->string('connection_type')->default('tcp'); // tcp | socket
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('socket')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted cast
            $table->json('options')->nullable(); // authSource / TLS etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};
