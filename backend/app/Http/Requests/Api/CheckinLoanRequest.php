<?php

namespace App\Http\Requests\Api;

/**
 * Pengembalian aset (Fase 7). Operator mencatat kondisi pasca-pakai; aset
 * kemudian menunggu inspeksi sebelum bisa dipinjam lagi (ADR-007).
 */
class CheckinLoanRequest extends AssetConditionCaptureRequest {}
