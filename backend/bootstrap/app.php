<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Services\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API berbagi session dan CSRF dengan web (auth manual berbasis session).
            Route::middleware('web')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        $middleware->alias([
            'organization.context' => EnsureOrganizationContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Percobaan 403/404 tetap dicatat (CS-04, CS-12), dan respons API dibuat
        // generik agar APP_DEBUG tidak membocorkan detail internal seperti ID resource.
        // Pencatatan tidak boleh menggagalkan response error yang asli.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            if ($status !== 403 && $status !== 404) {
                return null;
            }

            if ($request->user() !== null) {
                try {
                    app(AuditRecorder::class)->accessDenied(
                        $status === 404 ? 'resource_not_found' : 'forbidden',
                        ['path' => $request->path(), 'method' => $request->method()],
                    );
                } catch (Throwable) {
                    // Audit tidak boleh menutupi error asli.
                }
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $message = match (true) {
                $status === 404 => 'Tidak ditemukan.',
                // Pesan eksplisit aplikasi (mis. akun menunggu aktivasi) dipertahankan.
                ! $e->getPrevious() instanceof AuthorizationException && $e->getMessage() !== '' => $e->getMessage(),
                default => 'Anda tidak punya izin untuk tindakan ini.',
            };

            return response()->json(['message' => $message], $status);
        });
    })->create();
