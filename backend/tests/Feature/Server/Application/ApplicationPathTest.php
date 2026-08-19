<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/*
 * The cron form picks a site and fills in the path box. Nothing in the API used
 * to say where a site was on disk — not even its slug — so the only option was
 * guessing from the name, which is wrong for a renamed site and wrong for two
 * sites that once shared a name.
 *
 * The reason this is two fields and not one is Craft and Statamic. Everywhere
 * else the served directory and the application's own directory are the same,
 * and a single `path` would look correct right up until somebody made a Craft
 * site and their queue runner silently did nothing.
 */

beforeEach(function () {
    $this->systemUser = SystemUser::factory()->create([
        'username' => 'siteowner',
        'home_path' => '/home/siteowner',
    ]);
});

function app_at(string $siteType, ?string $webRoot): Application
{
    return Application::factory()->create([
        'system_user_id' => test()->systemUser->id,
        'slug' => 'my-site',
        'site_type' => $siteType,
        'web_root' => $webRoot,
    ]);
}

it('serves from public_html when no web root is set', function () {
    $application = app_at('wordpress', '/');

    expect($application->documentRoot())->toBe('/home/siteowner/my-site/public_html');
});

it('appends the web root inside public_html, never outside the site', function () {
    $application = app_at('php', 'public');

    expect($application->documentRoot())->toBe('/home/siteowner/my-site/public_html/public');
});

/*
 * The path is built from the slug, which is what makes it survivable. A path
 * derived from the name moves when somebody renames the site, orphaning every
 * file already written under the old one — and a cron job still pointing there.
 */
it('names the directory by slug, so renaming the site does not move it', function () {
    $application = app_at('wordpress', '/');
    $before = $application->documentRoot();

    $application->update(['name' => 'Something Else Entirely']);

    expect($application->fresh()->documentRoot())->toBe($before);
});

describe('the path a command runs from', function () {

    it('is the document root for a type that unpacks into it', function () {
        // wp-cron.php sits in the served directory, so the two coincide — which
        // is exactly why one field looks sufficient until it is not.
        $application = app_at('wordpress', '/');

        expect($application->codePath())
            ->toBe('/home/siteowner/my-site/public_html')
            ->toBe($application->documentRoot());
    });

    /*
     * `php {path}/craft queue/run` — the `craft` binary is one level above the
     * served directory, which is where CraftCmsInstaller cd's to run it. Handed
     * the document root, that command points at .../public_html/web/craft,
     * which does not exist, and cron reports nothing.
     */
    it('is one level above the document root for Craft', function () {
        $application = app_at('craftcms', '/web');

        expect($application->documentRoot())->toBe('/home/siteowner/my-site/public_html/web')
            ->and($application->codePath())->toBe('/home/siteowner/my-site/public_html');
    });

    it('is one level above the document root for Statamic', function () {
        $application = app_at('statamic', '/public');

        expect($application->codePath())->toBe('/home/siteowner/my-site/public_html');
    });

    /*
     * A git site is the case that stops `dirname()` being the general rule:
     * GitDeployer clones *into* the document root, so whatever the checkout
     * holds is there regardless of what web_root says. Asking the site type
     * rather than the path shape is what gets both of these right at once.
     */
    it('is the document root for a git site, because that is where the repository is cloned', function () {
        $application = app_at('github', 'public');

        expect($application->codePath())
            ->toBe('/home/siteowner/my-site/public_html/public')
            ->toBe($application->documentRoot());
    });
});

it('is exposed on the application API for the cron form to read', function () {
    // Through the endpoint rather than by resolving the resource directly: the
    // resource asks systemd about the site, so it wants a real request behind
    // it, and this is the shape the frontend actually receives.
    Process::fake();

    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    grantPermission($user, 'application');

    $application = app_at('craftcms', '/web');

    $this->getJson("/api/applications/{$application->id}")
        ->assertOk()
        ->assertJsonPath('application.document_root', '/home/siteowner/my-site/public_html/web')
        ->assertJsonPath('application.path', '/home/siteowner/my-site/public_html');
});

/*
 * The provisioner owned this arithmetic and every shell command still goes
 * through it. It now delegates, so the two cannot drift — but it keeps the
 * traversal guard, because it is the one handing a directory to a command.
 */
it('still refuses a traversing path where the directory reaches a shell', function () {
    $application = app_at('php', '../../etc');

    expect(fn () => app(ApplicationProvisioner::class)->documentRoot($application))
        ->toThrow(HttpException::class);
});

it('agrees with the provisioner it delegates to', function () {
    foreach ([['wordpress', '/'], ['craftcms', '/web'], ['php', 'public']] as [$type, $webRoot]) {
        $application = app_at($type, $webRoot);

        expect(app(ApplicationProvisioner::class)->documentRoot($application))
            ->toBe($application->documentRoot(), "{$type} disagrees");

        $application->delete();
    }
});
