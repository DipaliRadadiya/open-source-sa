<?php

use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Process\ProcessResult;

/*
 * A pasted compose file is untrusted input that becomes a root-run command.
 *
 * The validator parses it with **`docker compose config --format json`** — the
 * daemon's own parser, not a YAML library — and that is the decision the whole
 * class rests on. A validator whose parser differs from the executor's is a
 * bypass waiting to be found: anything the two read differently is a way past
 * every rule. Using one parser makes that class of hole impossible rather than
 * unlikely.
 *
 * It also normalises, which removes the other half. `- /:/host` and
 * `- {type: bind, source: /, target: /host}` mean the same thing; `config`
 * resolves both to the second, so each rule is written once against one
 * canonical shape instead of guessing at five spellings. The fixtures below
 * are that resolved shape, taken from real output on a Docker box.
 */

/** A ServerOps whose `docker compose config` returns this resolved document. */
function composeOps(array $resolved, bool $answered = true): ServerOps
{
    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn(json_encode($resolved));
    $process->shouldReceive('errorOutput')->andReturn('');

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturn(new ServerOpsResult(
        ok: $answered,
        reference: 'r',
        result: $process,
        answered: $answered,
    ));

    return $ops;
}

/** A ServerOps that answers each successive `config` call from a list. */
function composeOpsSequence(array $documents): ServerOps
{
    $ops = Mockery::mock(ServerOps::class);
    $index = 0;

    $ops->shouldReceive('run')->andReturnUsing(function () use ($documents, &$index) {
        $doc = $documents[min($index, count($documents) - 1)];
        $index++;

        $process = Mockery::mock(ProcessResult::class);
        $process->shouldReceive('output')->andReturn(json_encode($doc));
        $process->shouldReceive('errorOutput')->andReturn('');

        return new ServerOpsResult(ok: true, reference: 'r', result: $process, answered: true);
    });

    return $ops;
}

const ROOT = '/home/shop/shop/public_html';

it('accepts a file that keeps to its own directory and to loopback', function () {
    $ops = composeOps(['services' => ['web' => [
        'image' => 'nginx:alpine',
        'ports' => [['host_ip' => '127.0.0.1', 'published' => '20001', 'target' => 80]],
        'volumes' => [['type' => 'bind', 'source' => ROOT.'/data', 'target' => '/data']],
    ]]]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeTrue();
});

it('binds a public port to loopback instead of refusing the file', function () {
    // The reversal that matters. `"3001:3001"` is the normal Docker idiom and
    // EVERY upstream compose file publishes that way — it is what the
    // project's own README says. Refusing it was right about the danger and
    // wrong as a product decision: the panel would have been hostile to every
    // image anyone ever tried.
    //
    // Two parses: the first sees a public port, the second (after the
    // rewrite) sees loopback.
    $public = ['services' => ['web' => ['ports' => [['published' => '3001', 'target' => 3001]]]]];
    $fixed = ['services' => ['web' => ['ports' => [['host_ip' => '127.0.0.1', 'published' => '3001', 'target' => 3001]]]]];

    $ops = composeOpsSequence([$public, $fixed]);

    $verdict = (new ComposeValidator($ops))->validate(
        "services:\n  web:\n    image: nginx\n    ports:\n      - \"3001:3001\"\n",
        ROOT,
    );

    expect($verdict['ok'])->toBeTrue()
        ->and($verdict['rewrote_ports'])->toBeTrue()
        // What gets written is the bound form, not what was typed.
        ->and($verdict['compose'])->toContain('127.0.0.1:3001:3001');
});

it('refuses a public port the rewrite could not fix', function () {
    // The safety does NOT rest on the regex being complete. A mapping form the
    // rewrite misses is re-parsed, still found public, and refused — never
    // silently published to the world.
    $public = ['services' => ['web' => ['ports' => [['published' => '8080', 'target' => 80]]]]];

    // Both parses see a public port: the rewrite changed nothing it could fix.
    $ops = composeOpsSequence([$public, $public]);

    $verdict = (new ComposeValidator($ops))->validate(
        "services:\n  web:\n    ports:\n      - target: 80\n        published: 8080\n",
        ROOT,
    );

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['errors'][0])->toBe(__('errors/application.compose_port_public', ['service' => '', 'port' => '']));
});

it('refuses a bind mount outside the application directory', function () {
    $ops = composeOps(['services' => ['web' => [
        'image' => 'nginx:alpine',
        'volumes' => [['type' => 'bind', 'source' => '/', 'target' => '/host']],
    ]]]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeFalse();
});

it('is not fooled by a sibling directory with the same prefix', function () {
    // `/home/shop/shop/public_html-evil` starts with the root as a STRING and
    // is a different directory. Comparing without the separator is the classic
    // way this check is written wrong.
    $ops = composeOps(['services' => ['web' => [
        'volumes' => [['type' => 'bind', 'source' => ROOT.'-evil', 'target' => '/x']],
    ]]]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeFalse();
});

it('is not fooled by a path that climbs out', function () {
    // Compose resolves `../../../etc` itself — measured on a real box, it
    // came back as `/etc`. This asserts the check still holds if a
    // non-normalised source ever reaches it.
    $ops = composeOps(['services' => ['web' => [
        'volumes' => [['type' => 'bind', 'source' => ROOT.'/../../../etc', 'target' => '/x']],
    ]]]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeFalse();
});

it('leaves named volumes alone', function () {
    // A named volume is Docker's to place and cannot point at the host
    // filesystem. Refusing it would block the normal way to persist data.
    $ops = composeOps(['services' => ['db' => [
        'image' => 'postgres:16',
        'volumes' => [['type' => 'volume', 'source' => 'pgdata', 'target' => '/var/lib/postgresql/data']],
    ]]]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeTrue();
});

it('refuses every documented way out of the container', function () {
    // Each of these is a way onto the host, not a style preference.
    $cases = [
        'privileged' => true,
        'cap_add' => ['SYS_ADMIN'],
        'devices' => [['source' => '/dev/sda', 'target' => '/dev/sda']],
        'pid' => 'host',
        'ipc' => 'host',
        'userns_mode' => 'host',
        'security_opt' => ['apparmor:unconfined'],
        'network_mode' => 'host',
        'cgroup_parent' => '/',
    ];

    foreach ($cases as $key => $value) {
        $ops = composeOps(['services' => ['web' => ['image' => 'nginx', $key => $value]]]);

        expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])
            ->toBeFalse("{$key} was allowed");
    }
});

it('refuses a file Docker itself cannot read', function () {
    $ops = composeOps([], answered: false);

    $verdict = (new ComposeValidator($ops))->validate('not: [valid', ROOT);

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['errors'][0])->toBe(__('errors/application.compose_unparsable'));
});

it('refuses a file with no services, which would run nothing', function () {
    $ops = composeOps(['services' => []]);

    expect((new ComposeValidator($ops))->validate('...', ROOT)['ok'])->toBeFalse();
});

it('validates from the directory the deploy will run in', function () {
    // Load-bearing, not tidiness. Compose resolves a relative bind source
    // against the CWD of the process reading the file — measured: from
    // /home/ubuntu, `./data` became `/home/ubuntu/data`. Validating from
    // anywhere else resolves a different path than the deploy will, which is
    // the validation/execution mismatch this class exists to avoid.
    $seen = null;

    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn(json_encode(['services' => ['w' => ['image' => 'n']]]));
    $process->shouldReceive('errorOutput')->andReturn('');

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturnUsing(
        function ($command, $context = [], $timeout = 60, $input = null, $cwd = null) use (&$seen, $process) {
            $seen = $cwd;

            return new ServerOpsResult(ok: true, reference: 'r', result: $process, answered: true);
        }
    );

    (new ComposeValidator($ops))->validate('...', ROOT);

    expect($seen)->toBe(ROOT);
});

it('reports one problem once, however many services repeat it', function () {
    // Five identical lines reads as five problems.
    $ops = composeOps(['services' => [
        'a' => ['privileged' => true],
        'b' => ['privileged' => true],
    ]]);

    $verdict = (new ComposeValidator($ops))->validate('...', ROOT);

    // Two services, two distinct messages (each names its service), but no
    // duplicates of either.
    expect($verdict['errors'])->toHaveCount(count(array_unique($verdict['errors'])));
});

it('translates every refusal in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach (['compose_unparsable', 'compose_no_services', 'compose_bind_outside', 'compose_port_public'] as $key) {
            expect(__("errors/application.{$key}", [], $locale))
                ->not->toBe("errors/application.{$key}", "{$key} missing in {$locale}");
        }

        foreach (['privileged', 'cap_add', 'devices', 'namespace', 'security_opt', 'network_mode', 'cgroup_parent'] as $key) {
            expect(__("errors/application.compose_forbidden.{$key}", [], $locale))
                ->not->toBe("errors/application.compose_forbidden.{$key}", "{$key} missing in {$locale}");
        }
    }
});

it('finds the published port when there is only one', function () {
    // The panel cannot allocate this. With a pasted file the user has already
    // chosen, and proxying to the port the panel wished for points nginx at
    // nothing.
    $resolved = ['services' => ['app' => [
        'ports' => [['host_ip' => '127.0.0.1', 'published' => '3001', 'target' => 3001]],
    ]]];

    expect((new ComposeValidator(composeOps([])))->publishedPort($resolved))->toBe(3001);
});

it('uses the container port to disambiguate several published ports', function () {
    // An application and its metrics endpoint is a legitimate file. The field
    // the user already has names the port INSIDE the container, which is the
    // number they know.
    $resolved = ['services' => ['app' => [
        'ports' => [
            ['host_ip' => '127.0.0.1', 'published' => '9100', 'target' => 9100],
            ['host_ip' => '127.0.0.1', 'published' => '3001', 'target' => 3001],
        ],
    ]]];

    $validator = new ComposeValidator(composeOps([]));

    expect($validator->publishedPort($resolved, 3001))->toBe(3001)
        ->and($validator->publishedPort($resolved, 9100))->toBe(9100)
        // And gives up rather than guessing, so the caller can ask.
        ->and($validator->publishedPort($resolved))->toBeNull();
});

it('reports no port when the file publishes none', function () {
    // A worker or a queue is a legitimate container with nothing to proxy to.
    // Distinguished from ambiguity so the caller can say the right thing.
    $resolved = ['services' => ['worker' => ['image' => 'busybox']]];

    expect((new ComposeValidator(composeOps([])))->publishedPort($resolved))->toBeNull();
});
