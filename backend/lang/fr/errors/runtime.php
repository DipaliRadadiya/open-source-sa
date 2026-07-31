<?php

return [

    // Rendered when an install failure escapes as an HTTP response.
    // No placeholders: the exception renderer passes no replacements,
    // so a `:version` here would reach the user verbatim. The specific
    // reason lives on the install row, which the screen reads instead.
    'install_failed' => 'L\'installation a échoué. Communiquez la référence au support.',

];
