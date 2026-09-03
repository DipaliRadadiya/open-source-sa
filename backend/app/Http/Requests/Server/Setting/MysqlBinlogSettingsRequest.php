<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class MysqlBinlogSettingsRequest extends FormRequest
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
            // Zero is "keep forever", which is the default and the dangerous
            // one — allowed, because refusing it would mean the panel cannot
            // represent the state a server is already in, but the form warns.
            // The ceiling is a year: past that the setting is not retention,
            // it is a disk-usage plan nobody made.
            'expire_seconds' => ['required', 'integer', 'min:0', 'max:31536000'],
            // MySQL's own minimum is 4 KB and it rounds to a multiple of it.
            // 1 GB ceiling: a larger single log makes purging coarse, since
            // the server can only drop whole files.
            'max_binlog_size' => ['required', 'integer', 'min:4096', 'max:1073741824'],
        ];
    }
}
