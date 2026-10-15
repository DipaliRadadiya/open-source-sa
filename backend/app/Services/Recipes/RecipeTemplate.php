<?php

namespace App\Services\Recipes;

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\Exceptions\RecipeRenderException;

final class RecipeTemplate
{
    private const TOKEN = '/\{\{\s*([a-z_]+(?:\.[A-Za-z0-9_-]+)?)\s*(?:\|\s*([a-z0-9_]+)\s*)?\}\}/';

    private const OPEN = '/^[ \t]*\{\{#if ([a-z_]+(?:\.[A-Za-z0-9_-]+)?)\}\}[ \t]*$/';

    private string $clean;

    private array $names = [];

    private array $ifs = [];

    private array $body = [];

    private function __construct(private string $raw, private string $slug) {}

    public static function fromString(string $source, string $slug): self
    {
        $t = new self($source, $slug);
        $t->clean = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $block = null;
        foreach (explode("\n", $t->clean) as $i => $line) {
            if (preg_match(self::OPEN, $line, $m)) {
                if ($block !== null) {
                    $t->syntax();
                }
                $block = $m[1];
                $t->ifs[] = $block;

                continue;
            }
            if (preg_match('/^[ \t]*\{\{\/if\}\}[ \t]*$/', $line)) {
                if ($block === null) {
                    $t->syntax();
                }
                $block = null;

                continue;
            }
            $t->body[] = ['line' => $i + 1, 'text' => $line];
            preg_match_all(self::TOKEN, $line, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $t->names[] = $m[1];
            }
            if (str_contains(preg_replace(self::TOKEN, '', $line), '{{')) {
                $t->syntax();
            }
        }
        if ($block !== null || str_contains($source, "\r")) {
            $t->syntax();
        }

        return $t;
    }

    private function syntax(): never
    {
        throw new InvalidRecipeException($this->slug, 'template_syntax');
    }

    public function source(): string
    {
        return $this->raw;
    }

    public function placeholders(): array
    {
        return array_values(array_unique($this->names));
    }

    public function conditions(): array
    {
        return $this->ifs;
    }

    public function bodyLines(): array
    {
        return $this->body;
    }

    /** Names substituted in the active branches, not merely declared in the source. */
    public function activePlaceholders(array $values): array
    {
        preg_match_all(self::TOKEN, $this->selectedText($values), $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_column($matches, 1)));
    }

    private function selectedText(array $values): string
    {
        $lines = [];
        $keep = true;
        foreach (explode("\n", $this->clean) as $line) {
            if (preg_match(self::OPEN, $line, $m)) {
                if (! array_key_exists($m[1], $values)) {
                    throw new RecipeRenderException($m[1]);
                }
                $keep = $values[$m[1]] !== null && $values[$m[1]] !== '';

                continue;
            }
            if (preg_match('/^[ \t]*\{\{\/if\}\}[ \t]*$/', $line)) {
                $keep = true;

                continue;
            }
            if ($keep) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public function render(array $values): string
    {
        $text = $this->selectedText($values);
        $output = preg_replace_callback(self::TOKEN, function ($m) use ($values) {
            $name = $m[1];
            if (! array_key_exists($name, $values)) {
                throw new RecipeRenderException($name);
            }
            $value = (string) $values[$name];
            if (preg_match('/[\r\n]/', $value)) {
                throw new RecipeRenderException($name);
            }
            if (isset($m[2])) {
                if ($m[2] !== 'base64') {
                    throw new RecipeRenderException($name);
                }
                $value = base64_encode($value);
                if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $value) !== 1) {
                    throw new RecipeRenderException($name);
                }
            }

            return $value;
        }, $text);
        // Compare before ltrim: header comments legitimately leave leading newlines.
        if (str_contains($output, "\r") || str_contains($output, '{{') || substr_count($output, "\n") !== substr_count($text, "\n")) {
            throw new RecipeRenderException('template');
        }

        return ltrim($output);
    }
}
