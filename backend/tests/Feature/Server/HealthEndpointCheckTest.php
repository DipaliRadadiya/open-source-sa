<?php

use App\Services\Panel\InstalledPanelInfo;
use App\Services\Server\Doctor\Checks\HealthEndpointCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * The doctor's self-probe through the web server. The installer runs it while
 * Ubuntu's first-boot updates may still restart php-fpm or the web server, so
 * "nothing answered" and a 5xx are asked again (3 tries, 1 s then 2 s apart);
 * any other answer is reported at once, and a final failure says why.
 */

beforeEach(function () {
    Sleep::fake();
    config()->set('app.url', 'https://api.example.test');

    $this->mock(InstalledPanelInfo::class)
        ->shouldReceive('installed')->andReturn(['version' => '1.2.3']);
});

function healthCheck(): array
{
    return app(HealthEndpointCheck::class)->run();
}

$healthy = ['health' => ['status' => 'ok', 'version' => '1.2.3']];

it('passes when the panel answers on the second try after no connection', function () use ($healthy) {
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push($healthy)]);

    $result = healthCheck();

    expect($result['status'])->toBe('pass')
        ->and($result['detail'])->toContain('1.2.3');
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('passes when a 502 from a restarting php-fpm is followed by an answer', function () use ($healthy) {
    Http::fake(['*' => Http::sequence()->push('bad gateway', 502)->push($healthy)]);

    expect(healthCheck()['status'])->toBe('pass');
    Http::assertSentCount(2);
});

it('fails after three tries with no connection and says why', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Connection timed out after 5001 ms')]);

    $result = healthCheck();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.health_unreachable')
        ->and($result['detail'])->toContain('https://api.example.test/api/health')
        ->and($result['detail'])->toContain('3')
        ->and($result['detail'])->toContain('cURL error 28: Connection timed out after 5001 ms');
    Http::assertSentCount(3);
});

it('says why in the requested language', function () {
    Http::fake(['*' => Http::failedConnection('Could not resolve host')]);
    app()->setLocale('de');

    expect(healthCheck()['detail'])->toBe('keine Antwort von https://api.example.test/api/health nach 3 Versuchen (Could not resolve host)');
});

it('reports a 500 after three tries as the status it got', function () {
    Http::fake(['*' => Http::response('oops', 500)]);

    $result = healthCheck();

    expect($result['status'])->toBe('fail')
        ->and($result['detail'])->toContain('500');
    Http::assertSentCount(3);
});

it('does not retry a 404, which is an answer', function () {
    Http::fake(['*' => Http::response('not found', 404)]);

    $result = healthCheck();

    expect($result['status'])->toBe('fail')
        ->and($result['detail'])->toContain('404');
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('does not retry a version mismatch', function () {
    Http::fake(['*' => Http::response(['health' => ['status' => 'ok', 'version' => '9.9.9']])]);

    expect(healthCheck()['status'])->toBe('warn');
    Http::assertSentCount(1);
});
