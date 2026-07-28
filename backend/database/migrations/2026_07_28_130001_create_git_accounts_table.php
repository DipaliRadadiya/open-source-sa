<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connected git provider accounts (github | gitlab | bitbucket). Panel
     * scoped, not server state: the credential is stored so applications can
     * later clone private repositories. The token is encrypted at rest and is
     * never returned by the API.
     *
     * `identifier` is what the provider calls the account — a username for
     * GitHub/GitLab, the workspace slug for Bitbucket (its scoped access
     * tokens authenticate as the token, not as a user). It is fetched from
     * the provider during verification, never typed by the user.
     */
    public function up(): void
    {
        Schema::create('git_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // github | gitlab | bitbucket
            $table->string('label')->unique(); // the user's name for this connection
            $table->string('identifier')->nullable(); // username / workspace slug, from the provider
            $table->text('token'); // encrypted cast
            $table->string('host')->nullable(); // self-hosted GitLab base URL
            $table->string('workspace')->nullable(); // Bitbucket workspace slug
            $table->json('scopes')->nullable(); // as reported by the provider
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_accounts');
    }
};
