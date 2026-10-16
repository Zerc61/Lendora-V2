<?php

use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BorrowerDashboardController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerBookingController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MaintenanceTicketController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OperatorDashboardController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationPolicyController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\PlatformWalletController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicCatalogController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\ResolveCurrentOrganization;
use App\Http\Middleware\ResolveProviderContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Lendora v2
|--------------------------------------------------------------------------
|
| Autentikasi memakai session Laravel (bukan token, bukan starter kit), karena
| itu route ini berjalan di atas middleware `web`: session, cookie, dan CSRF.
| Frontend Vue memanggil GET /api/csrf-cookie sebelum request yang mengubah data.
|
*/

Route::get('/csrf-cookie', fn () => response()->noContent());

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Katalog publik. Tanpa autentikasi, dibatasi throttle. Konteks provider diisi
// middleware sehingga tiap kueri terbatas pada satu provider terverifikasi.
Route::get('/public/providers', [PublicCatalogController::class, 'providers'])
    ->middleware('throttle:public-catalog');

Route::middleware(['throttle:public-catalog', ResolveProviderContext::class])
    ->prefix('public/providers/{provider}')
    ->group(function () {
        Route::get('/', [PublicCatalogController::class, 'provider']);
        Route::get('/equipment', [PublicCatalogController::class, 'equipment']);
        Route::get('/equipment/{asset}', [PublicCatalogController::class, 'equipmentShow'])
            ->whereNumber('asset');
        Route::get('/equipment/{asset}/availability', [PublicCatalogController::class, 'availability'])
            ->whereNumber('asset');
    });

// Notifikasi Midtrans. Tanpa autentikasi dan tanpa token CSRF: yang mengirim
// adalah server Midtrans. Penjaganya verifikasi tanda tangan di PaymentService,
// bukan sesi.
Route::post('/payments/webhook', [MidtransWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', [AuthController::class, 'me'])
        ->middleware(ResolveCurrentOrganization::class);

    // Notifikasi milik user, berlaku lintas organisasi, sehingga berada di luar
    // konteks tenant: seluruh aksi dibatasi notifikasi milik user login.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // Foto profil milik user sendiri, berlaku lintas organisasi seperti notifikasi.
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/topup', [WalletController::class, 'topup'])
        ->middleware('throttle:payment-attempts');

    // Administrasi platform. Peran dari `users.platform_role`, bukan membership,
    // sehingga berada di luar konteks tenant: admin platform bekerja lintas
    // organisasi dan lintas akun.
    Route::prefix('platform')->group(function () {
        Route::get('/users', [PlatformAdminController::class, 'users']);
        Route::patch('/users/{user}/status', [PlatformAdminController::class, 'updateUserStatus'])
            ->whereNumber('user');
        Route::get('/providers', [PlatformAdminController::class, 'providers']);
        Route::patch('/providers/{organization}/verification', [PlatformAdminController::class, 'updateProviderVerification'])
            ->whereNumber('organization');

        // Dompet melekat pada akun, bukan organisasi: pengawasannya ikut di sini.
        Route::get('/wallet-transactions', [PlatformWalletController::class, 'index']);
    });

    // Pemesanan oleh customer. Customer cukup punya akun, tidak perlu membership
    // di organisasi provider, jadi rute berada di luar konteks tenant; konteks
    // provider diisi ResolveProviderContext.
    Route::get('/bookings', [CustomerBookingController::class, 'index']);

    Route::middleware(ResolveProviderContext::class)
        ->prefix('providers/{provider}')
        ->group(function () {
            Route::post('/bookings', [CustomerBookingController::class, 'store']);
            Route::post('/bookings/{loan}/cancel', [CustomerBookingController::class, 'cancel'])
                ->whereNumber('loan');
            Route::post('/bookings/{loan}/payment', [PaymentController::class, 'store'])
                ->whereNumber('loan')
                ->middleware('throttle:payment-attempts');
        });

    Route::middleware([ResolveCurrentOrganization::class, EnsureOrganizationContext::class])
        ->group(function () {
            Route::post('/organization/switch', [AuthController::class, 'switchOrganization']);

            Route::get('/organizations', [OrganizationController::class, 'index']);
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
            Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);

            Route::get('/memberships', [MembershipController::class, 'index']);
            Route::post('/memberships', [MembershipController::class, 'store']);
            Route::get('/memberships/{membership}', [MembershipController::class, 'show']);
            Route::patch('/memberships/{membership}', [MembershipController::class, 'update']);
            Route::delete('/memberships/{membership}', [MembershipController::class, 'destroy']);
            Route::post('/memberships/{membership}/reset-password', [MembershipController::class, 'resetPassword']);

            // Fase 5, Asset Management. Binding model tidak dipakai; controller
            // memakai int + findOrFail setelah konteks tenant terbentuk.
            Route::get('/assets', [AssetController::class, 'index']);
            Route::post('/assets', [AssetController::class, 'store']);
            Route::get('/assets/{asset}', [AssetController::class, 'show']);
            Route::patch('/assets/{asset}', [AssetController::class, 'update']);
            Route::post('/assets/{asset}/retire', [AssetController::class, 'retire']);
            Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::get('/categories/{category}', [CategoryController::class, 'show']);
            Route::patch('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

            Route::get('/locations', [LocationController::class, 'index']);
            Route::post('/locations', [LocationController::class, 'store']);
            Route::get('/locations/{location}', [LocationController::class, 'show']);
            Route::patch('/locations/{location}', [LocationController::class, 'update']);
            Route::delete('/locations/{location}', [LocationController::class, 'destroy']);

            Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);

            // Fase 6, Lending Engine. Availability dihitung, tidak di-cache.
            Route::get('/assets/{asset}/availability', [AvailabilityController::class, 'show']);

            Route::get('/loans', [LoanController::class, 'index']);
            Route::post('/loans', [LoanController::class, 'store']);
            Route::get('/loans/{loan}', [LoanController::class, 'show']);
            Route::post('/loans/{loan}/approve', [LoanController::class, 'approve']);
            Route::post('/loans/{loan}/reject', [LoanController::class, 'reject']);
            Route::post('/loans/{loan}/cancel', [LoanController::class, 'cancel']);

            // Fase 7, Operations. Serah terima, pengembalian, inspeksi.
            Route::post('/loans/{loan}/checkout', [LoanController::class, 'checkout']);
            Route::post('/loans/{loan}/return', [LoanController::class, 'checkin']);
            Route::post('/assets/{asset}/inspection', [InspectionController::class, 'store']);
            Route::get('/assets/{asset}/condition-records', [InspectionController::class, 'index']);
            Route::get('/operator/today', [OperatorDashboardController::class, 'today']);

            // Dashboard Borrower. Semua kueri dibatasi ke user yang login.
            Route::get('/borrower/overview', [BorrowerDashboardController::class, 'overview']);
            Route::get('/borrower/calendar', [BorrowerDashboardController::class, 'calendar']);

            // Fase 8, Maintenance. Issue, ticket, verifikasi.
            Route::get('/issues', [IssueController::class, 'index']);
            Route::post('/issues', [IssueController::class, 'store']);
            Route::get('/issues/{issue}', [IssueController::class, 'show']);
            Route::post('/issues/{issue}/review', [IssueController::class, 'review']);
            Route::post('/issues/{issue}/resolve', [IssueController::class, 'resolve']);
            Route::post('/issues/{issue}/dismiss', [IssueController::class, 'dismiss']);

            Route::get('/maintenance-tickets', [MaintenanceTicketController::class, 'index']);
            Route::post('/assets/{asset}/maintenance-tickets', [MaintenanceTicketController::class, 'store']);
            Route::get('/maintenance-tickets/{ticket}', [MaintenanceTicketController::class, 'show']);
            Route::post('/maintenance-tickets/{ticket}/assign', [MaintenanceTicketController::class, 'assign']);
            Route::post('/maintenance-tickets/{ticket}/start', [MaintenanceTicketController::class, 'start']);
            Route::post('/maintenance-tickets/{ticket}/await-parts', [MaintenanceTicketController::class, 'awaitParts']);
            Route::post('/maintenance-tickets/{ticket}/complete', [MaintenanceTicketController::class, 'complete']);
            Route::post('/maintenance-tickets/{ticket}/verify', [MaintenanceTicketController::class, 'verify']);
            Route::post('/maintenance-tickets/{ticket}/cancel', [MaintenanceTicketController::class, 'cancel']);

            // Fase 9, Analytics. Ringkasan admin dan laporan. Admin-only.
            Route::get('/admin/overview', [AdminOverviewController::class, 'index']);
            Route::get('/audit-events', [AuditEventController::class, 'index']);

            Route::get('/reports/utilization', [AnalyticsController::class, 'utilization']);
            Route::get('/reports/problems', [AnalyticsController::class, 'problems']);
            Route::get('/reports/loans', [AnalyticsController::class, 'loans']);
            Route::get('/reports/export', [AnalyticsController::class, 'export']);

            // Fase 10, Kebijakan organisasi. Admin-only.
            Route::get('/organizations/{organization}/policy', [OrganizationPolicyController::class, 'show']);
            Route::patch('/organizations/{organization}/policy', [OrganizationPolicyController::class, 'update']);
        });
});
