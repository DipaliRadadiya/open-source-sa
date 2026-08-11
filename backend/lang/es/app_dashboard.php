<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'El certificado SSL ha caducado.',
            'expiring' => 'El certificado SSL caduca en :days días.',
        ],
        'worker' => [
            'stopped' => 'El proceso de la aplicación está detenido.',
        ],
        'dns' => [
            'drift' => 'El DNS ha cambiado: la IP del dominio ya no coincide con el valor guardado.',
            'unresolved' => 'El DNS del dominio no se puede resolver en este momento.',
        ],
        'php_eol' => 'PHP :version ha llegado al fin de su vida útil y ya no recibe actualizaciones de seguridad.',
        'disk' => 'El uso del disco está al :percent%.',
        'deploy_failed' => 'El último despliegue falló en el paso :step.',
    ],
];
