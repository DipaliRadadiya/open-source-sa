<?php

namespace App\Http\Requests\Server\Addons;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Enabling Object Cache Pro: the customer's licence token and the plugin
 * download Central provides, plus the plugin's optional tuning. The Redis
 * connection is not taken from the caller -- the panel creates it.
 */
class ObjectCacheProRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('central_authenticated') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,128}$/'],
            'plugin_url' => ['required', 'string', 'max:2048', 'regex:/^https:\/\/\S+$/'],
            'prefetch' => ['sometimes', 'boolean'],
            'split_alloptions' => ['sometimes', 'boolean'],
            'strict' => ['sometimes', 'boolean'],
            'debug' => ['sometimes', 'boolean'],
            'maxttl' => ['sometimes', 'integer', 'min:0'],
            'timeout' => ['sometimes', 'numeric', 'gt:0', 'max:60'],
            'read_timeout' => ['sometimes', 'numeric', 'gt:0', 'max:60'],
        ];
    }
}
