<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OrganizationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Kebijakan organisasi (Database Plan B.1, Business Rules C.1).
 *
 * Satu baris per organisasi. Kalau baris belum ada, {@see resolve()} mengembalikan
 * instance berisi nilai default tanpa menulis ke database, supaya pembacaan
 * availability tidak menghasilkan write.
 */
class OrganizationPolicy extends Model
{
    /** @use HasFactory<OrganizationPolicyFactory> */
    use BelongsToOrganization, HasFactory;

    public const DEFAULT_MAX_LOAN_DURATION_DAYS = 14;

    public const DEFAULT_MIN_LOAN_DURATION_HOURS = 4;

    public const DEFAULT_MAX_ADVANCE_DAYS = 60;

    public const DEFAULT_MIN_NOTICE_HOURS = 4;

    public const DEFAULT_PICKUP_WINDOW_HOURS = 48;

    public const DEFAULT_MAX_ACTIVE_LOANS = 3;

    public const DEFAULT_REQUIRE_APPROVAL = true;

    public const DEFAULT_REQUIRE_PREPAYMENT = false;

    public const DEFAULT_REQUIRE_PRE_CHECKOUT_INSPECTION = true;

    public const DEFAULT_REQUIRE_POST_RETURN_INSPECTION = true;

    public const DEFAULT_ALLOW_RENEWAL = false;

    public const DEFAULT_ALLOW_BORROWER_CANCEL = true;

    public const DEFAULT_MAINTENANCE_NOTICE_DAYS = 7;

    protected $fillable = [
        'organization_id',
        'max_loan_duration_days',
        'min_loan_duration_hours',
        'max_advance_days',
        'min_notice_hours',
        'pickup_window_hours',
        'max_active_loans',
        'require_approval',
        'require_prepayment',
        'require_pre_checkout_inspection',
        'require_post_return_inspection',
        'allow_renewal',
        'allow_borrower_cancel',
        'maintenance_notice_days',
    ];

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'require_approval' => 'boolean',
            'require_prepayment' => 'boolean',
            'require_pre_checkout_inspection' => 'boolean',
            'require_post_return_inspection' => 'boolean',
            'allow_renewal' => 'boolean',
            'allow_borrower_cancel' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'max_loan_duration_days' => self::DEFAULT_MAX_LOAN_DURATION_DAYS,
            'min_loan_duration_hours' => self::DEFAULT_MIN_LOAN_DURATION_HOURS,
            'max_advance_days' => self::DEFAULT_MAX_ADVANCE_DAYS,
            'min_notice_hours' => self::DEFAULT_MIN_NOTICE_HOURS,
            'pickup_window_hours' => self::DEFAULT_PICKUP_WINDOW_HOURS,
            'max_active_loans' => self::DEFAULT_MAX_ACTIVE_LOANS,
            'require_approval' => self::DEFAULT_REQUIRE_APPROVAL,
            'require_prepayment' => self::DEFAULT_REQUIRE_PREPAYMENT,
            'require_pre_checkout_inspection' => self::DEFAULT_REQUIRE_PRE_CHECKOUT_INSPECTION,
            'require_post_return_inspection' => self::DEFAULT_REQUIRE_POST_RETURN_INSPECTION,
            'allow_renewal' => self::DEFAULT_ALLOW_RENEWAL,
            'allow_borrower_cancel' => self::DEFAULT_ALLOW_BORROWER_CANCEL,
            'maintenance_notice_days' => self::DEFAULT_MAINTENANCE_NOTICE_DAYS,
        ];
    }

    /**
     * Kebijakan tersimpan untuk organisasi, atau default bila belum diatur.
     *
     * Hasil disimpan dalam proses supaya daftar loan tidak membaca kebijakan
     * sekali per baris (N+1). Cache dibuang saat kebijakan ditulis.
     */
    public static function resolve(?int $organizationId): self
    {
        if ($organizationId === null) {
            return new self(self::defaults());
        }

        if (isset(self::$resolved[$organizationId])) {
            return self::$resolved[$organizationId];
        }

        $existing = self::query()
            ->withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->first();

        return self::$resolved[$organizationId] = $existing ?? new self(self::defaults());
    }

    public static function flushResolved(): void
    {
        self::$resolved = [];
    }

    /** @var array<int, self> */
    private static array $resolved = [];

    protected static function booted(): void
    {
        static::saved(fn () => self::flushResolved());
        static::deleted(fn () => self::flushResolved());
    }

    public function allowsBorrowerCancel(): bool
    {
        return (bool) $this->allow_borrower_cancel;
    }
}
