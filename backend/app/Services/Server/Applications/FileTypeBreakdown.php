<?php

namespace App\Services\Server\Applications;

/**
 * What kind of file this is, from its name.
 *
 * By extension on purpose: sniffing with `file` is one exec per file, which on a
 * `node_modules` is a hundred thousand processes for one chart. The categories answer
 * "what is eating the disk".
 */
class FileTypeBreakdown
{
    /**
     * Extension => category. Lower-case, no leading dot.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        // Images
        'jpg' => 'images', 'jpeg' => 'images', 'png' => 'images', 'gif' => 'images',
        'webp' => 'images', 'svg' => 'images', 'ico' => 'images', 'bmp' => 'images',
        'avif' => 'images', 'tif' => 'images', 'tiff' => 'images', 'heic' => 'images',

        // Video
        'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'mkv' => 'video',
        'webm' => 'video', 'flv' => 'video', 'wmv' => 'video', 'm4v' => 'video',

        // Audio
        'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'flac' => 'audio',
        'aac' => 'audio', 'm4a' => 'audio', 'wma' => 'audio',

        // Code and markup
        'php' => 'code', 'js' => 'code', 'mjs' => 'code', 'cjs' => 'code',
        'ts' => 'code', 'tsx' => 'code', 'jsx' => 'code', 'css' => 'code',
        'scss' => 'code', 'sass' => 'code', 'less' => 'code', 'html' => 'code',
        'htm' => 'code', 'vue' => 'code', 'py' => 'code', 'rb' => 'code',
        'go' => 'code', 'rs' => 'code', 'java' => 'code', 'sh' => 'code',
        'map' => 'code', 'json' => 'code', 'xml' => 'code', 'yml' => 'code',
        'yaml' => 'code', 'toml' => 'code', 'ini' => 'code', 'env' => 'code',
        'lock' => 'code', 'twig' => 'code', 'blade' => 'code',

        // Documents
        'pdf' => 'documents', 'doc' => 'documents', 'docx' => 'documents',
        'xls' => 'documents', 'xlsx' => 'documents', 'ppt' => 'documents',
        'pptx' => 'documents', 'txt' => 'documents', 'md' => 'documents',
        'csv' => 'documents', 'rtf' => 'documents', 'odt' => 'documents',

        // Archives
        'zip' => 'archives', 'gz' => 'archives', 'tgz' => 'archives',
        'bz2' => 'archives', 'xz' => 'archives', 'tar' => 'archives',
        'rar' => 'archives', '7z' => 'archives', 'zst' => 'archives',

        // Databases and dumps
        'sql' => 'database', 'sqlite' => 'database', 'db' => 'database',
        'dump' => 'database',

        // Logs
        'log' => 'logs',

        // Fonts
        'woff' => 'fonts', 'woff2' => 'fonts', 'ttf' => 'fonts',
        'otf' => 'fonts', 'eot' => 'fonts',
    ];

    /** Everything that matched nothing above. Its own answer, not a guess. */
    public const OTHER = 'other';

    /**
     * The category of one filename.
     *
     * A dotfile with no other dot (`.env`, `.gitignore`) has no extension: its leading dot is
     * not a separator.
     */
    public function categorise(string $name): string
    {
        $base = ltrim($name, '.');

        if (! str_contains($base, '.')) {
            return self::OTHER;
        }

        $extension = strtolower(substr($base, strrpos($base, '.') + 1));

        return self::CATEGORIES[$extension] ?? self::OTHER;
    }

    /**
     * Total bytes and file count per category, largest first.
     *
     * `other` is sorted with the rest, not pinned last: if unrecognised files fill the disk,
     * that is the answer.
     *
     * @param  iterable<array{name: string, size: int}>  $files
     * @return array<int, array{key: string, bytes: int, count: int}>
     */
    public function summarise(iterable $files): array
    {
        $totals = [];

        foreach ($files as $file) {
            $key = $this->categorise($file['name']);

            $totals[$key] ??= ['key' => $key, 'bytes' => 0, 'count' => 0];
            $totals[$key]['bytes'] += $file['size'];
            $totals[$key]['count']++;
        }

        $summary = array_values($totals);

        usort($summary, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $summary;
    }
}
