<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a site, and optionally the two things that outlive it: its files
 * and its databases.
 *
 * Both flags default to false and both have to be asked for. Deleting a panel
 * record must not silently destroy someone's code, and it must not silently
 * destroy their data either.
 *
 * Until now `destroy()` took a bare `Request` and read `remove_files` straight
 * off it, so the one destructive flag the endpoint had was never validated at
 * all — `remove_files=maybe` was as good as `true`, because `boolean()` treats
 * anything it does not recognise as false and nothing said so.
 */
class DestroyApplicationRequest extends FormRequest
{
    /**
     * The route already requires `application,manage`; this adds the second
     * permission the second flag needs.
     *
     * Dropping a database is not a consequence of deleting a site, it is a
     * separate destructive act on a separate resource — the same act the
     * `database` permission exists to gate. Someone who may remove a site but
     * may not touch databases would otherwise reach every database on the
     * server through the one relationship the panel lets them create.
     *
     * Checked here rather than mid-deletion so the refusal arrives before
     * anything is removed: a 403 means the site is still there.
     */
    public function authorize(): bool
    {
        if (! $this->boolean('remove_databases')) {
            return true;
        }

        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * The default 403 says "This action is unauthorized", which here would
     * describe deleting the site — the thing this caller is allowed to do.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('errors/application.database_removal_not_permitted'));
    }

    /**
     * Both flags arrive as query parameters — `?remove_files=true` — and
     * Laravel's `boolean` rule does not accept the strings `"true"`/`"false"`,
     * only `1`/`0`/`"1"`/`"0"`. Validating without this would have rejected
     * the exact request the panel has been sending since `remove_files`
     * shipped, which is a contract this endpoint does not get to change.
     *
     * Anything `filter_var` cannot read stays untouched and fails `boolean`
     * below, so a typo is still a 422 rather than a silent "keep".
     */
    protected function prepareForValidation(): void
    {
        foreach (['remove_files', 'remove_databases'] as $flag) {
            if (! $this->has($flag)) {
                continue;
            }

            $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $this->merge([$flag => $value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `sometimes`: absent is the common case and means false for both.
            'remove_files' => ['sometimes', 'boolean'],
            'remove_databases' => ['sometimes', 'boolean'],
        ];
    }
}
