<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel-wide settings — a singleton, always one row keyed by `id = 1`.
     *
     * Read and written through `DB::table('settings')` rather than an Eloquent
     * model: there is one row, it is never listed, and nothing relates to it.
     *
     * The row is created lazily by whatever writes to it first
     * (`CentralTokenManager::enable()` inserts it when absent), so this
     * migration only has to guarantee the table shape.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            // The single central-management token, stored raw so an inbound
            // `Authorization: Bearer` header can be compared against it with
            // `hash_equals`. Null means central management is switched off,
            // which is what a fresh install does.
            $table->string('central_token', 64)->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
