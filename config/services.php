<?php

return [

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai' => [
        'provider'        => env('AI_PROVIDER', 'gemini'),
        // Lot 3 — faux fournisseur de démonstration locale (APP_ENV=local + AI_FAKE=1 uniquement).
        'fake'            => env('AI_FAKE', false),
        'gemini_key'      => env('GEMINI_API_KEY'),
        'gemini_model'    => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'openai_key'      => env('OPENAI_API_KEY'),
        'openai_model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'anthropic_key'   => env('ANTHROPIC_API_KEY'),
        'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),

        // D6 (v3) — endpoints configurables. OpenAI : résidence des données UE par défaut.
        'openai_base_url'    => env('OPENAI_BASE_URL', 'https://eu.api.openai.com/v1'),
        'anthropic_base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'gemini_base_url'    => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),

        // Lot 2 §1 — second fournisseur pour la double vérification (adjudicateur), sans persona.
        // Spec §6.2 : TOUJOURS un fournisseur différent d'AI_PROVIDER (Claude Sonnet par défaut) ;
        // clé attendue ANTHROPIC_API_KEY. Le repli en cas de conflit ou de clé absente est
        // calculé et journalisé par AppServiceProvider::resolveAdjudicatorProvider().
        'adjudicator_provider' => env('AI_ADJUDICATOR_PROVIDER', 'anthropic'),
        'adjudicator_model'    => env('AI_ADJUDICATOR_MODEL'),
    ],

];
