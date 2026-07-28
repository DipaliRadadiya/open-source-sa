<?php

namespace App\Http\Requests\Server\Git;

use Illuminate\Foundation\Http\FormRequest;

class ListBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('git') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // owner/repo (GitLab allows nested groups). Constrained so the
            // value can never escape the provider's URL path — the second
            // rule rejects `.` and `..` segments, which the first one would
            // otherwise accept (a dot is legal inside a repository name).
            'repository' => [
                'required', 'string', 'max:255',
                'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+$/',
                'not_regex:/(^|\/)\.{1,2}(\/|$)/',
            ],
        ];
    }
}
