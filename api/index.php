<?php

/*
 * Titik masuk serverless function Vercel untuk Laravel.
 *
 * Vercel memanggil berkas ini lewat konvensi direktori `api/`, sehingga
 * request `/api/csrf-cookie` datang dengan SCRIPT_NAME=/api/index.php dan
 * PATH_INFO=/csrf-cookie. Symfony menurunkan "baseUrl" dari SCRIPT_NAME,
 * mendapat `/api`, lalu memotongnya dari REQUEST_URI — router Laravel pun
 * hanya melihat `/csrf-cookie` dan semua rute /api gagal dicocokkan.
 *
 * Dua hal dibereskan di sini sebelum front controller dijalankan:
 *   1. SCRIPT_FILENAME dikosongkan agar Symfony memakai REQUEST_URI apa adanya
 *      (baseUrl jadi kosong), dan PATH_INFO dibuang supaya tidak ambigu.
 *   2. Parameter `path` yang disuntikkan Vercel ke query string dibuang,
 *      sehingga query asli klien tidak tercemar.
 *
 * Berkas ini juga menggantikan peran `backend/public/index.php` (yang isinya
 * diterapkan di bawah) supaya connector pgsql bisa ditukar sebelum container
 * membuat koneksi database.
 *
 * Khusus Vercel: libpq yang tertanam di image PHP di sini lebih tua dari 14,
 * jadi tidak mengirim SNI saat TLS. Neon merutekan koneksi lewat SNI; tanpa itu
 * Neon menjawab "Endpoint ID is not specified". Cara resmi Neon adalah menambah
 * opsi DSN `options=endpoint=<id>`. Laravel tidak punya kunci konfigurasi untuk
 * menyisipkannya — `server_options` menghasilkan `-c endpoint=`, dan Neon justru
 * menolaknya — jadi connector pgsql standar diganti lewat container key
 * `db.connector.pgsql` (lihat ConnectionFactory::createConnector). Endpoint
 * diambil dari env DB_ENDPOINT_ID; bila kosong, perilaku asli Laravel dipakai.
 */

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Http\Request;

$injectedSuffix = ltrim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');

$_SERVER['SCRIPT_FILENAME'] = '';
$_SERVER['ORIG_SCRIPT_NAME'] = '';
unset($_SERVER['PATH_INFO']);

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$questionMark = strpos($uri, '?');
$path = $questionMark === false ? $uri : substr($uri, 0, $questionMark);

parse_str($questionMark === false ? '' : substr($uri, $questionMark + 1), $query);

if ($injectedSuffix !== '' && ($query['path'] ?? null) === $injectedSuffix) {
    unset($query['path']);
}

$queryString = http_build_query($query);
$_GET = $query;
$_SERVER['QUERY_STRING'] = $queryString;
$_SERVER['REQUEST_URI'] = $queryString === '' ? $path : $path.'?'.$queryString;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../backend/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../backend/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../backend/bootstrap/app.php';

$app->bind('db.connector.pgsql', function () {
    $endpoint = env('DB_ENDPOINT_ID');

    return new class($endpoint) extends PostgresConnector
    {
        public function __construct(private readonly ?string $endpoint) {}

        protected function addServerOptions($dsn, array $config)
        {
            if ($this->endpoint === null || $this->endpoint === '') {
                return parent::addServerOptions($dsn, $config);
            }

            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->endpoint);

            return $dsn.";options='endpoint={$escaped}'";
        }
    };
});

$app->handleRequest(Request::capture());
