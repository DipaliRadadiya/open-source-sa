<?php

// Standalone developer tool: deliberately no Laravel or Composer bootstrap.
try {
    $slug = $argv[1] ?? '';
    if ($argc !== 2 || ! preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $slug)) {
        throw new RuntimeException('Usage: php tests/Support/Recipes/template-from-golden.php <slug>');
    }

    $backend = dirname(__DIR__, 3);
    $golden = $backend.'/tests/Fixtures/recipes-golden/'.$slug;
    $read = static function (string $path): string {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Cannot read '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read '.$path);
        }

        return $contents;
    };
    $default = $read($golden.'/install-default.yml');
    $limits = $read($golden.'/install-limits.yml');
    $meta = json_decode($read($golden.'/meta.json'), true, 64, JSON_THROW_ON_ERROR);
    $recipe = json_decode($read($backend.'/resources/recipes/'.$slug.'/recipe.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($meta['slug'] ?? null) !== $slug || ($recipe['slug'] ?? null) !== $slug) {
        throw new RuntimeException('Metadata slug does not match '.$slug);
    }
    $appServices = array_values(array_filter($recipe['services'], static fn (array $service): bool => $service['role'] === 'app'));
    if (count($appServices) !== 1) {
        throw new RuntimeException('Exactly one app service is required.');
    }
    $appService = $appServices[0]['name'];
    $trailingNewline = str_ends_with($default, "\n");
    if (str_contains($default.$limits, "\r") || str_ends_with($limits, "\n") !== $trailingNewline) {
        throw new RuntimeException('Golden files must use LF and have matching trailing-newline states.');
    }
    $a = explode("\n", $trailingNewline ? substr($default, 0, -1) : $default);
    $b = explode("\n", $trailingNewline ? substr($limits, 0, -1) : $limits);
    $merged = [];
    $service = null;
    $cpuLines = 0;
    $memoryLines = 0;
    $j = 0;
    foreach ($a as $line) {
        if (preg_match('/^  ([a-z][a-z0-9_-]*):$/D', $line, $match)) {
            $service = $match[1];
        }
        if (($b[$j] ?? null) === '    cpus: 1.5') {
            if ($service !== $appService) {
                throw new RuntimeException('CPU limit is not on the app service.');
            }
            array_push($merged, '{{#if cpu_limit}}', '    cpus: {{ cpu_limit }}', '{{/if}}');
            $cpuLines++;
            $j++;
        }
        if ($line !== ($b[$j] ?? null)) {
            if ($service !== $appService || ! preg_match('/^    mem_limit: \d+(?:b|k|m|g)?$/iD', $line) || ($b[$j] ?? null) !== '    mem_limit: 640m') {
                throw new RuntimeException('Unexpected golden difference at default line '.(count($merged) + 1));
            }
            $line = '    mem_limit: {{ memory_limit }}';
            $memoryLines++;
        } elseif ($line === '    mem_limit: 512m' && $service !== $appService) {
            $line = '    mem_limit: {{ db_memory_limit }}';
        }
        $merged[] = $line;
        $j++;
    }
    if ($j !== count($b) || $cpuLines !== 1 || $memoryLines !== 1) {
        throw new RuntimeException('Expected exactly one app memory difference and one extra CPU line.');
    }

    $replacements = [];
    $sentinels = [];
    foreach ($meta['generated_secrets'] as $key) {
        $sentinel = substr(hash('sha256', 'golden|'.$slug.'|'.$key), 0, 32);
        $sentinels[] = $sentinel;
        $sentinels[] = base64_encode($sentinel);
        $replacements[$sentinel] = '{{ secret.'.$key.' }}';
        $replacements[base64_encode($sentinel)] = '{{ secret.'.$key.'|base64 }}';
    }
    foreach (array_keys($meta['volume_roles']) as $role) {
        $replacements['sv-app-4242_'.$role] = '{{ volume.'.$role.' }}';
    }
    $domain = str_replace('_', '-', $slug).'.golden.test';
    $replacements += [
        'sv-app-4242' => '{{ project }}',
        'https://'.$domain => '{{ url }}',
        $domain => '{{ domain }}',
        '/home/owner/site/public_html' => '{{ site_root }}',
        '"127.0.0.1:20101:'.$meta['container_port'].'"' => '"127.0.0.1:{{ app_port }}:{{ container_port }}"',
    ];
    foreach ($recipe['images'] as $role => $image) {
        $replacements['image: '.$image['ref']] = 'image: {{ image.'.$role.' }}';
    }
    // strtr with an array chooses the longest match first and never reprocesses replacements.
    $text = strtr(implode("\n", $merged), $replacements);
    $text = preg_replace('/^([ \t]+)$/m', '$1{{-- --}}', $text);
    if (preg_match('/[ \t]+$/m', $text)) {
        throw new RuntimeException('Generated template contains trailing whitespace.');
    }
    foreach (array_merge(['4242', '20101', 'golden.test'], $sentinels) as $needle) {
        if (str_contains($text, $needle)) {
            throw new RuntimeException('Generated template still contains a golden sentinel.');
        }
    }

    $header = "{{-- Managed by the panel. Manual edits are overwritten on the next deploy.\n     ".$meta['catalog']['en']['title'].": generated from the golden fixture by template-from-golden.php (RC-04). --}}\n";
    fwrite(STDOUT, $header.$text.($trailingNewline ? "\n" : ''));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
