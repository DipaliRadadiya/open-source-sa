<?php

use App\Exceptions\Server\ServerOperationException;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Process;

/*
 * `useradd: cannot lock /etc/passwd; try again later.` — reported from a real
 * server moments after install.sh finished, while apt was still holding the
 * account lock. The command never started, so it was a hard error for
 * something that would have worked seconds later.
 */

beforeEach(function () {
    config()->set('server.transient.attempts', 3);
    // Tests must not actually wait; the delay is the one thing not asserted.
    config()->set('server.transient.delay_ms', 0);
    $this->ops = app(ServerOps::class);
});

it('retries a lock failure and succeeds when the lock clears', function () {
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return $attempt < 3
            ? Process::result(errorOutput: 'useradd: cannot lock /etc/passwd; try again later.', exitCode: 1)
            : Process::result(output: '', exitCode: 0);
    });

    $result = $this->ops->run(['useradd', '-m', 'deploy']);

    expect($result->ok)->toBeTrue()
        ->and($attempt)->toBe(3)
        ->and($result->busy)->toBeFalse();
});

it('gives up after the configured attempts and reports busy', function () {
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return Process::result(errorOutput: 'useradd: cannot lock /etc/passwd; try again later.', exitCode: 1);
    });

    $result = $this->ops->run(['useradd', '-m', 'deploy']);

    expect($result->failed())->toBeTrue()
        ->and($attempt)->toBe(3)
        // The distinction that matters: occupied, not misconfigured.
        ->and($result->busy)->toBeTrue();
});

it('retries the dpkg lock too', function () {
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return $attempt < 2
            ? Process::result(errorOutput: 'E: Could not get lock /var/lib/dpkg/lock-frontend', exitCode: 1)
            : Process::result(exitCode: 0);
    });

    expect($this->ops->run(['apt-get', 'install', '-y', 'nginx'])->ok)->toBeTrue()
        ->and($attempt)->toBe(2);
});

it('does not retry a genuine failure', function () {
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return Process::result(errorOutput: 'useradd: user "deploy" already exists', exitCode: 9);
    });

    $result = $this->ops->run(['useradd', '-m', 'deploy']);

    // Retrying this would waste the user's time and change nothing — and for
    // a command that had half-completed it could do real damage.
    expect($result->failed())->toBeTrue()
        ->and($attempt)->toBe(1)
        ->and($result->busy)->toBeFalse();
});

it('does not retry a success', function () {
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return Process::result(exitCode: 0);
    });

    expect($this->ops->run(['useradd', 'deploy'])->ok)->toBeTrue()
        ->and($attempt)->toBe(1);
});

it('can be switched off', function () {
    config()->set('server.transient.attempts', 1);
    $attempt = 0;

    Process::fake(function () use (&$attempt) {
        $attempt++;

        return Process::result(errorOutput: 'cannot lock /etc/passwd', exitCode: 1);
    });

    expect($this->ops->run(['useradd', 'deploy'])->failed())->toBeTrue()
        ->and($attempt)->toBe(1);
});

it('answers a busy system with 503 and a retryable code, not a 500', function () {
    $exception = new class('ref-123', busy: true) extends ServerOperationException
    {
        protected function messageKey(): string
        {
            return 'errors/system-user.create_failed';
        }
    };

    $response = $exception->render(request());

    // 500 tells the operator their server is broken. It is merely occupied,
    // and the frontend needs a stable code to offer "try again" without
    // matching translated prose.
    expect($response->getStatusCode())->toBe(503)
        ->and($response->getData(true)['code'])->toBe('server_busy')
        ->and($response->getData(true)['message'])->toBe(__('errors/server.busy'))
        ->and($response->getData(true)['reference'])->toBe('ref-123');
});

it('still answers a real failure with 500', function () {
    $exception = new class('ref-456') extends ServerOperationException
    {
        protected function messageKey(): string
        {
            return 'errors/system-user.create_failed';
        }
    };

    $response = $exception->render(request());

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true)['code'])->toBe('server_operation_failed')
        ->and($response->getData(true)['message'])->toBe(__('errors/system-user.create_failed'));
});

it('has the busy message in every locale', function () {
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        expect(__('errors/server.busy'))->not->toBe('errors/server.busy');
    }
});

describe('a lock nobody holds', function () {
    it('reports a stale lock apart from a busy system', function () {
        // Same stderr either way. What separates them is that this one
        // survived every retry, which means the "holder" is a corpse: a lock
        // file an interrupted useradd left behind, blocking all user
        // management until someone deletes it.
        Process::fake(['*' => Process::result(
            errorOutput: 'useradd: cannot lock /etc/passwd; try again later.',
            exitCode: 1,
        )]);

        $result = $this->ops->run(['useradd', '-m', 'deploy']);

        expect($result->failed())->toBeTrue()
            ->and($result->staleLock)->toBeTrue();
    });

    it('does not call an apt lock stale', function () {
        // dpkg's lock really is held by a running apt, and really will free
        // itself. Telling the operator to delete it would be dangerous advice.
        Process::fake(['*' => Process::result(
            errorOutput: 'E: Could not get lock /var/lib/dpkg/lock-frontend',
            exitCode: 1,
        )]);

        expect($this->ops->run(['apt-get', 'install', '-y', 'nginx'])->staleLock)->toBeFalse();
    });

    it('answers 500 with a stale-lock code, not a 503 telling you to wait', function () {
        $exception = new class('ref-1', busy: true, staleLock: true) extends ServerOperationException
        {
            protected function messageKey(): string
            {
                return 'errors/system-user.create_failed';
            }
        };

        $response = $exception->render(request());
        $body = $response->getData(true);

        // 503 means "come back later". Nobody is coming to free this lock, so
        // that is advice which can never come true — this is a fault on the
        // server that needs a person.
        expect($response->getStatusCode())->toBe(500)
            ->and($body['code'])->toBe('server_stale_lock')
            ->and($body['message'])->toBe(__('errors/server.stale_lock'));
    });

    it('has the stale-lock message in every locale', function () {
        foreach (config('app.available_locales') as $locale) {
            app()->setLocale($locale);

            expect(__('errors/server.stale_lock'))->not->toBe('errors/server.stale_lock');
        }
    });
});
