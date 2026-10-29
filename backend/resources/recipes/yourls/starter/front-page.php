<?php

// Written once by the panel; yours to edit. The official image runs every .php file in
// /docker-entrypoint-init.d on each start, before Apache. YOURLS ships no front page, so
// the bare domain answers 403; this sends it to the admin area instead. Short links are
// not affected: Apache only serves index.php for "/" itself.
$index = '/var/www/html/index.php';

if (! file_exists($index)) {
    file_put_contents($index, "<?php\nheader('Location: /admin/', true, 302);\n");
}
