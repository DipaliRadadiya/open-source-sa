<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per time this panel was connected to ServerAvatar Central.
        //
        // Rows are kept after disconnecting rather than deleted: the row is
        // the record of consent — who allowed it, when, and when they took it
        // back. The token itself is gone the moment `revoked_at` is written.
        Schema::create('central_connections', function (Blueprint $table) {
            $table->id();

            // The token this connection issued, so disconnecting revokes
            // exactly the one it created. Nullable because the token row is
            // deleted on disconnect, and because a manually deleted token
            // must not take the consent record with it.
            $table->foreignId('token_id')->nullable();

            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at');

            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_connections');
    }
};
