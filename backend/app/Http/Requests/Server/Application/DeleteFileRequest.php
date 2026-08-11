<?php

namespace App\Http\Requests\Server\Application;

use App\Http\Requests\Server\Application\Concerns\AcceptsManyPaths;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DeleteFileRequest extends FormRequest
{
    use AcceptsManyPaths;

    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->pathRules(), [
            // A deliberate second field, not just hitting the URL — a floor
            // against firing this by accident. Deleting one file/folder does
            // not need the heavier "type the name" guard Restore uses for a
            // whole-site operation, but it needs more than a bare request.
            'confirm' => ['required', 'accepted'],

            // The caller states how many it believes it is deleting, and the
            // request is refused if that disagrees with the selection. A
            // wrong selection is the realistic failure for a bulk delete —
            // one stale checkbox and 200 files go — and `confirm` alone
            // cannot catch it because it is true either way.
            'count' => ['required_with:paths', 'integer'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $paths = $this->input('paths');

            if (is_array($paths) && (int) $this->input('count') !== count(array_unique($paths))) {
                $validator->errors()->add('count', __('errors/application.bulk_count_mismatch'));
            }
        });
    }

    public function targetPath(): string
    {
        return (string) $this->validated('path');
    }
}
