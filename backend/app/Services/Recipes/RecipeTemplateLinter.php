<?php

namespace App\Services\Recipes;

use App\Services\Recipes\Exceptions\InvalidRecipeException;

final class RecipeTemplateLinter
{
    public static function lint(array $data, RecipeTemplate $t): void
    {
        $fail = fn (string $code) => throw new InvalidRecipeException($data['slug'], $code);
        $names = $t->placeholders();
        if (in_array('app.name', [...$names, ...$t->conditions()], true)) {
            $fail('template_app_name');
        }
        $allowed = ['project', 'app_port', 'container_port', 'memory_limit', 'db_memory_limit', 'cpu_limit', 'url', 'domain', 'site_root'];
        foreach (['image' => 'images', 'volume' => 'volumes'] as $prefix => $key) {
            foreach (array_keys($data[$key] ?? []) as $role) {
                $allowed[] = "$prefix.$role";
            }
        }
        foreach ($data['secrets'] ?? [] as $s) {
            $allowed[] = 'secret.'.$s['key'];
        }
        foreach ($data['inputs'] ?? [] as $s) {
            $allowed[] = 'input.'.$s['name'];
        }
        if (array_diff([...$names, ...$t->conditions()], $allowed)) {
            $fail('template_unknown_placeholder');
        }
        foreach ($data['secrets'] ?? [] as $s) {
            $used = in_array('secret.'.$s['key'], $names, true);
            if ($used && $s['panel_only']) {
                $fail('template_panel_only_secret_rendered');
            }
            if (! $used && ! $s['panel_only']) {
                $fail('template_unused_secret');
            }
        }
        foreach (['volume' => 'volumes', 'image' => 'images'] as $prefix => $key) {
            foreach (array_keys($data[$key] ?? []) as $role) {
                if (! in_array("$prefix.$role", $names, true)) {
                    $fail('template_unused_'.$prefix);
                }
            }
        }
        $lines = array_column($t->bodyLines(), 'text');
        $text = implode("\n", $lines);
        $urlLines = array_values(array_filter($lines, fn ($l) => preg_match('/\{\{\s*url\s*\}\}/', $l)));
        if ((bool) $urlLines !== ! empty($data['url_env_key'])) {
            $fail('template_url_mismatch');
        }
        foreach ($urlLines as $line) {
            if (! preg_match('/^\s*'.preg_quote($data['url_env_key'], '/').':/', $line)) {
                $fail('template_url_mismatch');
            }
        }
        if (preg_match_all('/^\s*ports:\s*$/m', $text) !== 1 || count(array_filter($lines, fn ($l) => trim($l) === '- "127.0.0.1:{{ app_port }}:{{ container_port }}"')) !== 1) {
            $fail('template_publish');
        }
        if (preg_match('/^\s*(privileged|cap_add|devices|pid|ipc|userns_mode|security_opt|network_mode|cgroup_parent|build)\s*:/m', $text) || str_contains($text, 'docker.sock')) {
            $fail('template_forbidden_key');
        }
        if (preg_match('/(?<!\$)\$(?!\$)/', $text)) {
            $fail('template_dollar');
        }
        foreach ($data['secrets'] ?? [] as $s) {
            if ($s['complexity'] === 'complex' && ! $s['panel_only']) {
                foreach ($lines as $line) {
                    if (preg_match('/\{\{\s*secret\.'.preg_quote($s['key'], '/').'\s*(?:\|\s*base64\s*)?\}\}/', $line) && ! preg_match('/"[^"]*\{\{\s*secret\.'.preg_quote($s['key'], '/').'\s*(?:\|\s*base64\s*)?\}\}[^\"]*"/', $line)) {
                        $fail('template_unquoted_complex_secret');
                    }
                }
            }
        }
        if (count(array_filter($t->conditions(), fn ($name) => $name === 'cpu_limit')) !== 1) {
            $fail('template_cpu');
        }
        $block = null;
        $cpu = 0;
        foreach (explode("\n", preg_replace('/\{\{--.*?--\}\}/s', '', $t->source())) as $line) {
            if (preg_match('/^\s*\{\{#if ([a-z_]+(?:\.[A-Za-z0-9_-]+)?)\}\}\s*$/', $line, $m)) {
                $block = $m[1];
            } elseif (preg_match('/^\s*\{\{\/if\}\}\s*$/', $line)) {
                $block = null;
            } elseif (preg_match('/\{\{\s*cpu_limit\s*(?:\|[^}]*)?\}\}/', $line)) {
                if ($block !== 'cpu_limit') {
                    $fail('template_cpu');
                }
                $cpu++;
            }
        }
        if ($cpu !== 1) {
            $fail('template_cpu');
        }
        $count = count($data['services']);
        foreach (['image:', 'restart: unless-stopped', 'mem_limit:', 'max-size:'] as $key) {
            if (preg_match_all('/^\s*'.preg_quote($key, '/').'/m', $text) !== $count) {
                $fail('template_limits');
            }
        }
        foreach (['project', 'memory_limit', 'app_port'] as $key) {
            if (! in_array($key, $names, true)) {
                $fail('template_required');
            }
        }
    }
}
