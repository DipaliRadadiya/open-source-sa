<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ChunkedUpload;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Fake server state. Static for the same reason the other file-manager fakes
 * are: writes made inside an HTTP request do not reliably travel back to the
 * test through Pest's `test()` proxy.
 */
class ChunkedUploadFake
{
    /** @var array<int, string> every command the panel ran */
    public static array $ran = [];

    /** @var array<string, int> part-file path => bytes currently "on disk" */
    public static array $sizes = [];

    /** Free space reported by `df`, in 1K blocks. */
    public static int $availableBlocks = 200 * 1024 * 1024;

    /** Total size reported by `df`, in 1K blocks. */
    public static int $totalBlocks = 300 * 1024 * 1024;

    /** Whether the destination directory exists. */
    public static bool $directoryExists = true;

    public static function reset(): void
    {
        self::$ran = [];
        self::$sizes = [];
        self::$availableBlocks = 200 * 1024 * 1024;
        self::$totalBlocks = 300 * 1024 * 1024;
        self::$directoryExists = true;
    }

    /** @return array<int, string> */
    public static function binaries(): array
    {
        return array_map(fn (string $line) => explode(' ', $line)[0], self::$ran);
    }
}

/*
 * What matters about chunked upload is not that bytes arrive — it is the
 * handful of properties the design leans on and would fail silently without:
 *
 *  - the part file is assembled inside the site's own tree, so finalising is
 *    a rename and never a copy (the whole reason large uploads do not thrash
 *    a shared box's page cache),
 *  - the server's own byte count is authoritative, so a retried chunk cannot
 *    quietly corrupt the file,
 *  - the upload id can never become a path,
 *  - and the free-space guard actually refuses, because the alternative is
 *    filling a disk that every hosted site shares.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    ChunkedUploadFake::reset();
    fakeUploadServer();
});

function fakeUploadServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        ChunkedUploadFake::$ran[] = implode(' ', $args);

        // Every command is wrapped as `runuser -u siteowner --`; unwrap to see
        // what was actually asked for.
        $inner = ($args[0] ?? null) === 'runuser' ? array_slice($args, 4) : $args;
        $binary = $inner[0] ?? null;

        if ($binary === 'df') {
            return Process::result(output: implode("\n", [
                'Filesystem 1024-blocks Used Available Capacity Mounted on',
                sprintf(
                    '/dev/sda1 %d %d %d 13%% /',
                    ChunkedUploadFake::$totalBlocks,
                    ChunkedUploadFake::$totalBlocks - ChunkedUploadFake::$availableBlocks,
                    ChunkedUploadFake::$availableBlocks,
                ),
            ]));
        }

        if ($binary === 'test' && ($inner[1] ?? '') === '-d') {
            return Process::result(exitCode: ChunkedUploadFake::$directoryExists ? 0 : 1);
        }

        if ($binary === 'touch') {
            ChunkedUploadFake::$sizes[$inner[1]] = 0;

            return Process::result();
        }

        if ($binary === 'tee') {
            $target = $inner[2];
            // The chunk is piped as a stream; its length is what lands.
            $written = is_resource($process->input)
                ? strlen((string) stream_get_contents($process->input))
                : strlen((string) $process->input);

            ChunkedUploadFake::$sizes[$target] = (ChunkedUploadFake::$sizes[$target] ?? 0) + $written;

            return Process::result();
        }

        if ($binary === 'stat') {
            $target = $inner[3];

            return array_key_exists($target, ChunkedUploadFake::$sizes)
                ? Process::result(output: (string) ChunkedUploadFake::$sizes[$target])
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory');
        }

        if ($binary === 'mv') {
            $from = $inner[2];
            $to = $inner[3];
            ChunkedUploadFake::$sizes[$to] = ChunkedUploadFake::$sizes[$from] ?? 0;
            unset(ChunkedUploadFake::$sizes[$from]);

            return Process::result();
        }

        if ($binary === 'rm') {
            unset(ChunkedUploadFake::$sizes[$inner[2]]);

            return Process::result();
        }

        return Process::result();
    });
}

function uploadsUrl(): string
{
    return '/api/applications/'.test()->application->id.'/files/uploads';
}

it('opens an upload and hands back an id that is not client-chosen', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertOk();

    expect($response->json('upload_id'))->toMatch(ChunkedUpload::ID_PATTERN);
});

it('assembles the part file inside the site tree so finalising is a rename', function () {
    $id = $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->json('upload_id');

    // The part file must sit under the document root, not in panel storage:
    // a cross-filesystem move would copy every byte instead of renaming.
    expect(ChunkedUploadFake::$ran)->toContain(
        "runuser -u siteowner -- touch /home/siteowner/shop/public_html/.panel/uploads/{$id}.part"
    );
});

it('reports the running total from the server, not the client', function () {
    $id = $this->actingAs($this->admin)->postJson(uploadsUrl(), ['path' => 'big.zip'])->json('upload_id');

    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl()."/{$id}", [], [], [], [], str_repeat('a', 1024))
        ->assertOk();

    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl()."/{$id}", [], [], [], [], str_repeat('b', 512))
        ->assertOk()
        ->assertJson(['received' => 1536]);
});

it('lets an interrupted client resync to what actually landed', function () {
    $id = $this->actingAs($this->admin)->postJson(uploadsUrl(), ['path' => 'big.zip'])->json('upload_id');

    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl()."/{$id}", [], [], [], [], str_repeat('a', 2048));

    $this->actingAs($this->admin)
        ->getJson(uploadsUrl()."/{$id}")
        ->assertOk()
        ->assertJson(['received' => 2048]);
});

it('moves the completed upload into place with a rename', function () {
    $id = $this->actingAs($this->admin)->postJson(uploadsUrl(), ['path' => 'big.zip'])->json('upload_id');

    $this->actingAs($this->admin)->call('PUT', uploadsUrl()."/{$id}", [], [], [], [], 'payload');

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl()."/{$id}/finalize", ['upload_id' => $id, 'path' => 'big.zip'])
        ->assertOk();

    expect(ChunkedUploadFake::$ran)->toContain(
        "runuser -u siteowner -- mv -f /home/siteowner/shop/public_html/.panel/uploads/{$id}.part /home/siteowner/shop/public_html/big.zip"
    );

    // A rename, so no copy ever ran.
    expect(ChunkedUploadFake::binaries())->not->toContain('cp');
});

it('refuses an upload id that could become a path', function () {
    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl().'/..%2f..%2fetc%2fpasswd', [], [], [], [], 'x')
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson(uploadsUrl().'/not-a-valid-id')
        ->assertNotFound();
});

it('refuses to write when the shared disk is close to full', function () {
    $id = $this->actingAs($this->admin)->postJson(uploadsUrl(), ['path' => 'big.zip'])->json('upload_id');

    // Under the 10% floor of a 300 GB filesystem.
    ChunkedUploadFake::$availableBlocks = 1024 * 1024;

    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl()."/{$id}", [], [], [], [], 'x')
        ->assertStatus(507);
});

it('refuses to open an upload into a directory that does not exist', function () {
    ChunkedUploadFake::$directoryExists = false;

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'nope/big.zip'])
        ->assertStatus(422);
});

it('rejects a path that escapes the site', function () {
    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => '../../etc/passwd'])
        ->assertStatus(422);
});

it('discards the part file on abort', function () {
    $id = $this->actingAs($this->admin)->postJson(uploadsUrl(), ['path' => 'big.zip'])->json('upload_id');

    $this->actingAs($this->admin)
        ->deleteJson(uploadsUrl()."/{$id}")
        ->assertOk();

    expect(ChunkedUploadFake::$ran)->toContain(
        "runuser -u siteowner -- rm -f /home/siteowner/shop/public_html/.panel/uploads/{$id}.part"
    );
});

it('denies a user without manage rights', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertForbidden();
});
