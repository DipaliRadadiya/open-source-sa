<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;

/**
 * The slug is the only thing `documentRoot()` and every web-server config path are built from,
 * so an application without one resolves to the system user's home directory, shared by every
 * slugless site: two sites would provision into one directory.
 *
 * `slug` is deliberately not fillable (it names files the panel overwrites, so a client must
 * never choose it), which is why mass-assigned `Application::create()` calls in CloneManager and
 * StagingManager silently dropped it.
 */
beforeEach(function () {
    $this->systemUser = SystemUser::create([
        'username' => 'siteowner',
        'home_path' => '/home/siteowner',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function slugTestApplication(array $attributes = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Company Blog',
        'slug' => 'company-blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ], $attributes));
}

it('drops a slug passed through mass assignment', function () {
    // Asserted so it stays deliberate: `create()` gets no slug, and no error.
    $application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Company Blog',
        'slug' => 'company-blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    expect($application->slug)->toBeNull();
});

it('collapses the document root onto the system user home without a slug', function () {
    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Company Blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    // Documents the failure mode, does not endorse it: with no slug the root is the home directory
    // itself, shared by every slugless site of that user. `Application::rootPath()` drops the empty
    // segment, so there is no `//`.
    expect(app(ApplicationProvisioner::class)->documentRoot($application->load('systemUser')))
        ->toBe('/home/siteowner/public_html');
});

it('gives every slugged site its own document root', function () {
    $first = slugTestApplication(['domain' => 'a.test']);
    $second = slugTestApplication([
        'name' => 'Company Blog (Clone)',
        'slug' => Application::uniqueSlug('Company Blog (Clone)'),
        'domain' => 'b.test',
    ]);

    $provisioner = app(ApplicationProvisioner::class);

    expect($provisioner->documentRoot($first->load('systemUser')))
        ->toBe('/home/siteowner/company-blog/public_html')
        ->and($provisioner->documentRoot($second->load('systemUser')))
        ->toBe('/home/siteowner/company-blog-clone/public_html')
        ->and($provisioner->documentRoot($second))->not->toContain('//');
});

it('suffixes a slug that is already taken', function () {
    slugTestApplication(['domain' => 'a.test']);

    // Two different names can slug to the same string, which would put two sites in one directory.
    expect(Application::uniqueSlug('Company Blog!'))->toBe('company-blog-2');
});

it('suffixes a derived name so a second clone does not collide', function () {
    // `applications.name` is unique and "<source> (Clone)" repeats, so a second clone would be a
    // constraint violation (a 500, not a usable error).
    slugTestApplication(['name' => 'Company Blog (Clone)', 'slug' => 'c1', 'domain' => 'c1.test']);

    expect(Application::uniqueName('Company Blog (Clone)'))->toBe('Company Blog (Clone) 2');
});

it('leaves a free name alone', function () {
    expect(Application::uniqueName('Company Blog (Staging)'))->toBe('Company Blog (Staging)');
});
