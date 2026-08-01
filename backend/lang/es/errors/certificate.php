<?php

return [

    'no_certifiable_domains' => 'Ningún dominio de esta aplicación está listo para un certificado. Verifique primero el DNS.',
    'force_https_without_certificate' => 'No se puede forzar HTTPS sin un certificado activo: el sitio dejaría de responder.',
    'not_pem' => 'Esto no parece un archivo PEM. Debe empezar por -----BEGIN.',
    'key_mismatch' => 'La clave privada no coincide con el certificado.',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        'dns_missing' => ':domain no se resuelve. Añada un registro DNS A que apunte a este servidor e inténtelo de nuevo.',
        'dns_not_pointing' => ':domain apunta a :ip, que no es este servidor.',
        'behind_proxy' => ':domain apunta a Cloudflare, no a este servidor, por lo que la solicitud de validación nunca llega. Pause el proxy (nube gris) mientras se emite el certificado.',
        'blocked_ip' => ':domain apunta a :ip, que no es una dirección pública para la que se pueda emitir un certificado.',
        'unreachable' => 'Nada respondió en el puerto 80 para :domain. Compruebe que el firewall permite el puerto 80 y que el servidor web está en marcha.',
        'challenge_redirected' => ':domain redirige la solicitud de validación en lugar de responderla. Desactive la redirección de HTTP a HTTPS hasta que se emita el certificado.',
        'challenge_not_served' => ':domain respondió, pero no con el archivo de validación. Lo más probable es que el sitio esté reescribiendo /.well-known/: revise sus reglas de reescritura.',
        'precheck_failed' => 'No se pudo escribir el archivo de validación en este servidor, por lo que no se pudo comprobar :domain.',
    ],
];
