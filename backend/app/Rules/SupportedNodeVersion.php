<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a Node version the chosen application will not run on.
 *
 * The panel offered every installed Node version for every Node site type,
 * because the field is one shared `nodeFields()` select and nothing narrowed
 * it. Pick one outside the application's supported range and the site is
 * created, reported active, and then serves nothing: n8n **refuses to start**
 * on an unsupported version ("Your Node.js version is currently not supported
 * by n8n") and exits, which reaches the browser as a 502 from a reverse proxy
 * pointed at a port nobody is listening on.
 *
 * Caught here rather than at install time because by then the system user,
 * the directory, the vhost and possibly a database all exist — refusing at the
 * form is the difference between an error message and a half-built site to
 * clean up.
 *
 * Comparison is `version_compare` against the major (or major.minor) the
 * project publishes, so a floor of `22` admits every 22.x, 23.x and 24.x, and
 * a ceiling of `24` admits all of 24.x while refusing 25.
 */
class SupportedNodeVersion implements ValidationRule
{
    public function __construct(
        private ?string $min,
        private ?string $max,
        private string $typeTitle,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // `nullable` handles the absent case: no version chosen means the
        // server's own Node, which is not this rule's question.
        if (blank($value)) {
            return;
        }

        $version = (string) $value;

        if ($this->min !== null && version_compare($version, $this->min, '<')) {
            $fail($this->message());

            return;
        }

        // The ceiling is a major series, not a point release: `24` must admit
        // `24.7.0`. Comparing the majors alone is what makes that true without
        // pinning a ceiling that goes stale on every patch release.
        if ($this->max !== null && $this->major($version) > $this->major($this->max)) {
            $fail($this->message());
        }
    }

    private function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    private function message(): string
    {
        return __('validation.node_version_unsupported', [
            'type' => $this->typeTitle,
            'range' => $this->range(),
        ]);
    }

    /**
     * Said the way the project says it, because "invalid" tells the user
     * nothing about what to pick instead.
     */
    private function range(): string
    {
        return match (true) {
            $this->min !== null && $this->max !== null => "{$this->min} – {$this->max}",
            $this->min !== null => "{$this->min}+",
            default => "≤ {$this->max}",
        };
    }
}
