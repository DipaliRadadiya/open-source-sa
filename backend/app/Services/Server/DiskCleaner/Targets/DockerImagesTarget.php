<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/**
 * Docker images no container uses, and Docker's build cache (2026-10-29,
 * Dipali: deleted Docker apps left their images behind — 58 GB on one
 * server — and nothing on this screen could reclaim them).
 *
 * `docker image prune --all` removes only images with no container at all,
 * running or stopped, so a stopped app keeps its image and starts again
 * without downloading it. Volumes — the apps' data — and networks are never
 * touched here. Everything it removes can be downloaded again.
 */
class DockerImagesTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'docker_images';
    }

    public function group(): string
    {
        return 'package';
    }

    public function method(): string
    {
        return 'command';
    }

    public function available(): bool
    {
        return is_file('/usr/bin/docker');
    }

    public function paths(): array
    {
        return ['docker: images no container uses, and the build cache'];
    }

    /** An image has no age to keep by here: this category always removes all. */
    public function keepsDays(): bool
    {
        return false;
    }

    /** One listing, the same answer for every choice. */
    public function estimates(array $choices): array
    {
        return array_fill_keys($choices, $this->estimate());
    }

    /**
     * What `docker system df` calls reclaimable for images and the build
     * cache — exactly what the two prunes below remove. Zero when Docker does
     * not answer: the card says nothing rather than a guess.
     */
    public function estimate(?int $keepDays = null): int
    {
        $output = $this->serverOps->run(
            ['docker', 'system', 'df', '--format', '{{json .}}'],
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
            60,
        )->output();

        $bytes = 0;

        foreach (explode("\n", trim($output)) as $line) {
            $row = json_decode(trim($line), true);

            if (! is_array($row) || ! in_array($row['Type'] ?? '', ['Images', 'Build Cache'], true)) {
                continue;
            }

            // "1.234GB (45%)", "512.5MB", "0B" — decimal units, as Docker prints them.
            if (preg_match('/^([\d.]+)\s*([kKMGT]?B)/', (string) ($row['Reclaimable'] ?? ''), $m) === 1) {
                $bytes += $this->siBytes($m[1], $m[2] === 'kB' ? 'KB' : $m[2]);
            }
        }

        return $bytes;
    }

    /**
     * Images first, then the build cache. Never `--volumes`, and never
     * `system prune`, which would take stopped containers and networks too.
     */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        $context = ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()];
        $images = $this->serverOps->run(['docker', 'image', 'prune', '--all', '--force'], $context, 600);

        if ($images->failed()) {
            return $images;
        }

        return $this->serverOps->run(['docker', 'builder', 'prune', '--all', '--force'], $context, 600);
    }
}
