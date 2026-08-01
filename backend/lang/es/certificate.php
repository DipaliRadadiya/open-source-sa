<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Subido',
        'self_signed' => 'Autofirmado',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'Let\'s Encrypt ha emitido demasiados certificados para este dominio recientemente. El límite se restablece una semana después del más antiguo: inténtelo entonces o suba un certificado.',
        'rate_limited_failures' => 'Demasiados intentos fallidos para este dominio en la última hora. Let\'s Encrypt permite cinco; espere una hora antes de reintentar.',
        'unreachable' => 'La solicitud de validación nunca llegó a este servidor. Compruebe que el puerto 80 esté abierto y que nada más responda en él.',
        'dns_not_pointing' => 'El dominio no apunta a este servidor. Configure su registro DNS aquí, espere a que se propague e inténtelo de nuevo.',
        'challenge_not_served' => 'El archivo de validación no se sirvió correctamente. El sitio puede estar redirigiendo o reescribiendo /.well-known, o un proxy como Cloudflare responde en lugar de este servidor.',
        'certbot_missing' => 'certbot no está instalado en este servidor.',
        'no_certifiable_domains' => 'Ningún dominio de este sitio está listo para un certificado. Verifique primero el DNS.',
        'self_sign_failed' => 'No se pudo generar el certificado autofirmado.',
        'file_missing' => 'Falta el archivo del certificado en este servidor. Vuelva a emitirlo.',
        'unknown' => 'No se pudo emitir el certificado.',
    ],

];
