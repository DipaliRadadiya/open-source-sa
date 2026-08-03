<?php

namespace App\Exceptions\Admin\PanelUpdate;

use RuntimeException;

class PanelUpdateHelperFailedException extends RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct('The panel update helper failed.');
    }
}
