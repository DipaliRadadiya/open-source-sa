<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cronjobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The OS user the job runs as — canonical, always set. May be a
            // panel System User or a default/unmanaged account (root, www-data).
            $table->string('username')->index();
            // Soft-link to a panel System User when the target is one; null for
            // default/unmanaged users. Cascade so deleting the user cleans its jobs.
            $table->foreignId('system_user_id')->nullable()->constrained('system_users')->cascadeOnDelete();
            $table->string('command', 1000);
            $table->string('expression');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronjobs');
    }
};
