<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * The last gate before anything is destroyed.
 *
 * Two questions, both cheap, both worth asking here rather than three steps
 * later: is this the number of bytes we uploaded, and is it actually a
 * readable archive? A truncated or corrupt download discovered *after* the
 * database has been dropped is the difference between "the restore failed" and
 * "the site is gone".
 *
 * `tar -tzf` reads the whole archive without extracting anything, so it costs
 * one pass over a file already on local disk and catches every corruption that
 * a size check cannot.
 */
class VerifyDownload implements RestoreStep
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'verify_download';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return true;
    }

    public function run(RestoreContext $context): void
    {
        $archive = $context->archivePath;

        if ($archive === null || ! is_file($archive)) {
            throw new RuntimeException('there is no downloaded archive to verify');
        }

        $expected = (int) $context->backup->size_bytes;
        $actual = (int) filesize($archive);

        if ($expected > 0 && $actual !== $expected) {
            throw new RuntimeException(
                "downloaded {$actual} bytes but the backup recorded {$expected}",
            );
        }

        $result = $this->serverOps->run(
            ['tar', '-tzf', $archive],
            ['feature' => 'backup', 'op' => 'restore_verify', 'application' => $context->application->id],
            timeout: 1800,
        );

        if ($result->failed()) {
            throw new RuntimeException('the downloaded archive is not readable');
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        //
    }
}
