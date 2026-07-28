<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel-tracked databases. The server is the source of truth for
     * existence (detect-don't-trust); this table mirrors it + links a DB to
     * an application when one owns it. `charset`/`collation` are null for
     * MongoDB. `size_bytes` is a cached figure refreshed on read.
     */
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('engine'); // mysql | mariadb | mongodb
            $table->string('charset')->nullable();
            $table->string('collation')->nullable();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->unique(['name', 'engine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases');
    }
};
