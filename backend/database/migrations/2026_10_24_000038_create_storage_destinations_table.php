<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place backups are written to, for any supported provider.
 *
 * What identifies and addresses a destination lives in one encrypted `config`
 * blob whose *shape depends on the provider*, because the shapes genuinely do
 * not overlap — a bucket is not a hostname is not a Shared Drive id — and the
 * alternative is a table where every column is nullable and no row is
 * describable without first knowing which provider it is. That fact has a
 * column of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // Indexed because the driver factory and every provider-filtered
            // listing reads it, and because it is the column a future
            // "show me my FTP destinations" filter sorts on.
            $table->string('provider', 32)->index();

            // The per-provider credential + address set, encrypted as one
            // value (`encrypted:array` on the model). Nullable; a destination
            // with a null config is unusable and the driver factory says so
            // rather than guessing.
            $table->text('config')->nullable();

            $table->string('prefix')->nullable();

            // The outcome of the connection probe. Without persisting it the
            // Test button's verdict lives only in the browser tab that pressed
            // it, and the panel can no longer tell a destination it has never
            // spoken to from one it has confirmed works — which is exactly the
            // question someone setting up backups is asking.
            //
            // `last_test_success` is nullable on purpose: null means never
            // tested, which is a different answer from "tested and failed".
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_success')->nullable();
            // The prober's stable category key ('invalid_credentials' |
            // 'unreachable'), never the raw SDK message — that text can carry
            // a partial access key and a bucket URL.
            $table->string('last_test_error', 32)->nullable();

            $table->timestamps();
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_destinations');
    }
};
