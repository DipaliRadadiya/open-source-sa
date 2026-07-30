<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Models\Application;
use App\Services\Server\Runtimes\NodeRuntime;

/**
 * The Runtimes → Node section of the Settings screen.
 *
 * Only one thing here is a setting in the sense the other groups mean it —
 * which version bare `node` resolves to. Installing and removing versions are
 * operations, not form fields: they take minutes and run on the queue, so they
 * have their own endpoints rather than being pretended into a `PUT`.
 *
 * Always available, even with no Node at all: "nothing is installed" is what
 * the screen needs to say in order to offer installing something.
 */
class NodeSettings implements SettingGroup
{
    public function __construct(private NodeRuntime $node) {}

    public function key(): string
    {
        return 'node';
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
            ->whereNotNull('node_version')
            ->selectRaw('node_version, count(*) as total')
            ->groupBy('node_version')
            ->pluck('total', 'node_version');

        return [
            'manager' => $this->node->manager(),
            'default' => $this->node->default(),
            'versions' => array_map(fn (array $version) => [
                ...$version,
                // How many sites pin this version — what makes removing it
                // refusable rather than a surprise.
                'in_use_by' => (int) ($inUse[$version['version']] ?? 0),
            ], $this->node->versions()),
            // Whatever was on the box before the panel. Usable, never touched.
            'system' => $this->node->system(),
            'installable' => $this->node->installable(),
        ];
    }

    /**
     * The only real setting: which version bare `node` points at.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $this->node->setDefault((string) $data['default']);
    }
}
