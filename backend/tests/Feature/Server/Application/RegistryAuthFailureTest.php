<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Process;

/**
 * A container site whose image the registry would not hand over.
 *
 * The panel cannot sign in to a registry yet, so a private image fails at `compose up` with
 * Docker's wording buried in pull progress. The reason must say the image never arrived, not
 * just that the container did not start.
 *
 * The output strings below were captured from real Docker/Compose runs. Each registry words the
 * refusal differently, which is why the classifier needs four needles instead of one.
 */

/*
 * The four registry phrasings.
 */

it('names the Docker Hub refusal', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-900',
        result: Process::result(
            output: '',
            errorOutput: "Error response from daemon: pull access denied for svtest/private, repository does not exist or may require 'docker login'",
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)
        ->toBe('registry_auth');
});

it('names the GHCR refusal, which says only `denied`', function () {
    Process::fake();

    // A bare `denied` needle would be wrong: the word appears alone on a line here and in
    // unrelated daemon errors.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-901',
        result: Process::result(
            output: '',
            errorOutput: "Error response from daemon: error from registry: denied\ndenied",
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)
        ->toBe('registry_auth');
});

it('names the ECR refusal', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-902',
        result: Process::result(
            output: '',
            errorOutput: 'Error response from daemon: failed to resolve reference "123456789012.dkr.ecr.us-east-1.amazonaws.com/foo:1": pull access denied, repository does not exist or may require authorization: authorization failed: no basic auth credentials',
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)
        ->toBe('registry_auth');
});

it('names a registry v2 401, which is what GitLab and Harbor answer', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-903',
        result: Process::result(
            output: '',
            errorOutput: 'Error response from daemon: unauthorized: authentication required',
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)
        ->toBe('registry_auth');
});

it('finds it in compose output, where it is one line of pull progress', function () {
    Process::fake();

    // Compose prints per-image progress, so the line that matters is neither first nor last, and
    // `up` repeats it.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-904',
        result: Process::result(
            output: " Image svtest/private:1 Pulling \n Image svtest/private:1 Error pull access denied for svtest/private, repository does not exist or may require 'docker login'\n",
            errorOutput: "Error response from daemon: pull access denied for svtest/private, repository does not exist or may require 'docker login'",
            exitCode: 18,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)
        ->toBe('registry_auth');
});

/*
 * Two failures that reach the same step but are not registry auth. Misclassifying them would send
 * someone looking for a credential that was never the problem.
 */

it('does not blame a credential for a tag that does not exist', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-905',
        result: Process::result(
            output: '',
            errorOutput: 'Error response from daemon: failed to resolve reference "docker.io/library/nginx:no-such-tag": docker.io/library/nginx:no-such-tag: not found',
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)->toBeNull();
});

it('does not blame a credential for a registry it could not reach', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-906',
        result: Process::result(
            output: '',
            errorOutput: 'Error response from daemon: failed to resolve reference "registry.invalid.example.com/foo/bar:1": failed to do request: Head "https://registry.invalid.example.com/v2/foo/bar/manifests/1": dial tcp: lookup registry.invalid.example.com on 127.0.0.53:53: no such host',
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('container_start', $result)->reason)->toBeNull();
});

/*
 * The wiring: a classifier nothing calls is dead code, so the supervisor is asserted here too.
 */

it('reaches the classifier from the supervisor, not only from a unit test', function () {
    $systemUser = SystemUser::create(['username' => 'priv', 'home_path' => '/home/priv']);

    $application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Private', 'slug' => 'priv', 'domain' => 'priv.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'ghcr.io/acme/private:1', 'container_port' => 3000, 'app_port' => 3101,
        'status' => 'provisioning',
    ]);

    // Faked per command, and only `compose up` fails: a blanket failure would break the file
    // write first.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[1] ?? '') === 'compose' && in_array('up', $args, true)) {
            return Process::result(
                output: " Image ghcr.io/acme/private:1 Error error from registry: denied\n",
                errorOutput: "Error response from daemon: error from registry: denied\ndenied",
                exitCode: 18,
            );
        }

        return Process::result(exitCode: 0);
    });

    $supervisor = app(ContainerSupervisor::class);

    try {
        $supervisor->apply($application, '/home/priv/priv.test');
        $this->fail('apply() should have thrown on a failed compose up');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('container_start')
            ->and($e->reason)->toBe('registry_auth');
    }
});

it('has the reason translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.registry_auth', [], $locale);

        expect($line)->not->toBe('application.failure_reason.registry_auth')
            ->and($line)->not->toBeEmpty();
    }
});

it('does not claim the image is private, because Docker cannot tell', function () {
    // Docker Hub answers "no such repository" and "private, log in" with the same sentence, so the
    // text must name both causes and say the panel cannot store credentials yet.
    $line = __('application.failure_reason.registry_auth', [], 'en');

    expect($line)->toContain('name or tag is wrong')
        ->and($line)->toContain('private')
        ->and($line)->toContain('cannot sign in to a registry yet');
});
