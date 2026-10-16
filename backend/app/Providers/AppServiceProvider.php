<?php

namespace App\Providers;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)
                ->by($email.'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak percobaan masuk. Coba lagi beberapa saat lagi.',
                ], 429));
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak pendaftaran dari alamat ini. Coba lagi nanti.',
                ], 429));
        });

        // Katalog publik tanpa autentikasi, jadi dibatasi per alamat IP.
        RateLimiter::for('public-catalog', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak permintaan. Coba lagi beberapa saat lagi.',
                ], 429));
        });

        // Pembuatan tagihan ke Midtrans dibatasi per akun: tanpa ini satu akun
        // bisa membanjiri Snap dengan permintaan charge beruntun.
        RateLimiter::for('payment-attempts', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->getKey() ?? $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak percobaan pembayaran. Coba lagi beberapa saat lagi.',
                ], 429));
        });

        // Pemeriksaan status pembayaran aman diulang: batasnya lebih longgar
        // karena halaman dompet menanyakannya berkala selama menunggu bayar.
        RateLimiter::for('payment-status', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->getKey() ?? $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu sering memeriksa status. Tunggu sebentar.',
                ], 429));
        });
    }
}
