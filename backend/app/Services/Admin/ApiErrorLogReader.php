<?php

namespace App\Services\Admin;

use SplFileObject;

class ApiErrorLogReader
{
    public const DEFAULT_LINES = 100;
    public const MAX_LINES = 500;

    /** @return array{entries: array<int, array<string, mixed>>, truncated: bool} */
    public function latest(int $lines, ?string $reference = null): array
    {
        $lines = max(1, min($lines, self::MAX_LINES));
        $path = (string) config('logging.channels.server-ops.path');
        $directory = dirname($path);
        $prefix = pathinfo($path, PATHINFO_FILENAME);
        $files = glob("{$directory}/{$prefix}-*.log") ?: [];
        // Tests and a deliberately overridden deployment may use `single`.
        // Include that base path too; daily remains the production default.
        if (is_file($path)) {
            $files[] = $path;
        }
        $files = array_values(array_unique($files));
        rsort($files, SORT_STRING);

        $entries = [];
        foreach ($files as $file) {
            $matches = $this->matches($file, $reference, $lines - count($entries));

            if ($reference !== null && $matches !== []) {
                return ['entries' => $matches, 'truncated' => false];
            }

            foreach (array_reverse($matches) as $entry) {
                $entries[] = $entry;
                if (count($entries) >= $lines) {
                    return ['entries' => $entries, 'truncated' => true];
                }
            }
        }

        return ['entries' => $entries, 'truncated' => false];
    }

    /**
     * Read one daily file one line at a time. Server operations are high-volume,
     * so loading a file into memory just to render an admin page is unsafe.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matches(string $file, ?string $reference, int $limit): array
    {
        $matches = [];
        $handle = new SplFileObject($file, 'r');

        while (! $handle->eof()) {
            $entry = json_decode((string) $handle->fgets(), true);
            if (! is_array($entry) || ($entry['level_name'] ?? null) !== 'ERROR') {
                continue;
            }

            if ($reference !== null && ($entry['context']['reference'] ?? null) !== $reference) {
                continue;
            }

            $matches[] = $entry;
            if ($reference !== null) {
                return $matches;
            }

            if (count($matches) > $limit) {
                array_shift($matches);
            }
        }

        return $matches;
    }
}
