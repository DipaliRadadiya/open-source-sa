<?php

use App\Actions\Server\Application\ApplyVhost;
use App\Actions\Server\Application\RequestCertificate;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\IssueCertificate;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('captures the serving HTTPS URL before marking a reissued certificate as issuing', function (bool $requested) {
    $site = rc03RecipeSite('gitea', ['status' => 'active', 'docker_secrets' => []]);
    $certificate = Certificate::create([
        'application_id' => $site->id, 'type' => CertificateType::SelfSigned,
        'status' => CertificateStatus::Active, 'domains' => [$site->domain],
        'certificate_path' => '/etc/ssl/certs/site.crt', 'private_key_path' => '/etc/ssl/private/site.key',
        'expires_at' => now()->addYear(),
    ]);
    expect($site->fresh()->url())->toBe('https://example.test');
    $job = new IssueCertificate($certificate->id);
    if ($requested) {
        Queue::fake();
        app(RequestCertificate::class)->execute($site, CertificateType::SelfSigned);
        Queue::assertPushed(IssueCertificate::class, function ($queued) use (&$job) {
            $job = $queued;

            return true;
        });
        expect($certificate->fresh()->status)->toBe(CertificateStatus::Pending);
    }
    $files = Mockery::mock(CertificateFiles::class);
    $files->shouldReceive('conflict')->andReturn(null);
    $files->shouldReceive('selfSign')->once()->andReturn(new ServerOpsResult(true, 'self-sign-reference'));
    $files->shouldReceive('paths')->once()->andReturn(['certificate' => '/etc/ssl/certs/site.crt', 'private_key' => '/etc/ssl/private/site.key']);
    $files->shouldReceive('expiresAt')->once()->andReturn(now()->addYear());
    $urls = [];
    $installers = Mockery::mock(InstallerManager::class);
    $installers->shouldReceive('syncUrl')->twice()->andReturnUsing(function ($application, $url) use (&$urls) {
        $urls[] = $url;
    });
    $failure = new ProvisioningFailedException('vhost_test', 'vhost-reference');
    $vhost = Mockery::mock(ApplyVhost::class);
    $vhost->shouldReceive('execute')->once()->andThrow($failure);

    try {
        $job->handle(
            app(CertbotClient::class), $files, $vhost, app(WebServerManager::class), app(ActivityLogger::class), $installers,
        );
        test()->fail('The failed vhost publication must preserve its original exception.');
    } catch (ProvisioningFailedException $exception) {
        expect($exception)->toBe($failure);
    }

    expect($urls)->toBe(['https://example.test', 'https://example.test'])
        ->and($certificate->fresh()->status)->toBe(CertificateStatus::Failed);
})->with([false, true]);
