<?php

/*
 * Protocol-level errors, for the exceptions the framework raises before any
 * feature gets a say. Everything else in this directory belongs to a feature
 * and can say something specific; these cannot, and that is the point.
 *
 * `not_found` is deliberately vague about *why*. Route-model binding raises
 * ModelNotFoundException both when a row does not exist and when a scoped
 * binding excluded one that does, so a message that distinguished the two
 * would answer "does this id exist?" for rows the caller cannot read — an
 * enumeration oracle on every bound route at once.
 */

return [
    'not_found' => 'That item could not be found. It may have been deleted, or the link may be out of date.',
    'method_not_allowed' => 'That action is not available on this address.',
];
