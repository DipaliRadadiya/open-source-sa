<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atomically changes one value in an application's existing config file.
 *
 * The whole file may contain credentials, so its contents never enter argv or
 * the server-ops log: they are read into panel memory and written over stdin.
 * Ownership and mode are copied from the original before the atomic rename.
 */
class ApplicationConfigMutator
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @param  callable(string): string  $transform
     *
     * @throws ProvisioningFailedException
     */
    public function transform(Application $application, string $path, callable $transform): bool
    {
        $context = [
            'feature' => 'application',
            'op' => 'sync_url',
            'application' => $application->id,
            'path' => $path,
        ];

        $current = $this->serverOps->run(['cat', $path], $context, timeout: 30);

        if ($current->failed()) {
            throw new ProvisioningFailedException('sync_url', $current->reference);
        }

        try {
            $contents = $transform($current->output());
        } catch (Throwable) {
            throw new ProvisioningFailedException('sync_url', (string) Str::uuid());
        }

        if ($contents === $current->output()) {
            return false;
        }

        $temporary = $path.'.panel-tmp-'.Str::uuid();
        $written = $this->serverOps->run(['tee', $temporary], $context, timeout: 30, input: $contents);

        if ($written->failed()) {
            throw new ProvisioningFailedException('sync_url', $written->reference);
        }

        foreach ([
            ['chown', '--reference='.$path, $temporary],
            ['chmod', '--reference='.$path, $temporary],
        ] as $command) {
            $result = $this->serverOps->run($command, $context, timeout: 15);

            if ($result->failed()) {
                $this->serverOps->run(['rm', '-f', $temporary], $context, timeout: 15);

                throw new ProvisioningFailedException('sync_url', $result->reference);
            }
        }

        $moved = $this->serverOps->run(['mv', $temporary, $path], $context, timeout: 15);

        if ($moved->failed()) {
            $this->serverOps->run(['rm', '-f', $temporary], $context, timeout: 15);

            throw new ProvisioningFailedException('sync_url', $moved->reference);
        }

        return true;
    }
}
