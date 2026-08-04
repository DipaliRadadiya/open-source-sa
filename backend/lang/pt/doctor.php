<?php

return [
    'checks' => [
        'privilege' => 'Comandos privilegiados',
        'services' => 'Serviços',
        'writable_paths' => 'Caminhos graváveis',
        'database' => 'Base de dados',
        'health_endpoint' => 'Endpoint de saúde',
    ],
    'fixes' => [
        'privilege' => 'O painel não consegue executar comandos como root. Verifique se /etc/sudoers.d/ contém a permissão e se o ficheiro passa visudo -c.',
        'privilege_disabled' => 'A elevação de privilégios está desativada mas o painel não é root. Remova SERVER_OPS_SUDO=false do .env.',
        'services_missing' => 'Uma unidade esperada não existe. Defina PANEL_FRONTEND_SERVICE e PANEL_QUEUE_SERVICE no .env com os nomes reais.',
        'services_down' => 'Inicie-os com systemctl start e verifique journalctl -u <unidade>.',
        'writable_paths' => 'Atribua a propriedade à conta do painel: chown -R <utilizador> nos caminhos listados.',
        'database_unreachable' => 'Verifique as definições DB_ no .env e se o serviço de base de dados está a correr.',
        'database_pending' => 'Execute php artisan migrate --force. O código foi atualizado sem aplicar as alterações de esquema.',
        'health_unreachable' => 'Verifique se APP_URL no .env corresponde ao endereço do painel e se o servidor web e o php-fpm estão a correr.',
        'health_version_mismatch' => 'O código em execução e a versão servida diferem. Limpe as caches com php artisan optimize:clear e recarregue o php-fpm.',
    ],
];
