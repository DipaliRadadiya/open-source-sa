<?php

namespace App\Exceptions\Server\Database;

use App\Exceptions\FeatureException;

/**
 * phpMyAdmin single sign-on refusing to mint a token.
 *
 * Extends FeatureException, not ServerOperationException: every reason here
 * is something the user can act on (deploy phpMyAdmin, add a database user,
 * use a SQL engine), and nothing ran on the server to produce a reference.
 * Declared against ServerOperationException it did not implement the abstract
 * `messageKey()` at all, so the class could not be loaded — every SSO request
 * was a fatal.
 */
class PhpmyadminSsoException extends FeatureException {}
