<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'No package for :version. Check the PHP repository is configured and reachable.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'The install stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'The install failed. Quote the reference below to support.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'No package for :extension on PHP :version. It may not exist for this version.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'Installing :extension stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'Installing :extension failed. Quote the reference below to support.',
        'enable_failed' => ':extension was installed but could not be switched on. Try the toggle again.',
    ],

];
