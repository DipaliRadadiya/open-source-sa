<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * The Runtimes → PHP section of the Settings screen.
 *
 * Same shape as the Node group and for the same reason: which version bare
 * `php` resolves to is a setting; installing and removing versions are
 * operations that take minutes and run on the queue.
 *
 * Editing a version's ini stays on the Services screen, where the FPM units
 * are. Services answers "what is running"; this answers "what is installed".
 */
class PhpSettings implements SettingGroup
{
    public function __construct(private PhpRuntime $php) {}

    public function key(): string
    {
        return 'php';
    }

    public function available(): bool
    {
        return true;
    }

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
            'manager' => $this->php->manager(),
            'default' => $this->php->default(),
            'versions' => array_map(fn (array $version) => [
                ...$version,
                'in_use_by' => (int) ($inUse[$version['version']] ?? 0),
            ], $this->php->versions()),
            'installable' => $this->php->installable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $this->php->setDefault((string) $data['default']);
    }
}
