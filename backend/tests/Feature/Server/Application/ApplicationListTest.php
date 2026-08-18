<?php

use App\Http\Requests\Server\Application\IndexApplicationsRequest;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The applications list returned every application, every time.
 *
 * Not only a large payload: `ApplicationResource` asks systemd for the state of
 * every application that runs a process, so fifty Node or git sites meant fifty
 * elevated `systemctl show` calls in one request. A page bounds a number of
 * subprocesses, not just a number of rows.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

function listedApp(string $name, string $domain): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => $name,
        'slug' => Str::slug($name),
        'domain' => $domain,
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
}

it('returns ten per page by default', function () {
    for ($i = 1; $i <= 14; $i++) {
        listedApp("Site {$i}", "site{$i}.example.com");
    }

    $response = $this->actingAs($this->admin)->getJson('/api/applications')->assertOk();

    expect($response->json('applications'))->toHaveCount(IndexApplicationsRequest::PER_PAGE)
        ->and($response->json('meta.total'))->toBe(14)
        ->and($response->json('meta.per_page'))->toBe(10)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('returns the rest on the next page', function () {
    for ($i = 1; $i <= 14; $i++) {
        listedApp("Site {$i}", "site{$i}.example.com");
    }

    $response = $this->actingAs($this->admin)->getJson('/api/applications?page=2')->assertOk();

    expect($response->json('applications'))->toHaveCount(4)
        ->and($response->json('meta.current_page'))->toBe(2);
});

it('honours per_page, within a cap', function () {
    for ($i = 1; $i <= 6; $i++) {
        listedApp("Site {$i}", "site{$i}.example.com");
    }

    expect($this->actingAs($this->admin)->getJson('/api/applications?per_page=3')->json('applications'))
        ->toHaveCount(3);

    // Unbounded paging would put the per-row systemctl cost straight back.
    $this->actingAs($this->admin)
        ->getJson('/api/applications?per_page=5000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('searches the name', function () {
    listedApp('Shop', 'shop.example.com');
    listedApp('Blog', 'blog.example.com');

    $response = $this->actingAs($this->admin)->getJson('/api/applications?search=Shop')->assertOk();

    expect($response->json('applications'))->toHaveCount(1)
        ->and($response->json('applications.0.name'))->toBe('Shop');
});

it('searches the domain, because that is the other thing people remember', function () {
    listedApp('Shop', 'shop.example.com');
    listedApp('Blog', 'blog.example.net');

    $response = $this->actingAs($this->admin)->getJson('/api/applications?search=example.net')->assertOk();

    expect($response->json('applications'))->toHaveCount(1)
        ->and($response->json('applications.0.name'))->toBe('Blog');
});

it('counts only what the search matched', function () {
    for ($i = 1; $i <= 12; $i++) {
        listedApp("Site {$i}", "site{$i}.example.com");
    }

    listedApp('Shop', 'shop.example.com');

    $response = $this->actingAs($this->admin)->getJson('/api/applications?search=Shop')->assertOk();

    // The pager has to describe the filtered set, or page 2 of a one-result
    // search is an empty screen with a next button.
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.last_page'))->toBe(1);
});

it('returns an empty page rather than an error when nothing matches', function () {
    listedApp('Shop', 'shop.example.com');

    $response = $this->actingAs($this->admin)->getJson('/api/applications?search=nothing-like-this')->assertOk();

    expect($response->json('applications'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
});

it('keeps the newest first', function () {
    listedApp('Older', 'older.example.com');
    $newest = listedApp('Newer', 'newer.example.com');

    $response = $this->actingAs($this->admin)->getJson('/api/applications')->assertOk();

    expect($response->json('applications.0.id'))->toBe($newest->id);
});
