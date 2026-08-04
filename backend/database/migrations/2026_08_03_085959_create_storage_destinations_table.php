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
            $table->timestamps();
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_destinations');
    }
};
