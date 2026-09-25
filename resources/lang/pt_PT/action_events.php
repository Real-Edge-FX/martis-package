<?php

// The built-in action log (Martis\Resources\ActionEventResource) and the
// "Action Events" panel of an Actionable model's detail page. Labels follow
// Nova 5's ActionResource ("Action Name" => "Name", ...).
return [
    'label' => 'Eventos de ações',
    'singular_label' => 'Evento de ação',
    'subtitle' => 'Registo de auditoria de todas as ações executadas no painel',
    'id' => 'ID',
    'name' => 'Nome',
    'initiated_by' => 'Iniciada por',
    'target' => 'Alvo',
    'status' => 'Estado',
    'original' => 'Original',
    'changes' => 'Alterações',
    'exception' => 'Exceção',
    'happened_at' => 'Ocorreu em',
    'status_waiting' => 'Em espera',
    'status_running' => 'Em execução',
    'status_finished' => 'Concluída',
    'status_failed' => 'Falhou',
    'status_denied' => 'Negada',
];
