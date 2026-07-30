<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * What the PHP screen shows: every installed version with the facts a user
 * decides on — whether it is the default, how many sites pin it, whether the
 * panel itself runs on it — plus what could still be installed.
 *
 * PHP is one feature here rather than three. It used to be split across the
 * Services screen (the ini editor, gated by `service`) and the Settings
 * screen (versions and extensions, gated by `setting`), which meant managing
 * PHP required both permissions — and `setting` also grants the SSH port and
 * the reboot button. You could not let someone change a PHP version without
 * also letting them reboot the server.
 */
class PhpOverview
{
    public function __construct(
        private PhpRuntime $runtime,
        private PhpVersionManager $versions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $inUse = Application::query()
            ->whereNotNull('php_version')
            ->selectRaw('php_version, count(*) as total')
            ->groupBy('php_version')
            ->pluck('total', 'php_version');

        return [
            'default' => $this->runtime->default(),
            'panel_version' => $this->runtime->panelVersion(),
            'versions' => array_map(fn (array $version) => [
                ...$version,
                'in_use_by' => (int) ($inUse[$version['version']] ?? 0),
                // The FPM unit stays on the Services screen — starting and
                // stopping a daemon is the same job there as for nginx or
                // redis. Named here so the two screens can link to each other.
                'service' => "php{$version['version']}-fpm",
                'ini_path' => $this->versions->iniPath($version['version']),
            ], $this->runtime->versions()),
            'installable' => $this->runtime->installable(),
        ];
    }
}
