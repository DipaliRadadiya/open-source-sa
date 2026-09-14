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
            /*
             * `min:1` is deliberate, and it has been reported as a bug twice.
             *
             * The report is that the Save button stays disabled when every "What
             * to Clean" box is unchecked. That button is the frontend correctly
             * mirroring this rule — remove only the button's guard and the save
             * becomes a 422 instead, which is worse.
             *
             * A schedule with no categories is a cron entry that runs on time and
             * cleans nothing: it reads as protection on the screen and does
             * nothing on the disk. `enabled` is already the off switch, and
             * `DELETE /disk-cleaner/schedule` removes it entirely, so there are
             * two honest ways to say "do not do this" and the empty list is not
             * a third. Operator decision, 2026-09-14.
             */
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => [Rule::in($safe)],
            'threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
