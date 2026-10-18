<?php

return [

    'recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', true),
        'type' => env('RECAPTCHA_TYPE', 'v2'),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'verify_url' => env('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify'),
        'enterprise_project_id' => env('RECAPTCHA_ENTERPRISE_PROJECT_ID'),
        'enterprise_api_key' => env('RECAPTCHA_ENTERPRISE_API_KEY'),
        'enterprise_action' => env('RECAPTCHA_ENTERPRISE_ACTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Proveedor de identidad central (identidad.juarezcampos.net). ISTQB es un cliente OAuth confidencial.
    'identidad' => [
        // identidad-web (Angular): /authorize y /logout, en el navegador.
        'url' => rtrim((string) env('IDENTIDAD_URL', 'https://identidad.juarezcampos.net'), '/'),
        // API de identidad (Laravel): token y JWKS, servidor a servidor.
        'api_url' => rtrim((string) env('IDENTIDAD_API_URL', rtrim((string) env('IDENTIDAD_URL', 'https://identidad.juarezcampos.net'), '/').'/api'), '/'),
        'issuer' => rtrim((string) env('IDENTIDAD_ISSUER', env('IDENTIDAD_URL', 'https://identidad.juarezcampos.net')), '/'),
        'client_id' => env('IDENTIDAD_CLIENT_ID'),
        'client_secret' => env('IDENTIDAD_CLIENT_SECRET'),
        'redirect_uri' => env('IDENTIDAD_REDIRECT_URI'),
        'post_logout_redirect_uri' => env('IDENTIDAD_POST_LOGOUT_URI'),
        'audience' => env('IDENTIDAD_AUDIENCE', 'ISTQB'),
        'refresh_cookie' => 'istqb_refresh',
        'refresh_minutes' => (int) env('IDENTIDAD_REFRESH_MINUTOS', 480),
        // Rol en identidad (código) => rol local de ISTQB (tabla roles), que define los permisos.
        'roles' => [
            'ADMIN' => 'Administrador',
            'GESTOR' => 'Gestor de preguntas',
            'AUDITOR' => 'Auditor',
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
