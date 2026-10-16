<?php

namespace App\Notifications;

/**
 * Saldo dompet ditarik kembali karena Midtrans mengembalikan dana pembeli.
 * Perubahan saldo ke arah bawah tidak boleh terjadi diam-diam.
 */
class WalletTopUpReversed extends AppNotification {}
