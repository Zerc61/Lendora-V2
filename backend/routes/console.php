<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Pengingat waktu peminjaman (ambil, tenggat, terlambat) dijadwalkan di sini
 * karena tidak diturunkan dari aksi pengguna. Butuh cron:
 *   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('loans:send-reminders')->hourly();

/*
 * Rekonsiliasi transaksi dompet pending tiap 5 menit agar saldo selalu
 * akurat walau notifikasi Midtrans terlewat.
 */
Schedule::command('wallet:reconcile')->everyFiveMinutes()->withoutOverlapping();

/*
 * Rekonsiliasi pembayaran booking pending. Tanpa ini order yang sudah dibayar
 * tetap tampak menunggu saat webhook gagal sampai, dan gerbang serah terima
 * ikut tertahan.
 */
Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
