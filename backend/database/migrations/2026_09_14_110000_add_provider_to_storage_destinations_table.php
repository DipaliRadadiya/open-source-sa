<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a single-provider table into a multi-provider one.
 *
 * `storage_destinations` was S3-shaped in its columns: `endpoint`, `region`,
 * `bucket`, `access_key` and `secret_key` are required columns, which is the
 * right schema when S3 is the only thing that exists and the wrong one the
 * moment a destination can be an FTP host with no bucket and no region.
 *
 * The five columns become one encrypted `config` blob whose *shape depends on
 * the provider*, because the shapes genuinely do not overlap — a bucket is not
 * a hostname is not a Shared Drive id — and the alternative is a table where
 * every column is nullable and no row is describable without first knowing
 * which provider it is. That fact now has a column of its own.
 *
 * This is an additive migration, not an edit of the shipped `create`. Editing
 * a migration that has already run reaches fresh installs only: every existing
 * panel would keep the old table forever and no test could see the difference.
 */
return new class extends Migration
{
    /** The S3 columns being folded into `config` and then dropped. */
    private const LEGACY_COLUMNS = ['endpoint', 'region', 'bucket', 'access_key', 'secret_key'];

    /** Of those, the ones stored under the model's `encrypted` cast. */
    private const LEGACY_SECRETS = ['access_key', 'secret_key'];

    public function up(): void
    {
        Schema::table('storage_destinations', function (Blueprint $table): void {
            // Nullable for the length of this migration only — the backfill
            // below fills every row and the column is made NOT NULL at the
            // end. Adding it NOT NULL with a default would have meant the
            // default outliving the migration, and a silent 's3' default is
            // exactly the missing-provider bug this column exists to end.
            $table->string('provider', 32)->nullable()->after('name');

            // The per-provider credential + address set, encrypted as one
            // value (`encrypted:array` on the model). Nullable so the column
            // can be added before it is filled; a destination with a null
            // config is unusable and the driver factory says so rather than
            // guessing.
            $table->text('config')->nullable()->after('provider');
        });

        $this->foldLegacyColumnsIntoConfig();

        Schema::table('storage_destinations', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable(false)->change();

            // Indexed because the driver factory and every provider-filtered
            // listing reads it, and because it is the column a future
            // "show me my FTP destinations" filter sorts on.
            $table->index('provider');
        });

        Schema::table('storage_destinations', function (Blueprint $table): void {
            $table->dropColumn(self::LEGACY_COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('storage_destinations', function (Blueprint $table): void {
            // Restored nullable rather than with the original NOT NULL: rows
            // created for a non-S3 provider have no bucket and no keys to put
            // back, and a rollback that cannot represent its own data is not a
            // rollback. `endpoint`/`region` carried defaults originally and
            // regain them; the rest are genuinely absent for those rows.
            $table->string('endpoint')->nullable()->after('name');
            $table->string('region')->nullable()->default('us-east-1')->after('endpoint');
            $table->string('bucket')->nullable()->after('region');
            $table->text('access_key')->nullable()->after('prefix');
            $table->text('secret_key')->nullable()->after('access_key');
        });

        $this->unfoldConfigIntoLegacyColumns();

        Schema::table('storage_destinations', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'config']);
        });
    }

    /**
     * Every existing row is S3 — that is the only provider that has ever
     * existed — so the provider is known without inspecting anything.
     *
     * The two secret columns are under the model's `encrypted` cast, so their
     * stored form is ciphertext. They are decrypted here and re-encrypted as
     * part of the config blob: `encrypted:array` encrypts the *whole* JSON
     * document, so handing it ciphertext would double-encrypt the secrets and
     * every backup would fail authentication with a password made of
     * base64.
     */
    private function foldLegacyColumnsIntoConfig(): void
    {
        DB::table('storage_destinations')
            ->select(array_merge(['id'], self::LEGACY_COLUMNS))
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $config = [];

                    foreach (self::LEGACY_COLUMNS as $column) {
                        $value = $row->{$column};

                        $config[$column] = in_array($column, self::LEGACY_SECRETS, true)
                            ? $this->decryptOrNull($value, $row->id, $column)
                            : $value;
                    }

                    DB::table('storage_destinations')
                        ->where('id', $row->id)
                        ->update([
                            'provider' => 's3',
                            'config' => Crypt::encryptString(json_encode($config)),
                        ]);
                }
            });
    }

    /**
     * The reverse fold. Secrets go back through `Crypt::encryptString` because
     * that is the form the restored columns' `encrypted` cast expects to read.
     */
    private function unfoldConfigIntoLegacyColumns(): void
    {
        DB::table('storage_destinations')
            ->select(['id', 'provider', 'config'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    // A non-S3 destination has nothing to unfold into S3
                    // columns. Its config is dropped with the column and the
                    // row survives as an unusable husk rather than silently
                    // becoming a malformed S3 destination — the rollback is
                    // losing data either way and this way it is visible.
                    if ($row->provider !== 's3') {
                        continue;
                    }

                    $config = $this->decodeConfig($row->config, $row->id);

                    $update = [];

                    foreach (self::LEGACY_COLUMNS as $column) {
                        $value = $config[$column] ?? null;

                        $update[$column] = ($value !== null && in_array($column, self::LEGACY_SECRETS, true))
                            ? Crypt::encryptString((string) $value)
                            : $value;
                    }

                    DB::table('storage_destinations')->where('id', $row->id)->update($update);
                }
            });
    }

    /**
     * A value that will not decrypt is a value encrypted under a different
     * APP_KEY — a restored database, a rotated key, a hand-edited row. The
     * migration must not abort the whole upgrade over one unreadable
     * destination, and it must not write the ciphertext through as if it were
     * the plaintext: that would produce a destination whose "password" is a
     * base64 blob and whose failure appears at the next backup, far from here.
     * Null, loudly.
     */
    private function decryptOrNull(?string $value, int|string $id, string $column): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable $e) {
            Log::warning('Storage destination credential could not be decrypted during migration.', [
                'feature' => 'storage',
                'destination_id' => $id,
                'column' => $column,
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(?string $value, int|string $id): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
        } catch (Throwable $e) {
            Log::warning('Storage destination config could not be decrypted during rollback.', [
                'feature' => 'storage',
                'destination_id' => $id,
            ]);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
};
