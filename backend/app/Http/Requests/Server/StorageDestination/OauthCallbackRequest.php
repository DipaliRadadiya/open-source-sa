<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Services\Server\Backups\Storage\GoogleOauthState;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the callback page is allowed to forward.
 *
 * Note what is *not* here: a destination id. It travels sealed inside `state`
 * and is read there ({@see GoogleOauthState}),
 * because the page that posts this is a browser holding query parameters anyone
 * could have written. Accepting an id here would make the seal decorative.
 */
class OauthCallbackRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Google's one-time authorization code. Bounded because it is fed
            // straight into an outbound request body; the real ones are ~100
            // characters and the limit is generous rather than tight.
            'code' => ['required', 'string', 'max:2048'],

            // The sealed payload this panel issued. Longer, because it is
            // ciphertext carrying a nonce and a timestamp.
            'state' => ['required', 'string', 'max:4096'],
        ];
    }
}
