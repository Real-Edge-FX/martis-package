<?php

return [
    'name_help' => 'Identificador simbólico verificado no código via `$user->can(...)` ou middleware (ex: `dashboard.home.view`, `users.create`). Formato livre — escolha uma convenção e mantenha-a.',
    'guard_help' => 'Auth guard a que esta permissão se aplica. A maioria das aplicações tem apenas um guard (`web`). Defina outro valor apenas se a sua aplicação expõe múltiplos guards (ex: `web` para o painel, `api` para uma aplicação móvel separada).',
    'role_guard_help' => 'Auth guard a que este papel se aplica. Um papel só pode receber permissões do MESMO guard.',
    'multi_guard_explanation' => 'O Spatie Permission exige que cada permissão e cada papel declarem um `guard_name`. Os dois têm de coincidir para um papel poder receber uma permissão. A maioria das aplicações usa um único guard (`web`); configurações multi-guard são raras e tipicamente separam admin (`web`) de uma API pública (`api`).',
];
