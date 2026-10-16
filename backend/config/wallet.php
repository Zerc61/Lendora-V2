<?php

/*
 * Aturan dompet. Batas nominal dan masa berlaku dikumpulkan di sini supaya tidak
 * menjadi angka ajaib yang tersebar di controller, command, dan frontend.
 *
 * Batas yang sama dikirim ke klien lewat `GET /api/wallet/balance`, jadi
 * perubahan di sini cukup di satu tempat selama `.env` ikut diperbarui.
 */
return [
    // Nominal top up per transaksi, dalam rupiah penuh (tanpa desimal).
    'topup_min' => (int) env('WALLET_TOPUP_MIN', 1000),
    'topup_max' => (int) env('WALLET_TOPUP_MAX', 1000000),

    // Masa berlaku halaman pembayaran top up, dikirim ke Midtrans sebagai `expiry`.
    'expiry_hours' => (int) env('WALLET_EXPIRY_HOURS', 24),
];
