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

    'already_disabled' => 'Esta aplicação já está desativada.',
    'not_disabled' => 'Esta aplicação não está desativada.',
    'availability_failed' => 'Não foi possível alterar a disponibilidade da aplicação no servidor.',
    'basic_auth_failed' => 'Não foi possível alterar a proteção por senha no servidor.',
    'bot_blocker_failed' => 'Não foi possível alterar a política do Bloqueador de Bots de IA no servidor.',
    'bot_agent_invalid' => 'Introduza um único nome de bot, como GPTBot ou SemrushBot — apenas letras, números, pontos e hífenes.',
    'bot_agent_too_broad' => 'Isso é demasiado geral — bloquearia também motores de pesquisa como o Google e o Bing. Use o nome completo do bot.',
    'bot_agent_search_engine' => 'Isso é um motor de pesquisa, não um rastreador de IA. Bloqueá-lo removeria o seu site dos resultados de pesquisa.',
    'web_root_failed' => 'Não foi possível alterar a raiz web no servidor.',
    'web_root_not_found' => 'O diretório raiz web não foi encontrado no servidor. Verifique a raiz web nas definições da aplicação e volte a aprovisioná-la se nunca foi criada.',
    'waf_unsupported' => 'A Firewall 8G ainda não está disponível no :server.',
    'waf_failed' => 'Não foi possível alterar as definições da firewall no servidor.',
    'staging_failed' => 'A operação de staging falhou no servidor.',
    'clone_failed' => 'A operação de clonagem falhou no servidor.',
    'fail2ban_failed' => 'A operação do fail2ban falhou no servidor.',

    'permissions_fix_failed' => 'Falha ao redefinir as permissões de arquivo no servidor.',

    'unsafe_path' => 'Esse caminho não é permitido.',
    'file_too_large' => 'Esse ficheiro é demasiado grande para abrir no editor. Transfira-o — as transferências não têm limite de tamanho.',
    'file_not_text' => 'Esse ficheiro não parece ser texto e não pode ser aberto aqui.',
    'file_operation_failed' => 'A operação de ficheiro falhou no servidor.',

    'file_not_archive' => 'Apenas arquivos .zip e .tar.gz podem ser extraídos aqui.',
    'archive_unreadable' => 'Esse arquivo não pôde ser lido. Pode estar corrompido.',
    'archive_empty' => 'Esse arquivo não contém nada.',
    'archive_too_many_entries' => 'Esse arquivo tem demasiados ficheiros para extrair aqui.',
    'archive_too_large' => 'Esse arquivo seria demasiado grande depois de extraído.',
    'archive_has_symlink' => 'Esse arquivo contém uma ligação simbólica, o que não é permitido.',
    'archive_unsafe_entry' => 'Esse arquivo contém um caminho de ficheiro que não é permitido.',

    'path_exists' => 'Já existe algo nesse caminho.',
    'cannot_delete_root' => 'A pasta raiz do site não pode ser eliminada.',
    'target_not_archive' => 'O nome do novo arquivo deve terminar em .zip, .tar.gz ou .tgz.',
    'unknown_backup' => 'Essa não é uma cópia de segurança conhecida deste ficheiro.',

    'upload_directory_missing' => 'A pasta de destino deste envio já não existe.',
    'upload_insufficient_space' => 'O servidor não tem espaço livre em disco suficiente para este envio.',

    'bulk_count_mismatch' => 'O número que confirmou não corresponde à quantidade de itens selecionados.',
    'sources_not_in_one_directory' => 'Todos os itens a comprimir têm de estar na mesma pasta.',
];
