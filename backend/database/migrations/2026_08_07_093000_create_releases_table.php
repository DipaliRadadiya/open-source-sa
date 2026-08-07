<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // absolute filesystem path to this release
            $table->string('commit_hash', 40)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->string('status', 20)->default('active'); // active | rolled_back | archived
            $table->text('output')->nullable(); // deployment script output
            $table->timestamps();

            $table->index(['application_id', 'status']);
            $table->index(['application_id', 'deployed_at']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('current_release_id')->nullable()->after('directory_size_bytes')->constrained('releases')->nullOnDelete();
            $table->string('previous_release_path')->nullable()->after('current_release_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['current_release_id']);
            $table->dropColumn(['current_release_id', 'previous_release_path']);
        });

        Schema::dropIfExists('releases');
    }
};
