<?php

namespace App\Http\Requests\Server\DiskCleaner;

use App\Services\Server\DiskCleaner\DiskCleaner;
use App\Services\Server\DiskCleaner\KeepDays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CleanDiskRequest extends FormRequest
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
        // Whitelist: only categories that are registered AND available on this
        // box. The client never sends a path — just a known category key.
        $available = collect(app(DiskCleaner::class)->targets())
            ->filter(fn ($target) => $target->available())
            ->map(fn ($target) => $target->key())
            ->values()
            ->all();

        return [
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => [Rule::in($available)],
            // One choice for the whole clean. Absent = each category does what
            // it did before the choice existed, so older clients are unchanged.
            'keep_days' => ['nullable', 'integer', Rule::in(KeepDays::CHOICES)],
        ];
    }
}
