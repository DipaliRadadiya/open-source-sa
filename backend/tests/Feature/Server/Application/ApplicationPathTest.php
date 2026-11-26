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
 * The cron form picks a site and fills in the path box, so the API must say where a site is on
 * disk; guessing from the name breaks for renamed sites.
 *
 * It is two fields, not one, because of Craft and Statamic: elsewhere the served directory and
 * the application's own directory coincide, so a single `path` is wrong for them.
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
 * The path is built from the slug: a path derived from the name would move on rename, orphaning
 * files and any cron job pointing there.
 */
it('names the directory by slug, so renaming the site does not move it', function () {
    $application = app_at('wordpress', '/');
    $before = $application->documentRoot();

    $application->update(['name' => 'Something Else Entirely']);

    expect($application->fresh()->documentRoot())->toBe($before);
});

describe('the path a command runs from', function () {

    it('is the document root for a type that unpacks into it', function () {
        // wp-cron.php sits in the served directory, so the two coincide.
        $application = app_at('wordpress', '/');

        expect($application->codePath())
            ->toBe('/home/siteowner/my-site/public_html')
            ->toBe($application->documentRoot());
    });

    /*
     * `php {path}/craft queue/run`: the `craft` binary is one level above the served directory
     * (where CraftCmsInstaller cd's to run it). Given the document root, the command would point at
     * .../public_html/web/craft, which does not exist.
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
     * A git site stops `dirname()` being the general rule: GitDeployer clones *into* the document
     * root, whatever web_root says. So the site type is asked, not the path shape.
     */
    it('is the document root for a git site, because that is where the repository is cloned', function () {
        $application = app_at('github', 'public');

        expect($application->codePath())
            ->toBe('/home/siteowner/my-site/public_html/public')
            ->toBe($application->documentRoot());
    });
});

it('is exposed on the application API for the cron form to read', function () {
    // Through the endpoint, not the resource directly: the resource asks systemd about the site and
    // this is the shape the frontend receives.
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
 * The provisioner delegates the path arithmetic so the two cannot drift, but keeps the traversal
 * guard because it is what hands a directory to a shell command.
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
