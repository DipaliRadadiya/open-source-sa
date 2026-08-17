<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Tráfego baixo',
            'description' => 'Alguns processos. Adequado à maioria dos sites pequenos e o mais suave para um servidor pequeno.',
        ],
        'balanced' => [
            'title' => 'Equilibrado',
            'description' => 'Aguenta tráfego normal sem reservar memória que raramente precisa.',
        ],
        'high' => [
            'title' => 'Tráfego alto',
            'description' => 'Mantém processos prontos. Use quando o site estiver realmente ocupado — reserva memória seja usada ou não.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recomendado',
            'description' => 'Bloqueia todas as formas de executar um programa a partir do PHP — o que um web shell precisa e o que um site normal quase nunca faz.',
        ],
        'strict' => [
            'title' => 'Rigoroso',
            'description' => 'Acrescenta inspeção de processos, utilizadores e sockets à lista recomendada. Corresponde ao endurecimento habitual do alojamento partilhado e pode quebrar um site que use a extensão sockets.',
        ],
    ],

    'errors' => [
        'missing_account' => 'A conta Linux com que este site é executado não existe no servidor, por isso não foi escrito nenhum pool de PHP. O PHP-FPM não arranca de todo com um pool cujo utilizador não consegue resolver.',
        'version_not_installed' => 'O PHP :version não está instalado neste servidor. Instale-o primeiro e depois selecione-o aqui.',
        'unsupported_stack' => 'Este servidor usa OpenLiteSpeed, que não utiliza pools de PHP-FPM.',
        'already_isolated' => 'Este site já tem o seu próprio pool de PHP.',
        'not_isolated' => 'Este site não está isolado.',
        'needs_isolation' => 'Este site ainda não tem o seu próprio pool de PHP, por isso estes limites não poderiam ser aplicados. Atribua-lhe um primeiro e depois guarde.',
        'basedir_absolute' => 'Cada caminho tem de ser absoluto, começando por /. «:path» não é.',
        'basedir_root' => '«/» permite todo o sistema de ficheiros, o que deixaria o open_basedir ligado sem aplicar nada. Desative antes a definição.',
        'basedir_traversal' => '«:path» não é permitido — os caminhos não podem conter «..».',
        'write_failed' => 'Não foi possível escrever a configuração do pool. Nada foi alterado.',
        'config_test_failed' => 'O PHP-FPM rejeitou a configuração, por isso não foi aplicada nem recarregada. O site continua a ser servido exatamente como antes.',
        'reload_failed' => 'O PHP-FPM não recarregou, por isso a configuração anterior foi restaurada.',
        'no_sections' => 'Cabeçalhos de secção não são permitidos aqui — iniciariam um segundo pool dentro deste.',
        'function_list' => 'Tem de ser uma lista de nomes de funções separados por vírgulas.',
    ],
];
