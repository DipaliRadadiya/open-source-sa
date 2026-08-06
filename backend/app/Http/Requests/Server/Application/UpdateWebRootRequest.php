<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebRootRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Same rule as the one on the generic application update, and for
            // the same reason: this string is composed into a path that
            // `mkdir`, `chown` and the vhost writer all handle with elevated
            // privileges. The charset keeps it to a relative path fragment and
            // `not_regex` keeps `..` out of it — a traversal here would hand
            // the site's user a directory outside its own home.
            'web_root' => ['present', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
        ];
    }

    public function webRoot(): ?string
    {
        return $this->validated('web_root');
    }
}
