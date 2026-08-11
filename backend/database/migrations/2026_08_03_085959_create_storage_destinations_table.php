<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('endpoint');
            $table->string('region')->default('us-east-1');
            $table->string('bucket');
            $table->string('prefix')->nullable();
            $table->text('access_key');
            $table->text('secret_key');

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
