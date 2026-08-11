<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'O certificado SSL expirou.',
            'expiring' => 'O certificado SSL expira em :days dias.',
        ],
        'worker' => [
            'stopped' => 'O processo da aplicação está parado.',
        ],
        'dns' => [
            'drift' => 'O DNS divergiu — o IP do domínio já não corresponde ao valor guardado.',
            'unresolved' => 'O DNS do domínio não pode ser resolvido neste momento.',
        ],
        'php_eol' => 'O PHP :version chegou ao fim de vida e já não recebe atualizações de segurança.',
        'disk' => 'A utilização do disco está em :percent%.',
        'deploy_failed' => 'A última implementação falhou no passo :step.',
    ],
];
