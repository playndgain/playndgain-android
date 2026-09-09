<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Verificação de permissão (Resource)
    |--------------------------------------------------------------------------
    |
    | null — sem verificação adicional além do painel Filament (políticas Laravel continuam válidas).
    | string — nome de uma Gate registrada no Laravel (ex.: 'manage-announcements').
    | Closure — recebe o usuário autenticado e deve retornar bool.
    |
    */
    'permission_check' => 'manage-announcements',

    /*
    |--------------------------------------------------------------------------
    | Polling do widget (fallback se o plugin não estiver resolvido)
    |--------------------------------------------------------------------------
    */
    'polling_interval' => '60s',
];
