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

    'wompi' => [
        'base_url' => env('WOMPI_BASE_URL', 'https://sandbox.wompi.co/v1'),
        'checkout_url' => env('WOMPI_CHECKOUT_URL', 'https://checkout.wompi.co/p/'),
        'public_key' => env('WOMPI_PUBLIC_KEY'),
        'private_key' => env('WOMPI_PRIVATE_KEY'),
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
        'events_secret' => env('WOMPI_EVENTS_SECRET'),
        'redirect_url' => env('WOMPI_REDIRECT_URL'),
        'reservation_minutes' => (int) env('WOMPI_RESERVATION_MINUTES', 30),
    ],

    'envia' => [
        'base_url' => env('ENVIA_BASE_URL', 'https://api-test.envia.com'),
        'queries_base_url' => env('ENVIA_QUERIES_BASE_URL', 'https://queries.test.envia.com'),
        'api_token' => env('ENVIA_API_TOKEN'),
        'timeout' => (int) env('ENVIA_TIMEOUT', 15),
        'connect_timeout' => (int) env('ENVIA_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('ENVIA_RETRIES', 2),
    ],

    'dhl_mydhl' => [
        'base_url' => env('DHL_MYDHL_BASE_URL', 'https://express.api.dhl.com/mydhlapi/test'),
        'username' => env('DHL_MYDHL_USERNAME'),
        'password' => env('DHL_MYDHL_PASSWORD'),
        'account_number' => env('DHL_MYDHL_ACCOUNT_NUMBER'),
        'timeout' => (int) env('DHL_MYDHL_TIMEOUT', 15),
        'connect_timeout' => (int) env('DHL_MYDHL_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('DHL_MYDHL_RETRIES', 2),
    ],

];
