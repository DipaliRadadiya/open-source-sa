<?php

namespace App\Http\Requests\Admin;

use App\Services\Activity\ActivityRetention;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How long the activity log is kept: one of the fixed choices in months, or
 * null for "keep everything". The route is behind `access-admin`, as the
 * admin-wide log is: the choice deletes every user's history.
 */
class ActivityRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'months' => ['present', 'nullable', 'integer', Rule::in(ActivityRetention::CHOICES)],
        ];
    }

    public function months(): ?int
    {
        $value = $this->validated('months');

        return $value === null ? null : (int) $value;
    }
}
