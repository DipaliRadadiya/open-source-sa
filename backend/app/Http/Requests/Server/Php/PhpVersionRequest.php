<?php

namespace App\Http\Requests\Server\Php;

use App\Services\Server\Runtimes\PhpRuntime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class PhpVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('php');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // major.minor only — PHP packages are named that way, and the
            // value reaches both a package name and a path.
            'version' => ['required', 'string', 'regex:/^\d+\.\d+$/', 'bail', $this->inPackageIndex(...)],
        ];
    }

    /**
     * A version must be on the box already or in its package index.
     *
     * Checked here, not left to apt: the format rule alone let `8.1` through on
     * an OpenLiteSpeed server whose repository has no `lsphp81`, and the answer
     * came back minutes later from a queued job as "check the PHP repository is
     * configured" — about a repository that was fine. The list is the one the
     * PHP screen offers, so the screen and this endpoint cannot disagree.
     *
     * An installed version passes without the index: asking again is how a
     * half-installed one gets completed.
     */
    private function inPackageIndex(string $attribute, mixed $value, Closure $fail): void
    {
        $php = app(PhpRuntime::class);
        $version = (string) $value;

        if ($php->installed($version) || in_array($version, $php->installable(), true)) {
            return;
        }

        $fail(__('php.not_installable', ['version' => $version]));
    }
}
