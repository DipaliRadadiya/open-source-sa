<?php

namespace App\Http\Requests\Server\DiskCleaner;

use App\Models\DiskCleanerSchedule;
use App\Services\Server\DiskCleaner\DiskCleaner;
use App\Services\Server\DiskCleaner\KeepDays;
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
            /*
             * `min:1` is deliberate: the frontend's disabled Save button mirrors it.
             * A schedule with no categories reads as protection but cleans nothing;
             * `enabled` is the off switch and `DELETE /disk-cleaner/schedule` removes it.
             */
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => [Rule::in($safe)],
            // 99, not 100: a threshold of 100 never fires.
            'threshold_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            // Delete all (0) is allowed unattended; pairing it with a threshold is the
            // screen's advice, not a rule. Null = the pre-choice behaviour.
            'keep_days' => ['nullable', 'integer', Rule::in(KeepDays::CHOICES)],
        ];
    }
}
