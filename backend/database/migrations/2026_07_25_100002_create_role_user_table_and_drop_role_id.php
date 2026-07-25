<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move from one-role-per-user (`users.role_id`) to many-roles-per-user
     * (a `role_user` pivot). Existing single assignments are migrated into
     * the pivot before the column is dropped.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        $now = now();
        DB::table('users')->whereNotNull('role_id')->orderBy('id')->each(function ($user) use ($now) {
            DB::table('role_user')->insert([
                'user_id' => $user->id,
                'role_id' => $user->role_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('is_admin')->constrained('roles')->nullOnDelete();
        });

        // Restore a single role per user (the first one) for reversibility.
        DB::table('role_user')->orderBy('user_id')->each(function ($pivot) {
            DB::table('users')
                ->where('id', $pivot->user_id)
                ->whereNull('role_id')
                ->update(['role_id' => $pivot->role_id]);
        });

        Schema::dropIfExists('role_user');
    }
};
