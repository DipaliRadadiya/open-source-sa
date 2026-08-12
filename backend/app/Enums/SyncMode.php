<?php

namespace App\Enums;

/**
 * Whether a sync run writes anything.
 *
 * Preview exists so the one-click flow is still honest: the user sees the
 * whole list, with evidence, before anything is adopted. It reads the server
 * and touches neither the server nor the panel.
 */
enum SyncMode: string
{
    case Preview = 'preview';
    case Apply = 'apply';

    public function writes(): bool
    {
        return $this === self::Apply;
    }
}
