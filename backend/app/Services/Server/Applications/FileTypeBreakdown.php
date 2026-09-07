<?php

namespace App\Services\Server\Applications;

/**
 * What kind of file this is, from its name.
 *
 * By extension, deliberately, and not by asking `file` what the bytes are.
 * A real mime sniff is one exec per file; on a site with a `node_modules` that
 * is a hundred thousand processes to answer one chart. The extension is what
 * the name claims, which is what somebody looking at a directory is reasoning
 * about anyway.
 *
 * The categories exist to answer "what is eating the disk" — so `code` covers
 * the many small files a dependency tree is made of, and `media` splits into
 * images/video/audio because those are the three that usually explain a large
 * number.
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
     * A dotfile with no other dot (`.env`, `.gitignore`) has no extension in
     * the sense that matters: its leading dot is not a separator. Treating it
     * as one would file `.gitignore` under an invented `gitignore` type.
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
     * `other` is sorted with the rest rather than pinned last: if unrecognised
     * files are what is filling the disk, that is the answer, and burying it
     * under a "miscellaneous" convention would hide it.
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
