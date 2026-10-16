<?php

namespace Tests\Unit\Polish;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fase 10, "tidak ada istilah mentah": setiap nilai enum domain harus punya
 * label bahasa Indonesia yang berbeda dari slug mentahnya. Label dipakai
 * resource API; UI memakai field `*_label` yang sama.
 */
class EnumLabelCoverageTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: list<string>, 2: array<string, string>}>
     */
    public static function labelSets(): array
    {
        return [
            ['lifecycle aset', Asset::LIFECYCLE_STATUSES, Asset::LIFECYCLE_LABELS],
            ['kondisi aset', Asset::CONDITIONS, Asset::CONDITION_LABELS],
            ['status loan', Loan::STATUSES, Loan::STATUS_LABELS],
            ['tipe issue', Issue::TYPES, Issue::TYPE_LABELS],
            ['severity issue', Issue::SEVERITIES, Issue::SEVERITY_LABELS],
            ['status issue', Issue::STATUSES, Issue::STATUS_LABELS],
            ['status ticket', MaintenanceTicket::STATUSES, MaintenanceTicket::STATUS_LABELS],
            ['prioritas ticket', MaintenanceTicket::PRIORITIES, MaintenanceTicket::PRIORITY_LABELS],
            ['aksi maintenance', array_keys(MaintenanceLog::ACTION_LABELS), MaintenanceLog::ACTION_LABELS],
            ['sumber kondisi', AssetConditionRecord::SOURCES, AssetConditionRecord::SOURCE_LABELS],
            [
                'role anggota',
                [Membership::ROLE_BORROWER, Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN],
                Membership::ROLE_LABELS,
            ],
            [
                'status anggota',
                [Membership::STATUS_ACTIVE, Membership::STATUS_INACTIVE, Membership::STATUS_INVITED],
                Membership::STATUS_LABELS,
            ],
            ['status user', User::STATUSES, User::STATUS_LABELS],
            ['status verifikasi penyedia', Organization::VERIFICATION_STATUSES, Organization::VERIFICATION_LABELS],
        ];
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     */
    #[DataProvider('labelSets')]
    public function test_every_enum_value_has_a_human_label(string $labelName, array $values, array $labels): void
    {
        $this->assertNotSame([], $labels, "Kumpulan label untuk $labelName kosong.");

        foreach ($values as $value) {
            $this->assertArrayHasKey($value, $labels, "Label untuk $labelName '$value' tidak ada.");
            $this->assertNotSame('', (string) $labels[$value], "Label untuk $labelName '$value' kosong.");
            $this->assertNotSame(
                (string) $value,
                (string) $labels[$value],
                "$labelName '$value' memakai slug mentah sebagai label.",
            );
        }
    }

    public function test_membership_label_methods_return_labels(): void
    {
        $membership = new Membership(['role' => Membership::ROLE_OPERATOR, 'status' => Membership::STATUS_ACTIVE]);

        $this->assertSame('Operator', $membership->roleLabel());
        $this->assertSame('Aktif', $membership->statusLabel());
    }

    public function test_user_status_label_method_returns_label(): void
    {
        $user = new User(['status' => User::STATUS_PENDING]);

        $this->assertSame('Menunggu aktivasi', $user->statusLabel());
    }
}
