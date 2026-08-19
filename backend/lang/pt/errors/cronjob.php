<?php

return [
    'sync_failed' => 'Falha ao aplicar a tarefa cron no servidor.',
    // One sentence per privileged step. They all used to share
    // `sync_failed`, so a full disk and a missing group read the same.
    'step' => [
        'log_dir' => 'Não foi possível criar o diretório de registos das tarefas cron. Verifique o espaço livre em disco e se /var/log é gravável.',
        'log_touch' => 'Não foi possível criar o ficheiro de registo da tarefa cron. Normalmente o disco está cheio.',
        'log_chown' => 'Não foi possível atribuir o ficheiro de registo à conta que executa a tarefa. Verifique se essa conta ainda existe.',
        'log_chmod' => 'Não foi possível definir as permissões do ficheiro de registo.',
        'rotation' => 'Não foi possível instalar a política de rotação de registos, por isso a tarefa não foi agendada — a sua saída cresceria sem limite.',
        'write' => 'Não foi possível escrever o ficheiro cron. Verifique o espaço livre em disco.',
        'chmod' => 'Não foi possível definir as permissões do ficheiro cron. O cron ignora um ficheiro em que não confia, por isso a tarefa não foi agendada.',
        'remove' => 'Não foi possível remover o ficheiro cron, por isso a tarefa continua agendada no servidor.',
        'remove_stale' => 'Não foi possível remover o ficheiro cron antigo após a mudança de nome. Nada foi alterado, por isso a tarefa não fica agendada duas vezes.',
        'detach_source' => 'Não foi possível remover o ficheiro cron original de onde esta tarefa foi importada. Nada foi alterado, por isso o comando não corre duas vezes.',
    ],
    'invalid_expression' => 'O agendamento não é uma expressão cron válida.',
    'invalid_user' => 'O usuário selecionado não existe no servidor.',
    'unresolved_placeholder' => 'O comando ainda contém o marcador {path} — substitua-o pelo diretório da aplicação.',
    'no_newline' => 'Este valor não pode conter quebras de linha.',
    'reserved_name' => 'Este nome é reservado e não pode ser usado.',
];
