<?php

/*
 * Origin SPA, bukan origin API. Midtrans membuka tautan balik ini di browser
 * pengguna, jadi harus menunjuk ke aplikasi Vue. Di produksi SPA dan API satu
 * origin (APP_URL), di dev tidak (API 8000, Vite 5173) — tanpa FRONTEND_URL
 * tautan balik jatuh ke backend dan jadi 404.
 */
$frontendUrl = rtrim((string) env('FRONTEND_URL', env('APP_URL', '')), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | Midtrans (Tahap 4). Klien tanpa paket composer: charge Snap dan verifikasi
    | tanda tangan notifikasi cukup lewat HTTP client Laravel.
    | `frontend_url` adalah origin SPA; `finish_url` halaman yang dibuka lagi
    | setelah pembayaran selesai di sisi Midtrans.
    */
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'frontend_url' => $frontendUrl,
        'finish_url' => env('MIDTRANS_FINISH_URL', $frontendUrl.'/booking'),
    ],

];
