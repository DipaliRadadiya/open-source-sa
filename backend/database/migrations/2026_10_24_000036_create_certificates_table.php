<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One certificate per application.
 *
 * Deliberately not a history table. A server block can present exactly one
 * certificate, so a second row would be a certificate that is not serving
 * anything — a record of the past that the UI would have to explain. Reissuing
 * updates this row; what it replaced is in the activity log, which is where
 * "what happened" belongs.
 *
 * `force_https` lives here rather than on `applications` for the same reason:
 * redirecting to HTTPS without a certificate takes the site offline, so the
 * flag has no meaning apart from the certificate it depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();

            // letsencrypt · custom · self_signed
            $table->string('type')->default('letsencrypt');

            // pending · issuing · active · failed
            $table->string('status')->default('pending');

            // The exact names on the certificate. Stored rather than derived
            // from the domains table because the two drift the moment someone
            // adds a domain: the certificate covers what it covered when it
            // was issued, and the UI has to be able to say "this new name is
            // not on the certificate yet".
            $table->json('domains');

            // Where the web server reads them from. Stored rather than built
            // from a convention because the three types put them in three
            // different places, and the vhost template should not have to know
            // which type issued the file it is pointing at.
            $table->string('certificate_path')->nullable();
            $table->string('private_key_path')->nullable();
            $table->string('chain_path')->nullable();

            // Only ever set for `custom`: an uploaded certificate is the one
            // case where the panel holds a private key it did not generate on
            // the box. Encrypted, and written to disk 0600 owned by root.
            $table->text('uploaded_private_key')->nullable();

            $table->boolean('force_https')->default(false);

            // Let's Encrypt renews itself through certbot's own timer, so this
            // is not a schedule — it is whether the panel keeps the renewal
            // configuration in place. A self-signed or uploaded certificate
            // cannot renew at all.
            $table->boolean('auto_renew')->default(true);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // What the web server is actually presenting, which may differ from disk.
            $table->timestamp('served_expires_at')->nullable();
            $table->timestamp('served_checked_at')->nullable();

            // A classified code, never raw certbot output — the same rule the
            // runtime installer follows. Rendered into a sentence in the
            // viewer's locale at read time.
            $table->string('reason')->nullable();
            $table->string('reference')->nullable();

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
