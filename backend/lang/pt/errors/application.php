<?php

return [
    'primary_domain_not_removable' => 'O domínio principal não pode ser removido. Defina outro domínio como principal primeiro.',
    'unsupported_web_server' => 'O painel não consegue gravar a configuração do site para :web_server.',
    'no_web_server' => 'nenhum servidor web detectado',
    'provision_failed' => 'A configuração do site falhou na etapa ":step".',
    'not_a_git_application' => 'A aplicação não é uma implantação git, portanto não há nada para baixar.',
    'no_database_engine' => 'Nenhum mecanismo de banco de dados disponível. Instale e configure o MySQL ou MariaDB antes de criar esta aplicação.',
    'no_process' => '\"‎:name\" não executa um processo próprio.',
    'process_failed' => 'Não foi possível :action a aplicação. Informe a referência ao suporte.',
    'no_port_available' => 'Nenhuma porta livre entre :from e :to. Libere uma ou amplie o intervalo.',

    'webhook_not_a_git_application' => 'O deploy automático está disponível apenas para aplicações implantadas a partir de um repositório git.',

    'permissions_fix_failed' => 'Falha ao redefinir as permissões de arquivo no servidor.',

    'unsafe_path' => 'Esse caminho não é permitido.',
    'file_too_large' => 'Esse ficheiro é demasiado grande para abrir aqui. Use SFTP para ficheiros grandes.',
    'file_not_text' => 'Esse ficheiro não parece ser texto e não pode ser aberto aqui.',
    'file_operation_failed' => 'A operação de ficheiro falhou no servidor.',

];
