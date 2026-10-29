<?php

use App\Models\GitAccount;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\CentralTokenManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
 * Dipali, 2026-10-28: the central panel (and the panel's own screens) adding
 * a git account or a storage destination whose credential is already here
 * gets that record back — no second one. Any difference in the credential is
 * a new record, and an existing record is never changed.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octocat'], 200, ['x-oauth-scopes' => 'repo']),
        'gitlab.com/api/v4/user' => Http::response(['username' => 'dev'], 200),
    ]);
});

function dupGit(array $overrides = []): TestResponse
{
    return test()->postJson('/api/integrations/git/accounts', array_merge([
        'provider' => 'github', 'label' => 'Work GitHub', 'token' => 'ghp_same_token',
    ], $overrides));
}

function dupS3(array $config = [], array $overrides = []): TestResponse
{
    return test()->postJson('/api/integrations/storage/destinations', array_merge([
        'name' => 'Daily backups', 'provider' => 's3', 'prefix' => 'site/',
        'config' => array_merge(['bucket' => 'my-backups', 'access_key' => 'AKIASAME', 'secret_key' => 'secret-same'], $config),
    ], $overrides));
}

describe('git accounts', function () {

    it('returns the account that already holds this token, and makes no second one', function () {
        $first = dupGit()->assertCreated()->assertJsonPath('existing', false)->json('git_account.id');

        dupGit(['label' => 'From central'])
            ->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('git_account.id', $first)
            // As it was: the second request's label changes nothing.
            ->assertJsonPath('git_account.label', 'Work GitHub')
            ->assertJsonPath('message', "This git account is already connected as 'Work GitHub'.");

        expect(GitAccount::count())->toBe(1);
    });

    it('returns it even when the label is the same, instead of "already taken"', function () {
        $first = dupGit()->assertCreated()->json('git_account.id');

        dupGit()->assertOk()->assertJsonPath('git_account.id', $first)->assertJsonPath('existing', true);
    });

    it('does not ask the provider again for an account it already has', function () {
        dupGit()->assertCreated();
        Http::fake(['*' => Http::response([], 500)]);

        dupGit(['label' => 'Again'])->assertOk()->assertJsonPath('existing', true);
    });

    it('makes a new account for a new token, and leaves the old one alone', function () {
        $first = dupGit()->assertCreated()->json('git_account.id');

        dupGit(['label' => 'Second token', 'token' => 'ghp_other_token'])
            ->assertCreated()
            ->assertJsonPath('existing', false);

        expect(GitAccount::count())->toBe(2)
            ->and(GitAccount::find($first)->token)->toBe('ghp_same_token');
    });

    it('still refuses a label that is taken by a different credential', function () {
        dupGit()->assertCreated();

        dupGit(['token' => 'ghp_other_token'])->assertStatus(422)->assertJsonValidationErrors('label');
    });

    it('treats the same token on another provider or host as a different account', function () {
        dupGit(['provider' => 'gitlab', 'label' => 'GitLab'])->assertCreated();

        // gitlab.com written as nothing and as a URL is one host…
        dupGit(['provider' => 'gitlab', 'label' => 'GitLab again', 'host' => 'https://gitlab.com/'])->assertOk()->assertJsonPath('existing', true);

        // …and the same token on GitHub is another account.
        dupGit(['provider' => 'github', 'label' => 'GitHub'])->assertCreated()->assertJsonPath('existing', false);

        expect(GitAccount::count())->toBe(2);
    });

    it('never puts the token in the response', function () {
        dupGit()->assertCreated();

        expect(dupGit(['label' => 'x'])->getContent())->not->toContain('ghp_same_token');
    });
});

describe('storage destinations', function () {

    it('returns the destination that already holds this credential', function () {
        $first = dupS3()->assertCreated()->assertJsonPath('existing', false)->json('storage_destination.id');

        dupS3([], ['name' => 'From central'])
            ->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('storage_destination.id', $first)
            ->assertJsonPath('storage_destination.name', 'Daily backups')
            ->assertJsonPath('message', "This storage is already added as 'Daily backups'.");

        expect(StorageDestination::count())->toBe(1);
    });

    it('compares a blank region as the us-east-1 it is saved as, and a prefix with or without its slash', function () {
        $first = dupS3()->assertCreated()->json('storage_destination.id');

        dupS3(['region' => 'us-east-1'], ['name' => 'Again', 'prefix' => '/site'])
            ->assertOk()->assertJsonPath('storage_destination.id', $first);
    });

    it('makes a new destination for any difference in the credential', function (array $config, array $overrides) {
        dupS3()->assertCreated();

        dupS3($config, array_merge(['name' => 'Other'], $overrides))->assertCreated()->assertJsonPath('existing', false);

        expect(StorageDestination::count())->toBe(2);
    })->with([
        'new secret key' => [['secret_key' => 'secret-new'], []],
        'new access key' => [['access_key' => 'AKIANEW'], []],
        'other bucket' => [['bucket' => 'other-bucket'], []],
        'other region' => [['region' => 'eu-west-1'], []],
        'other prefix' => [[], ['prefix' => 'another/']],
    ]);

    it('still refuses a name that is taken by a different credential', function () {
        dupS3()->assertCreated();

        dupS3(['secret_key' => 'secret-new'])->assertStatus(422)->assertJsonValidationErrors('name')
            // Support's wording (2026-10-29): says what to do, not only what is wrong.
            ->assertJsonPath('errors.name.0', 'This name is already taken. Please choose a different name.');
    });

    it('matches FTP by host, port, user, folder and password', function () {
        $ftp = fn (array $c = [], string $name = 'FTP') => test()->postJson('/api/integrations/storage/destinations', [
            'name' => $name, 'provider' => 'ftp',
            'config' => array_merge(['host' => 'backup.example.com', 'username' => 'backups', 'password' => 'pw', 'root' => '/b'], $c),
        ]);
        $first = $ftp()->assertCreated()->json('storage_destination.id');

        $ftp([], 'FTP again')->assertOk()->assertJsonPath('storage_destination.id', $first);
        $ftp(['password' => 'other'], 'FTP new password')->assertCreated();
        $ftp(['root' => '/c'], 'FTP other folder')->assertCreated();

        expect(StorageDestination::count())->toBe(3);
    });

    it('returns a local folder that is already a destination rather than refusing it', function () {
        $base = storage_path('framework/testing/dup-local-'.getmypid());
        File::deleteDirectory($base);
        File::ensureDirectoryExists("{$base}/backups");
        config(['server.backups.local.allowed_roots' => ["{$base}/backups"]]);
        Process::fake(fn ($p) => Process::result(output: ((array) $p->command)[0] === 'stat' ? get_current_user().'|directory' : ''));
        $first = StorageDestination::create(['name' => 'On this server', 'provider' => 'local', 'config' => ['path' => "{$base}/backups/panel"]]);

        $this->postJson('/api/integrations/storage/destinations', ['name' => 'Local again', 'provider' => 'local', 'config' => ['path' => "{$base}/backups/panel/"]])
            ->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('storage_destination.id', $first->id);

        File::deleteDirectory($base);
    });

    it('never puts a secret in the response', function () {
        dupS3()->assertCreated();

        expect(dupS3([], ['name' => 'x'])->getContent())->not->toContain('secret-same');
    });

    it('does not count a record it cannot decrypt as the same', function () {
        dupS3()->assertCreated();
        DB::table('storage_destinations')->update(['config' => 'not-encrypted']);

        dupS3([], ['name' => 'Fresh'])->assertCreated()->assertJsonPath('existing', false);
    });
});

describe('the central panel', function () {

    it('gets the same answer with its token as the panel does', function () {
        auth()->forgetGuards();
        $token = app(CentralTokenManager::class)->enable()['central_token'];
        $central = ['Authorization' => 'Bearer '.$token];

        $git = $this->withHeaders($central)->postJson('/api/integrations/git/accounts', ['provider' => 'github', 'label' => 'Central', 'token' => 'ghp_same_token'])->assertCreated()->json('git_account.id');
        $this->withHeaders($central)->postJson('/api/integrations/git/accounts', ['provider' => 'github', 'label' => 'Central retry', 'token' => 'ghp_same_token'])
            ->assertOk()->assertJsonPath('git_account.id', $git)->assertJsonPath('existing', true);

        $s3 = $this->withHeaders($central)->postJson('/api/integrations/storage/destinations', ['name' => 'Central S3', 'provider' => 's3', 'config' => ['bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']])->assertCreated()->json('storage_destination.id');
        $this->withHeaders($central)->postJson('/api/integrations/storage/destinations', ['name' => 'Central S3 retry', 'provider' => 's3', 'config' => ['bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']])
            ->assertOk()->assertJsonPath('storage_destination.id', $s3)->assertJsonPath('existing', true);

        expect(GitAccount::count())->toBe(1)->and(StorageDestination::count())->toBe(1);
    });
});

it('refuses a user who can only view, before saying anything about what exists', function () {
    dupS3()->assertCreated();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'storage');
    $this->actingAs($viewer);

    dupS3([], ['name' => 'x'])->assertForbidden()->assertJsonMissingPath('storage_destination');
});
