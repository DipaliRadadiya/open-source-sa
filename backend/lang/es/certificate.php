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

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'test_domain' => 'Los únicos dominios de este sitio son dominios de prueba temporales (:domains). Let\'s Encrypt no puede emitir un certificado para ellos, porque comparten un límite semanal con todos los demás que usan ese servicio. Un certificado autofirmado cifrará este sitio ahora mismo.',
        'dns_unverified' => 'Ningún dominio de este sitio apunta todavía a este servidor. Añade un registro DNS A para uno, espera a que se propague e inténtalo de nuevo.',
        'self_signed_warning' => 'Cifra el tráfico de inmediato y funciona en cualquier dominio, incluidos los de prueba e internos. Los navegadores mostrarán una advertencia, porque nada fuera de este servidor lo respalda.',
    ],

];
