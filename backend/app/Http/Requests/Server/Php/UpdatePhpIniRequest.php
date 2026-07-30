<?php

namespace App\Http\Requests\Server\Php;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePhpIniRequest extends FormRequest
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
            'contents' => ['required', 'string', 'max:'.(int) config('server.php_ini_max_bytes', 262144)],
            // The client must state that it understands the impact, mirroring
            // the confirmation the UI shows. A raw ini edit can stop PHP from
            // starting, so it should never be reachable by an accidental
            // request.
            'acknowledged' => ['accepted'],
        ];
    }
}
