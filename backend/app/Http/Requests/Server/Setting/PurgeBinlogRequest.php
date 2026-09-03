<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class PurgeBinlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A floor of one day, not zero. Purging up to "now" discards logs
            // a replica may not have read yet and the most recent window a
            // point-in-time recovery would need — an unrecoverable action
            // offered as a convenience. A day of headroom makes it a cleanup
            // rather than a data-loss button.
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
