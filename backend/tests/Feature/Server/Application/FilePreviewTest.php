<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The file manager can show an image.
 *
 * Before this endpoint it could not: `files/content` refuses anything binary,
 * and `files/download` deliberately labels every file `application/octet-stream`
 * so that nothing the panel returns can be interpreted by the browser. A
 * picture in a site's uploads folder was listed and never viewable.
 *
 * This is therefore the one response here a browser is asked to render, and
 * the tests below are mostly about keeping that narrow: the content type comes
 * from the file's own bytes, an SVG is refused, an oversized file is refused
 * before it is read, and the headers say the browser may not second-guess any
 * of it.
 */
class PreviewFake
{
    /** Bytes the fake file holds. */
    public static string $content = '';

    /** Size `find` reports, which is not always strlen($content) — see the cap test. */
    public static ?int $size = null;

    /** @var array<int, string> every command the panel ran */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$content = '';
        self::$size = null;
        self::$ran = [];
    }

    public static function reportedSize(): int
    {
        return self::$size ?? strlen(self::$content);
    }
}

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

    // The recorded stack, for the reason ApplicationFileTest gives: without it
    // the browser detects one by reading /etc on whatever machine runs the
    // suite.
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    PreviewFake::reset();
});

/**
 * A server holding one file, whose bytes are whatever the test set.
 *
 * `head` returns a prefix rather than the whole thing, like the real one: the
 * sniff has to work on the first few hundred bytes, and a fake that answered
 * with the entire file would hide it if it ever stopped.
 */
function fakePreviewServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // `runuser -u <user> -- <command>`: the command itself starts after --.
        $separator = array_search('--', $args, true);
        $command = $separator === false ? $args : array_slice($args, $separator + 1);

        PreviewFake::$ran[] = implode(' ', $command);

        return match ($command[0] ?? '') {
            'test' => Process::result(exitCode: 0),
            'find' => Process::result(output: "f\t".PreviewFake::reportedSize()),
            'head' => Process::result(output: substr(PreviewFake::$content, 0, (int) ($command[2] ?? 0))),
            'cat' => Process::result(output: PreviewFake::$content),
            default => Process::result(exitCode: 0),
        };
    });
}

/** A valid one-pixel PNG, header and all. */
function pngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

function previewResponse(string $path = 'logo.png')
{
    fakePreviewServer();

    return test()->actingAs(test()->admin)
        ->get('/api/applications/'.test()->application->id.'/files/preview?path='.urlencode($path));
}

it('serves an image with its own content type', function () {
    PreviewFake::$content = pngBytes();

    $response = previewResponse();

    $response->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('content-security-policy', "default-src 'none'; sandbox");

    // `rtrim`, and only here: Laravel's fake process appends a newline to the
    // output it was handed, which `cat` does not. The bytes either side of it
    // are the file's own, which is what this asserts.
    expect($response->headers->get('content-disposition'))->toStartWith('inline;')
        ->and(rtrim($response->streamedContent(), "\n"))->toBe(pngBytes());
});

it('reads the type from the bytes, never from the name', function () {
    // The whole reason this endpoint may set a real content type at all. A
    // file named `.png` holding markup must not come back as an image — a
    // browser that sniffs past our header would then run it on the API's own
    // origin.
    PreviewFake::$content = '<html><script>alert(1)</script></html>';

    previewResponse('avatar.png')
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.file_not_previewable'));
});

it('refuses an SVG, and says that is why', function () {
    // Its own message rather than "not an image": an SVG *is* an image, and
    // the user needs to know it was withheld deliberately. It is refused
    // because it can carry script and this response is inline and same-origin.
    PreviewFake::$content = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>';

    previewResponse('icon.svg')
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.file_svg_not_previewable'));
});

it('does not mistake other XML for an SVG', function () {
    // A sitemap is not an image and not an SVG, and telling someone their XML
    // was withheld for carrying script would be a confident wrong answer.
    PreviewFake::$content = '<?xml version="1.0"?><urlset><url><loc>https://shop.test</loc></url></urlset>';

    previewResponse('sitemap.xml')
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.file_not_previewable'));
});

it('refuses an oversized image without reading it', function () {
    PreviewFake::$content = pngBytes();
    PreviewFake::$size = 11 * 1024 * 1024;

    previewResponse()
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.file_too_large_to_preview'));

    // Checked against the stat, so an image too big to show costs a refusal
    // rather than a transfer.
    expect(PreviewFake::$ran)->not->toContain('cat /home/siteowner/shop/logo.png');
});

it('takes the cap from configuration', function () {
    config(['server.applications.preview_max_bytes' => 32]);

    PreviewFake::$content = pngBytes();
    PreviewFake::$size = 33;

    previewResponse()->assertStatus(422);
});

it('refuses a caller without file permission', function () {
    // A user with a different permission entirely, not a user with none: the
    // question is whether this route is gated on `app_file`, and a caller who
    // can do nothing at all would pass that test for the wrong reason.
    $outsider = User::factory()->create();
    grantPermission($outsider, 'app_backup');

    PreviewFake::$content = pngBytes();
    fakePreviewServer();

    $this->actingAs($outsider)
        ->get("/api/applications/{$this->application->id}/files/preview?path=logo.png")
        ->assertForbidden();
});

it('refuses a path that climbs out of the site', function () {
    fakePreviewServer();

    $this->actingAs($this->admin)
        ->get("/api/applications/{$this->application->id}/files/preview?path=".urlencode('../../etc/passwd'))
        ->assertStatus(422);
});
