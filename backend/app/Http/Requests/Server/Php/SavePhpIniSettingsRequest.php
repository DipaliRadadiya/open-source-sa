<?php

namespace App\Http\Requests\Server\Php;

use App\Services\Server\Php\PhpIniDirectives;
use App\Services\Server\Php\PhpVersionManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * RC-8: the common php.ini settings of one PHP version, as a form. The same
 * vocabulary as the per-application PHP settings (`128M`, `1G`, `-1`).
 */
class SavePhpIniSettingsRequest extends FormRequest
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
        $size = ['sometimes', 'string', 'regex:/^(-1|\d+[KMG]?)$/i', 'max:12'];

        return [
            'memory_limit' => $size,
            'upload_max_filesize' => $size,
            'post_max_size' => $size,
            'max_execution_time' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'max_input_time' => ['sometimes', 'integer', 'min:-1', 'max:3600'],
            'max_input_vars' => ['sometimes', 'integer', 'min:100', 'max:100000'],
        ];
    }

    /**
     * An upload travels inside the POST body, so a post_max_size below
     * upload_max_filesize makes every upload between the two fail — judged
     * against what the file will say after the save, not just this request.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['post_max_size', 'upload_max_filesize'])
                || ! $this->hasAny(['post_max_size', 'upload_max_filesize'])) {
                return;
            }

            $current = app(PhpIniDirectives::class)->read(app(PhpVersionManager::class)->readIni((string) $this->route('version')));
            // PHP's own defaults when the file sets neither.
            $postValue = (string) ($this->input('post_max_size') ?? $current['post_max_size'] ?? '8M');
            $uploadValue = (string) ($this->input('upload_max_filesize') ?? $current['upload_max_filesize'] ?? '2M');
            $post = self::bytes($postValue);
            $upload = self::bytes($uploadValue);

            if ($post !== -1 && ($upload === -1 || $post < $upload)) {
                $validator->errors()->add('post_max_size', __('php_settings.errors.post_below_upload', ['post' => $postValue, 'upload' => $uploadValue]));
            }
        }];
    }

    /** `128M` → bytes; -1 for unlimited. */
    public static function bytes(string $value): int
    {
        $value = strtoupper(trim($value));

        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (substr($value, -1)) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /** @return array<string, string|int> */
    public function changes(): array
    {
        return array_map(fn ($value) => is_string($value) ? strtoupper($value) : $value, $this->validated());
    }
}
