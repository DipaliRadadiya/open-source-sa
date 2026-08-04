<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class SaveEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware already enforces both halves — the grant and
        // whether this site type has an environment file at all. Repeated here
        // only so a route registered later without the middleware still cannot
        // write to someone's site.
        return $this->user()?->canManage('app_environment') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The whole file, as text. Not a list of pairs: rebuilding the
            // file from pairs discards the comments, ordering and grouping
            // that are the user's, and they would never get them back.
            'raw' => ['required', 'string', 'max:262144'],
        ];
    }
}
