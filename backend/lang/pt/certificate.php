<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Enviado',
        'self_signed' => 'Autoassinado',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'O Let\'s Encrypt emitiu certificados demais para este domínio recentemente. O limite é reposto uma semana após o mais antigo — tente então, ou envie um certificado.',
        'rate_limited_failures' => 'Tentativas falhadas demais para este domínio na última hora. O Let\'s Encrypt permite cinco; aguarde uma hora.',
        'unreachable' => 'O pedido de validação nunca chegou a este servidor. Verifique se a porta 80 está aberta e se nada mais responde nela.',
        'dns_not_pointing' => 'O domínio não aponta para este servidor. Configure o registo DNS aqui, aguarde a propagação e tente de novo.',
        'challenge_not_served' => 'O ficheiro de validação não foi servido corretamente. O site pode estar a redirecionar /.well-known, ou um proxy como a Cloudflare responde em vez deste servidor.',
        'certbot_missing' => 'O certbot não está instalado neste servidor.',
        'no_certifiable_domains' => 'Nenhum domínio deste site está pronto para um certificado. Verifique primeiro o DNS.',
        'self_sign_failed' => 'Não foi possível gerar o certificado autoassinado.',
        'file_missing' => 'O ficheiro do certificado não existe neste servidor. Emita-o de novo.',
        'unknown' => 'Não foi possível emitir o certificado.',
    ],

];
