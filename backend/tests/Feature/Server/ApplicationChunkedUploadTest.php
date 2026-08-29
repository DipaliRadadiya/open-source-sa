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

    /**
     * Paths the site already has, for `test -e`.
     *
     * Empty by default: an upload landing on nothing is the ordinary case, and
     * every other test in this file assumes it.
     *
     * @var array<int, string>
     */
    public static array $existing = [];

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
        self::$existing = [];
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

        // An upload refuses to stand on an existing file, so what is already
        // there decides whether it is allowed to start at all.
        if ($binary === 'test' && ($inner[1] ?? '') === '-e') {
            return Process::result(
                exitCode: in_array($inner[2] ?? '', ChunkedUploadFake::$existing, true) ? 0 : 1,
            );
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

it('tells the client the largest chunk this server will take', function () {
    // The client sizes chunks by file size, but post_max_size is the server's
    // to know. A chunk over it is refused by Laravel's ValidatePostSize with
    // "The POST data is too large" before any upload code runs — which is
    // what a 6 GB file picking 32 MB chunks hit on a stock 8M limit.
    $response = $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertOk();

    expect($response->json('max_chunk'))->toBe(ChunkedUpload::maxChunkBytes());
});

it('never advertises a chunk that post_max_size would refuse', function () {
    $limit = ChunkedUpload::maxChunkBytes();
    $post = (int) ini_get('post_max_size') * 1024 * 1024;

    // Strictly under, not equal: the request line and headers count toward
    // the length the limit is applied to, and are not part of the chunk.
    expect($limit)->toBeLessThan($post)
        // Still worth sending. A limit this low means the panel pool was never
        // configured, but an upload of many small chunks beats none at all.
        ->and($limit)->toBeGreaterThanOrEqual(1024 * 1024);
});

it('assembles the part file inside the site tree so finalising is a rename', function () {
    $id = $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->json('upload_id');

    // The part file must sit under the application root — the same filesystem
    // as the destination, so finalising renames instead of copying every byte.
    // It used to sit under the *document root*, on the theory that only the
    // served directory was "the site tree"; `{root}/.panel` and
    // `{root}/public_html` are on the same filesystem, so the rename survives
    // the move and the part file is no longer one deny rule away from being
    // fetchable over HTTP.
    expect(ChunkedUploadFake::$ran)->toContain(
        "runuser -u siteowner -- touch /home/siteowner/shop/.panel/uploads/{$id}.part"
    );
});

it('keeps part files out of the served directory entirely', function () {
    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertOk();

    // The guard, not a restatement of the path above: whatever the layout
    // becomes, nothing an upload writes may land under public_html, where it
    // is protected only by a per-web-server dotfile rule that OpenLiteSpeed
    // did not apply to `.panel`.
    expect(collect(ChunkedUploadFake::$ran)->filter(
        fn (string $command) => str_contains($command, '/public_html/.panel')
    ))->toBeEmpty();
});

it('creates the upload directory elevated and hands it to the site user', function () {
    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertOk();

    // `.panel` is root-owned (the web server driver creates it through
    // ServerOps, and provisioning's chown only descends the document root),
    // so a `runuser` mkdir inside it is permission denied. Created elevated,
    // then chowned — the site user owns its uploads directory and nothing else.
    expect(ChunkedUploadFake::$ran)
        ->toContain('mkdir -p /home/siteowner/shop/.panel/uploads')
        ->toContain('chown siteowner:siteowner /home/siteowner/shop/.panel/uploads');
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

    // Body carries the path only — the id is in the URL, exactly as it is for
    // chunk, status and abort. Requiring it in both places made finalize
    // reject the client for sending it once.
    $this->actingAs($this->admin)
        ->postJson(uploadsUrl()."/{$id}/finalize", ['path' => 'big.zip'])
        ->assertOk();

    expect(ChunkedUploadFake::$ran)->toContain(
        "runuser -u siteowner -- mv -f /home/siteowner/shop/.panel/uploads/{$id}.part /home/siteowner/shop/public_html/big.zip"
    );

    // A rename, so no copy ever ran.
    expect(ChunkedUploadFake::binaries())->not->toContain('cp');
});

it('still refuses a finalize whose id could become a path', function () {
    // The id left the request body, so this is the only thing standing
    // between a crafted URL and an arbitrary filename.
    $this->actingAs($this->admin)
        ->postJson(uploadsUrl().'/not-a-valid-id/finalize', ['path' => 'big.zip'])
        ->assertNotFound();
});

it('refuses an upload id that could become a path', function () {
    $this->actingAs($this->admin)
        ->call('PUT', uploadsUrl().'/..%2f..%2fetc%2fpasswd', [], [], [], [], 'x')
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson(uploadsUrl().'/not-a-valid-id')
        ->assertNotFound();
});

it('refuses a file that will not fit before a single byte is sent', function () {
    // 10 GB free on a 300 GB disk: past the floor, so small writes are fine.
    ChunkedUploadFake::$availableBlocks = 10 * 1024 * 1024;

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'huge.zip', 'size' => 40 * 1024 * 1024 * 1024])
        ->assertStatus(507);

    // Nothing was created for an upload that was never going to fit.
    expect(ChunkedUploadFake::binaries())->not->toContain('touch');
});

it('accepts a file that fits once the floor is taken into account', function () {
    ChunkedUploadFake::$availableBlocks = 100 * 1024 * 1024;

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip', 'size' => 40 * 1024 * 1024 * 1024])
        ->assertOk();
});

it('reports usable space with the safety floor already subtracted', function () {
    ChunkedUploadFake::$totalBlocks = 300 * 1024 * 1024;
    ChunkedUploadFake::$availableBlocks = 100 * 1024 * 1024;

    $response = $this->actingAs($this->admin)
        ->getJson(uploadsUrl().'/space')
        ->assertOk();

    $floor = (int) (300 * 1024 * 1024 * 1024 * ChunkedUpload::MIN_FREE_FRACTION);

    expect($response->json('available'))->toBe(100 * 1024 * 1024 * 1024)
        ->and($response->json('floor'))->toBe($floor)
        ->and($response->json('usable'))->toBe(100 * 1024 * 1024 * 1024 - $floor);
});

it('does not claim the disk is full when free space cannot be read', function () {
    // `df` failing must not block uploads the disk may well have room for.
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'df: cannot read'));

    $this->actingAs($this->admin)
        ->getJson(uploadsUrl().'/space')
        ->assertOk()
        ->assertJson(['usable' => PHP_INT_MAX]);
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
        "runuser -u siteowner -- rm -f /home/siteowner/shop/.panel/uploads/{$id}.part"
    );
});

it('denies a user without manage rights', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertForbidden();
});

it('refuses to start an upload onto a file that is already there', function () {
    // Checked at begin, not only at finalize: refusing now costs nothing,
    // refusing after a two-gigabyte upload costs the upload.
    ChunkedUploadFake::$existing = ['/home/siteowner/shop/public_html/big.zip'];

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->assertStatus(422);

    // Nothing was created for an upload that was never allowed to open.
    expect(ChunkedUploadFake::binaries())->not->toContain('touch');
});

it('refuses to finalise onto a file that appeared during the upload', function () {
    // An upload is not instant. A file can arrive between begin and finalize,
    // and `mv -f` would take it away without a word.
    $id = $this->actingAs($this->admin)
        ->postJson(uploadsUrl(), ['path' => 'big.zip'])
        ->json('upload_id');

    ChunkedUploadFake::$existing = ['/home/siteowner/shop/public_html/big.zip'];

    $this->actingAs($this->admin)
        ->postJson(uploadsUrl().'/'.$id.'/finalize', ['path' => 'big.zip'])
        ->assertStatus(422);

    expect(ChunkedUploadFake::binaries())->not->toContain('mv');
});
