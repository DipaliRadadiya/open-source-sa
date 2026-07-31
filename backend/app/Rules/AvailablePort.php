<?php

namespace App\Rules;

use App\Models\Application;
use App\Services\Server\Applications\PortAllocator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A port the user picked, checked against the server they actually own.
 *
 * This is a panel for someone else's machine. That server already runs things
 * the panel did not put there, and its owner is entitled to say which port
 * their application should use — including ports with a name in
 * /etc/services, since 8080 is `http-alt` there and is also where half the
 * Node applications in the world listen.
 *
 * So this refuses only what is genuinely taken, and says which of the two it
 * is: the panel can name the other application, and for anything else the
 * honest answer is "something on this server is already listening there",
 * which is what the user needs to go and look.
 *
 * Without this the port was validated as a number in range and nothing else —
 * a user could pick one another site already had and find out from a failed
 * bind, or from the unique index as a 500.
 */
class AvailablePort implements ValidationRule
{
    public function __construct(private ?Application $except = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $conflict = app(PortAllocator::class)->conflict((int) $value, $this->except);

        if ($conflict !== null) {
            $fail(__("validation.port_{$conflict}", ['port' => (int) $value]));
        }
    }
}
