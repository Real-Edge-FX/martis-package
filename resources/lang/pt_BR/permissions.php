<?php

return [
    'name_help' => 'Identificador simbólico verificado no código via `$user->can(...)` ou middleware (ex: `dashboard.home.view`, `users.create`). Formato livre — escolha uma convenção e mantenha-a.',
    'guard_help' => 'Auth guard ao qual esta permissão se aplica. A maioria das aplicações usa apenas um guard (`web`). Defina outro valor apenas se a sua aplicação expõe múltiplos guards (ex: `web` para o painel, `api` para um app mobile separado).',
    'role_guard_help' => 'Auth guard ao qual esta função se aplica. Uma função só pode receber permissões do MESMO guard.',
    'multi_guard_explanation' => 'O Spatie Permission exige que cada permissão e cada função declarem um `guard_name`. Os dois precisam coincidir para uma função receber uma permissão. A maioria das apps usa um único guard (`web`); configurações multi-guard são raras e tipicamente separam admin (`web`) de uma API pública (`api`).',
];
