<?php

namespace App\Services\Runtime;

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use InvalidArgumentException;

/**
 * Database-specific progress around the shared apt phase detector.
 *
 * Package phases come from apt's own output. The surrounding operations are
 * explicit because systemd, repository setup and account provisioning do not
 * emit a stable machine-readable stream of their own.
 */
class DatabaseInstallProgress
{
    /** @var array<int, string> */
    public const STEPS = [
        'queued',
        'checking_conflicts',
        'preparing_repository',
        'updating_package_index',
        'preparing',
        'downloading',
        'unpacking',
        'configuring',
        'starting_service',
        'verifying_connection',
        'creating_panel_account',
    ];

    private InstallProgress $packages;

    public function __construct(private RuntimeInstall $install)
    {
        $this->packages = new InstallProgress($install);
    }

    public function step(string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new InvalidArgumentException("Unknown database install step [{$step}].");
        }

        $this->install->forceFill(['current_step' => $step])->save();
    }

    public function output(string $chunk): void
    {
        if ($this->packages->push($chunk)) {
            $this->packages->persist();
        }
    }

    /** Preserve apt's last words without overwriting a later explicit step. */
    public function flushOutput(): void
    {
        $this->packages->persistOutput();
    }

    /** @return array<string, mixed> */
    public static function describe(RuntimeInstall $install): array
    {
        $step = $install->current_step;

        return [
            ...$install->toProgress(),
            'current_step_title' => $step === null ? null : __("database.install_steps.{$step}"),
            'retryable' => $install->status === InstallStatus::Failed,
        ];
    }
}
