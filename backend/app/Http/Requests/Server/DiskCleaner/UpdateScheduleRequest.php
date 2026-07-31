<?php

namespace App\Http\Requests\Server\DiskCleaner;

use App\Models\DiskCleanerSchedule;
use App\Services\Server\DiskCleaner\DiskCleaner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('disk_cleaner') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Automation is safe-only: schedulable categories are the safe,
        // available targets (never caution categories, never client paths).
        $safe = collect(app(DiskCleaner::class)->targets())
            ->filter(fn ($target) => $target->safe() && $target->available())
            ->map(fn ($target) => $target->key())
            ->values()
            ->all();

        return [
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(DiskCleanerSchedule::FREQUENCIES)],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => [Rule::in($safe)],
            'threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
