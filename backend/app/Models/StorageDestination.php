<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key'])]
class StorageDestination extends Model
{
    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    /**
     * What the panel currently knows about this destination.
     *
     * `never_tested` is deliberately distinct from `failed`: "we have not
     * asked" and "we asked and it said no" are different situations and only
     * one of them is the user's problem to fix.
     */
    public function testStatus(): string
    {
        if ($this->last_test_success === null) {
            return 'never_tested';
        }

        return $this->last_test_success ? 'connected' : 'failed';
    }

    /**
     * Forget what the last probe found.
     *
     * Called whenever the credentials or the address change: a stored
     * "connected" describes the keys that were tested, not the ones now
     * stored, and a panel that shows a green tick for a key rotated out ten
     * seconds ago is lying about the one thing this field exists to answer.
     */
    public function forgetTestResult(): void
    {
        $this->forceFill([
            'last_tested_at' => null,
            'last_test_success' => null,
            'last_test_error' => null,
        ])->save();
    }
}
