<?php

namespace App\Exceptions\Server\Database;

use App\Exceptions\FeatureException;

/**
 * Adminer single sign-on refusing to mint a token. Every reason is something
 * the user can act on — install Adminer, add a database user, issue a
 * certificate — and nothing ran on the server to produce a reference, so it is
 * a FeatureException (422), the same as phpMyAdmin's.
 */
class AdminerSsoException extends FeatureException {}
