<?php

namespace App\Exceptions\Server;

use App\Exceptions\FeatureException;

/**
 * An invalid central-management token.
 *
 * Previously declared as extending `App\Exceptions\ServerException`, a class
 * that does not exist anywhere in this codebase — so throwing it was a fatal
 * and `CentralSystemGuard` could never have caught it.
 */
class CentralTokenException extends FeatureException {}
