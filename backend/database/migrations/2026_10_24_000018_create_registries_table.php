<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // The registry's address as Docker knows it, which is also the key
            // its config.json is written under. `docker.io` for Hub, `ghcr.io`,
            // `registry.example.com:5000` for a self-hosted one. Stored as the
            // user typed it and normalised when written, not here: the column
            // has to round-trip what the form showed them.
            $table->string('registry');

            $table->string('username');

            // The credential set, encrypted as ONE value by the model's
            // `encrypted:array` cast — the pattern `storage_destinations`
            // already uses for S3 keys and Drive tokens. `text`, not `string`:
            // a PAT is long and the ciphertext is longer still.
            //
            // Nothing inside it may be pre-encrypted. A field encrypted within
            // an already-encrypted document is encrypted twice, and every login
            // then fails with a password made of base64 — a mistake already
            // paid for once on StorageDestination.
            $table->text('config');

            // The connection probe's verdict, persisted for the same reason the
            // storage one is: without it the Test button's answer lives only in
            // the tab that pressed it, and the panel cannot tell a registry it
            // has never spoken to from one it has confirmed works.
            //
            // Nullable `last_test_success` on purpose — null is "never asked",
            // which is a different answer from "asked and was refused".
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_success')->nullable();

            // A stable category key ('invalid_credentials' | 'unreachable'),
            // never the raw output: Docker's own refusal text quotes the
            // repository and sometimes the username back at you.
            $table->string('last_test_error', 32)->nullable();

            $table->timestamps();

            // One row per name, so a picker never shows two identical options.
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registries');
    }
};
