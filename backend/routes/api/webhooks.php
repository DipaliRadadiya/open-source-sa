<?php

use App\Http\Controllers\API\Server\ApplicationWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Incoming webhooks. **Unauthenticated on purpose** — this is where GitHub,
| GitLab and Bitbucket call, and they carry no session or token. The HMAC
| signature over the raw body is the credential, and the provider a given
| application expects is stored, not read off the request.
|
| This file lives in routes/api/ rather than routes/api/server/ because those
| are wrapped in `auth:sanctum` and gated on a feature permission — neither of
| which a request from GitHub can satisfy.
|
| `throttle:api` is deliberately removed. It is prepended to every API route in
| bootstrap/app.php and limits guests to 20/minute **per IP** — and a provider
| delivers from shared egress, so a busy panel would drop legitimate deliveries
| and show up as a hook that "sometimes doesn't fire". Replaced by a limiter
| keyed on the webhook itself, which is the thing worth bounding.
*/

Route::post('/webhooks/deploy/{identifier}', [ApplicationWebhookController::class, 'receive'])
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:webhook')
    ->name('webhooks.deploy');
