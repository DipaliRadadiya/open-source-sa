<?php

namespace App\Services\Panel;

use App\Models\PanelUpdate;

/**
 * Reads the bounded, redacted tail of a detached panel-update log.
 *
 * Update commands can print paths and diagnostics that are useful to an
 * administrator, but the same stream can also contain credentials emitted by
 * a dependency or shell command. The browser never receives the raw file.
 */
class PanelUpdateOutput
{
    private const MAX_BYTES = 24 * 1024;

    /**
     * @return array{content: string, truncated: bool}
     */
    public function read(PanelUpdate $update): array
    {
        $path = rtrim((string) config('panel_update.state_dir'), '/')
            .'/update-'.$update->getKey().'.log';

        if (! is_file($path) || ! is_readable($path)) {
            return ['content' => '', 'truncated' => false];
        }

        $size = @filesize($path);

        if (! is_int($size)) {
            return ['content' => '', 'truncated' => false];
        }

        $offset = max(0, $size - self::MAX_BYTES);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['content' => '', 'truncated' => false];
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (! is_string($content)) {
            return ['content' => '', 'truncated' => false];
        }

        // Starting in the middle of a line makes the first line look like a
        // complete command. Drop that fragment while retaining the bounded tail.
        if ($offset > 0 && ($newline = strpos($content, "\n")) !== false) {
            $content = substr($content, $newline + 1);
        }

        return [
            'content' => $this->sanitize($content),
            'truncated' => $offset > 0,
        ];
    }

    private function sanitize(string $content): string
    {
        // Terminal colour/control sequences have no place in JSON or a browser.
        $content = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $content) ?? '';
        $content = preg_replace('/[^\P{C}\n\t]/u', '', $content) ?? '';

        $patterns = [
            '/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i' => '$1[REDACTED]',
            '/\b(Bearer)\s+[A-Za-z0-9._~+\/=:-]+/i' => '$1 [REDACTED]',
            '/\b((?:[A-Z0-9_]*)(?:PASSWORD|TOKEN|SECRET|API_KEY|APP_KEY)(?:[A-Z0-9_]*)=)[^\s]+/i' => '$1[REDACTED]',
            '/([?&](?:token|key|secret|password|signature)=)[^&\s]+/i' => '$1[REDACTED]',
            '/(Cookie:\s*)[^\r\n]+/i' => '$1[REDACTED]',
            '/(Set-Cookie:\s*)[^\r\n]+/i' => '$1[REDACTED]',
            '/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/is' => '[REDACTED PRIVATE KEY]',
        ];

        return trim((string) preg_replace(array_keys($patterns), array_values($patterns), $content));
    }
}
