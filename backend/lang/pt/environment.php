<?php

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => 'Desconhecido',
    ],

    'checks' => [
        'file_exposed' => [
            'title' => 'O arquivo de ambiente pode ser acessado pela web',
            'detail' => 'Este site serve o diretório onde está o seu .env, então o arquivo fica a uma URL de distância e o Apache não bloqueia dotfiles pelo nome. Defina uma raiz web (uma app Laravel serve public/), o que coloca o diretório servido abaixo do arquivo.',
        ],
        'app_debug_on' => [
            'title' => 'O modo de depuração está ligado',
            'detail' => 'Quem provocar um erro vê o rastreio completo, incluindo as credenciais da base de dados. Defina APP_DEBUG como false num site em produção.',
        ],
        'app_env_local' => [
            'title' => 'O site está a correr num ambiente de desenvolvimento',
            'detail' => 'APP_ENV tem um valor de desenvolvimento, o que altera erros, cache e envio de email. Defina production num site em produção.',
        ],
        'app_key_missing' => [
            'title' => 'Falta a APP_KEY',
            'detail' => 'Sem ela a aplicação não consegue desencriptar sessões nem cookies, e normalmente não arranca.',
        ],
        'next_public_secret' => [
            'title' => '":key" é enviada a todos os visitantes',
            'detail' => 'Tudo com o prefixo NEXT_PUBLIC_ vai para o pacote do navegador. Um segredo aqui já é público.',
        ],
        'duplicate_key' => [
            'title' => '":key" está definida mais do que uma vez',
            'detail' => 'Só a última tem efeito, por isso o valor que vê pode não ser o usado. Linha :line.',
        ],
        'syntax_no_equals' => [
            'title' => 'A linha :line não tem "="',
            'detail' => 'Cada linha tem de ser CHAVE=valor, um comentário, ou vazia.',
        ],
        'syntax_bad_key' => [
            'title' => 'A linha :line não é uma variável válida',
            'detail' => 'Um nome tem de começar por letra ou underscore e conter apenas letras, números e underscores.',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" tem uma aspa por fechar',
            'detail' => 'O valor na linha :line abre uma aspa que nunca fecha e continua pelas linhas seguintes.',
        ],
        'syntax_export' => [
            'title' => '":key" usa "export"',
            'detail' => 'Esta aplicação lê o ambiente através do systemd, que rejeita a palavra export e não arranca. Remova-a. Linha :line.',
        ],
    ],

];
