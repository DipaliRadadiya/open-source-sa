<?php

use App\Actions\Server\Application\CreateApplication;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/**
 * A new application appears on its own Domains screen.
 *
 * The domains table is what that screen reads, and nothing wrote to it at
 * create time — only the migration that introduced the table backfilled the
 * sites that existed then. Every application made afterwards came up with an
 * empty Domains section while plainly answering on a domain.
 *
 * The second half is the `is_test` flag, which decides whether a name may go
 * on a certificate. It was fillable, cast, and read in three places, and
 * nothing ever set it — so it was always false, and the guard that exists to
 * stop the panel spending a Let's Encrypt quota shared with the whole internet
 * had never once fired.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    Process::fake();
});

function createSite(array $payload = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications', array_merge([
            'system_user_id' => test()->su->id,
            'name' => 'Blog',
            'domain' => 'blog.example.com',
            'site_type' => 'php',
        ], $payload));
}

it('records the primary domain so the Domains screen is not empty', function () {
    createSite()->assertCreated();

    $domain = ApplicationDomain::first();

    expect($domain)->not->toBeNull()
        ->and($domain->domain)->toBe('blog.example.com')
        ->and($domain->type)->toBe(DomainType::Primary)
        // `applications.domain` is the mirror; this is the row it mirrors.
        ->and($domain->application->domain)->toBe('blog.example.com');
});

it('creates exactly one primary domain', function () {
    createSite()->assertCreated();

    expect(ApplicationDomain::where('type', DomainType::Primary->value)->count())->toBe(1);
});

it('flags a temporary domain as what it is, without disqualifying it', function () {
    createSite([
        'domain' => 'blog.203-0-113-9.nip.io',
        'domain_type' => 'temp',
    ])->assertCreated();

    $domain = ApplicationDomain::first();

    // The flag is a fact about the hostname — the panel uses it to know a
    // wildcard-DNS name resolves by construction and needs no lookup. It is
    // not a verdict: a name that points here can be certified whatever its
    // suffix, and refusing on the suffix made a second class of domain the
    // panel would not protect.
    expect($domain->is_test)->toBeTrue()
        ->and($domain->certifiable())->toBe($domain->dns_verified_at !== null);
});

it('treats a domain the user owns as certifiable once DNS is verified', function () {
    createSite(['domain_type' => 'custom'])->assertCreated();

    $domain = ApplicationDomain::first();

    expect($domain->is_test)->toBeFalse();

    $domain->forceFill(['dns_verified_at' => now()])->save();

    expect($domain->fresh()->certifiable())->toBeTrue();
});

it('catches a wildcard-DNS name even when it is labelled as the user\'s own', function () {
    // The client says "custom" and sends a nip.io name. Believing that would
    // send it to Let's Encrypt and spend from the shared limit, so the suffix
    // is checked rather than the label taken on trust.
    createSite([
        'domain' => 'blog.203-0-113-9.nip.io',
        'domain_type' => 'custom',
    ])->assertCreated();

    expect(ApplicationDomain::first()->is_test)->toBeTrue();
});

it('treats an unlabelled domain as the user\'s own', function () {
    // A caller written before the create form grew the toggle.
    createSite()->assertCreated();

    expect(ApplicationDomain::first()->is_test)->toBeFalse();
});

it('refuses a domain type it does not recognise', function () {
    createSite(['domain_type' => 'whatever'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain_type');
});

it('flags a temporary domain added after creation too', function () {
    createSite()->assertCreated();

    $application = Application::first();

    test()->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t2')->plainTextToken])
        ->postJson("/api/applications/{$application->id}/domains", [
            'domain' => 'staging.203-0-113-9.nip.io',
            'type' => 'alias',
        ])->assertCreated();

    expect(ApplicationDomain::where('domain', 'staging.203-0-113-9.nip.io')->first()->is_test)->toBeTrue();
});

it('tells the create form this server address so it can offer a temporary name', function () {
    $response = test()->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t3')->plainTextToken])
        ->getJson('/api/server/capabilities')
        ->assertOk();

    // Null on a box where detection failed is a valid answer — the form has to
    // be able to say so rather than offer a name that resolves nowhere.
    expect($response->json('capabilities'))->toHaveKey('server_ip')
        // A list, so the form can spread new sites across services rather than
        // pointing every install at the same one.
        ->and($response->json('capabilities.temporary_domain_suffixes'))->toContain('nip.io', 'sslip.io');
});

it('recognises every suffix it offers as temporary', function () {
    // The offered list and the recognised list are the same config key, and
    // this is what that buys: a hostname the form can build is always a
    // hostname the backend knows not to put on a certificate. When they were
    // two keys, a suffix could be offered and not recognised — and the name
    // would go to Let's Encrypt as though the user owned it.
    foreach ((array) config('server.temporary_domain_suffixes') as $suffix) {
        expect(ApplicationDomain::looksTemporary("site.203-0-113-9.{$suffix}"))
            ->toBeTrue("{$suffix} is offered but not recognised as temporary");
    }
});

it('does not mistake a real domain for a temporary one', function () {
    expect(ApplicationDomain::looksTemporary('example.com'))->toBeFalse()
        // Not a suffix match: a real domain that merely contains the string.
        ->and(ApplicationDomain::looksTemporary('nip.io.example.com'))->toBeFalse()
        ->and(ApplicationDomain::looksTemporary('mysslip.io'))->toBeFalse();
});

it('records the primary domain for every site type', function () {
    // Asked rather than assumed. The domain row is written in one place, but
    // "one place" is what the placeholder and the vhost looked like too, and
    // each of those turned out to behave differently per site type. A one-type
    // test would have proved nothing about the other sixteen.
    $types = app(SiteTypeManager::class)->names();

    expect($types)->not->toBeEmpty();

    foreach ($types as $index => $type) {
        $application = app(CreateApplication::class)->execute([
            'system_user_id' => $this->su->id,
            'name' => 'Site '.$type,
            'domain' => $type.'-'.$index.'.example.com',
            'site_type' => $type,
            'domain_type' => 'custom',
        ]);

        $primary = $application->domains()->where('type', DomainType::Primary->value)->first();

        expect($primary)->not->toBeNull("{$type} created no primary domain")
            ->and($primary->domain)->toBe($type.'-'.$index.'.example.com')
            ->and($application->domains()->count())->toBe(1, "{$type} created more than one domain");
    }
});

it('serves every site type under the domain it was created with', function () {
    // The vhost renders `server_name` from the domains relation, falling back
    // to the mirrored column. Now that the rows exist, the relation is what
    // answers — so this checks the two agree rather than trusting the fallback
    // to keep hiding a disagreement.
    foreach (app(SiteTypeManager::class)->names() as $index => $type) {
        $application = app(CreateApplication::class)->execute([
            'system_user_id' => $this->su->id,
            'name' => 'Served '.$type,
            'domain' => $type.'-served-'.$index.'.example.com',
            'site_type' => $type,
        ]);

        expect($application->load('domains')->serverNames())
            ->toContain($type.'-served-'.$index.'.example.com');
    }
});

it('returns the domain from the endpoint the Domains screen calls', function () {
    createSite(['domain' => 'shop.example.com', 'domain_type' => 'custom'])->assertCreated();

    $application = Application::first();

    // End to end through HTTP, because that is what the screen does. The rows
    // existing in the database is necessary and not sufficient — the resource
    // has to expose them too.
    test()->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t4')->plainTextToken])
        ->getJson("/api/applications/{$application->id}/domains")
        ->assertOk()
        ->assertJsonCount(1, 'domains')
        ->assertJsonPath('domains.0.domain', 'shop.example.com')
        ->assertJsonPath('domains.0.type', 'primary');
});
