<?php

return [

    'no_certifiable_domains' => 'Nenhum domínio desta aplicação está pronto para um certificado. Verifique primeiro o DNS.',
    'force_https_without_certificate' => 'Não é possível forçar HTTPS sem um certificado ativo — o site deixaria de responder.',
    'not_pem' => 'Isto não parece um ficheiro PEM. Deve começar por -----BEGIN.',
    'key_mismatch' => 'A chave privada não corresponde ao certificado.',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        'dns_missing' => ':domain não resolve. Adicione um registo DNS A a apontar para este servidor e tente de novo.',
        'dns_not_pointing' => ':domain aponta para :ip, que não é este servidor.',
        'dns_unverifiable' => 'Este servidor está atrás de NAT, por isso o painel não consegue confirmar daqui que :domain aponta para ele. Se o DNS estiver correto, use Emitir mesmo assim — o pedido de validação chega de fora e será bem-sucedido.',
        'behind_proxy' => ':domain aponta para a Cloudflare e não para este servidor, por isso o pedido de validação nunca chega. Pause o proxy (nuvem cinzenta) enquanto o certificado é emitido.',
        'blocked_ip' => ':domain aponta para :ip, que não é um endereço público para o qual se possa emitir um certificado.',
        'unreachable' => 'Nada respondeu na porta 80 para :domain. Verifique se a firewall permite a porta 80 e se o servidor web está a correr.',
        'challenge_redirected' => ':domain redireciona o pedido de validação em vez de o responder. Desative o redirecionamento de HTTP para HTTPS até o certificado ser emitido.',
        'challenge_not_served' => ':domain respondeu, mas não com o ficheiro de validação. O site está provavelmente a reescrever /.well-known/ — verifique as regras de reescrita.',
        'precheck_failed' => 'Não foi possível escrever o ficheiro de validação neste servidor, por isso :domain não pôde ser verificado.',
    ],
];
