<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login needs HTTPS. Over plain HTTP the login token — and the administrator session it buys — cross the network in clear text, so anyone in between becomes an administrator of this site. Issue a certificate on the Domains & SSL tab first.',
    'multisite_unsupported' => 'This is a WordPress multisite network. Magic Login only handles single-site installs for now: on a network, the administrators listed here cannot reach the network admin, so signing in would give you less access than it appears to.',
    'not_an_administrator' => 'That account is not an administrator of this site. The list may have changed since it was opened — close Magic Login and try again to refresh it.',
    'list_failed' => 'This site\'s administrators could not be listed. WordPress may not be installed at this path, or wp-cli could not run here.',
    'list_unreadable' => 'WordPress returned something unreadable instead of the administrator list. The site is most likely printing a PHP notice — check its error log.',
    'mint_failed' => 'The one-time login token could not be saved to this site\'s database.',
    'loader_failed' => 'The Magic Login helper could not be written to this site.',
];
