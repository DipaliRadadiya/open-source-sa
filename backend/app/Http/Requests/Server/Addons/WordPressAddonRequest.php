<?php

namespace App\Http\Requests\Server\Addons;

use App\Services\Addons\WpToolkitCommands;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for every wp-toolkit route, taken from the command's own entry
 * in WpToolkitCommands so the rules and the argument list sit side by side.
 *
 * Authorised by the `central.only` middleware ahead of it; nothing here can
 * widen that.
 */
class WordPressAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('central_authenticated') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return WpToolkitCommands::get($this->command())['rules'];
    }

    public function command(): string
    {
        return (string) $this->route('command');
    }
}
