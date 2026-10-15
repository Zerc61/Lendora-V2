<?php

namespace App\Services\Notifications;

use App\Models\Issue;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\IssueReported;
use App\Notifications\LoanApproved;
use App\Notifications\LoanCancelled;
use App\Notifications\LoanCheckedOut;
use App\Notifications\LoanDueSoon;
use App\Notifications\LoanOverdue;
use App\Notifications\LoanPickupSoon;
use App\Notifications\LoanRejected;
use App\Notifications\LoanRequested;
use App\Notifications\LoanReturnConfirmed;
use App\Notifications\LoanReturned;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menyusun dan mengirim notifikasi peristiwa peminjaman.
 *
 * Dipanggil setelah transaksi bisnis selesai (view tanpa commit), sehingga
 * kegagalan pengiriman tidak pernah mengembalikan atau membatalkan aksi yang
 * sudah tersimpan. Penerima dihitung dari membership aktif: pengelola
 * (operator/admin) untuk pengajuan dan pengembalian, peminjam untuk keputusan
 * atas pengajuannya. Pengirim aksi tidak menerima notifikasi atas aksinya
 * sendiri (D. anti-noise).
 */
class NotificationDispatcher
{
    /** @var array<int, string> */
    private const MONTHS_SHORT = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    public function loanRequested(Loan $loan, User $actor): void
    {
        $message = new LoanRequested($this->loanPayload(
            $loan,
            key: 'loan.requested',
            title: 'Pengajuan pinjam baru',
            body: sprintf('%s mengajukan %s untuk %s.', $actor->name, $this->assetsSummary($loan), $this->dateRange($loan)),
            actionLabel: 'Tinjau pengajuan',
        ));

        $this->send($this->managementRecipients($loan->organization_id, $actor), $message);
    }

    public function loanApproved(Loan $loan): void
    {
        $message = new LoanApproved($this->loanPayload(
            $loan,
            key: 'loan.approved',
            title: 'Requestmu disetujui',
            body: sprintf('Ambil %s pada %s.', $this->assetsSummary($loan), $this->dateRange($loan)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function loanRejected(Loan $loan): void
    {
        $message = new LoanRejected($this->loanPayload(
            $loan,
            key: 'loan.rejected',
            title: 'Requestmu ditolak',
            body: $this->rejectionBody($loan),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    /**
     * Pembatalan: pihak yang tidak membatalkan yang diberi tahu.
     */
    public function loanCancelled(Loan $loan, User $actor): void
    {
        if ((int) $actor->getKey() === (int) $loan->borrower_id) {
            $message = new LoanCancelled($this->loanPayload(
                $loan,
                key: 'loan.cancelled',
                title: 'Peminjaman dibatalkan',
                body: sprintf('%s membatalkan %s untuk %s.', $actor->name, $this->assetsSummary($loan), $this->dateRange($loan)),
                actionLabel: 'Lihat detail',
            ));

            $this->send($this->managementRecipients($loan->organization_id, $actor), $message);

            return;
        }

        $message = new LoanCancelled($this->loanPayload(
            $loan,
            key: 'loan.cancelled',
            title: 'Peminjaman dibatalkan',
            body: sprintf('%s untuk %s dibatalkan pengelola.', $this->assetsSummary($loan), $this->dateRange($loan)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function loanCheckedOut(Loan $loan): void
    {
        $message = new LoanCheckedOut($this->loanPayload(
            $loan,
            key: 'loan.checked_out',
            title: 'Aset sudah diserahkan',
            body: sprintf('%s. Kembalikan sebelum %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function loanReturned(Loan $loan, User $actor): void
    {
        $message = new LoanReturned($this->loanPayload(
            $loan,
            key: 'loan.returned',
            title: 'Aset menunggu pemeriksaan',
            body: sprintf('%s mengembalikan %s. Perlu diperiksa.', $loan->borrower?->name ?? 'Peminjam', $this->assetsSummary($loan)),
            actionLabel: 'Periksa aset',
        ));

        $this->send($this->managementRecipients($loan->organization_id, $actor), $message);
    }

    /**
     * Pengembalian sudah dikonfirmasi petugas: peminjam diberi tahu bahwa
     * kewajiban atas loan ini selesai. Petugas yang mengonfirmasi atas loan
     * miliknya sendiri tidak menerima notifikasi (D. anti-noise).
     */
    public function loanReturnConfirmed(Loan $loan, User $actor): void
    {
        if ((int) $actor->getKey() === (int) $loan->borrower_id) {
            return;
        }

        $message = new LoanReturnConfirmed($this->loanPayload(
            $loan,
            key: 'loan.return_confirmed',
            title: 'Pengembalian dikonfirmasi',
            body: sprintf('Pengembalian %s telah dikonfirmasi petugas.', $this->assetsSummary($loan)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    /**
     * Pengingat jadwal. Hanya dipanggil dari pekerjaan terjadwal
     * (`loans:send-reminders`), tidak pernah dari request dashboard, dan
     * selalu lewat guard idempoten supaya tidak dobel.
     */
    public function loanPickupSoon(Loan $loan): void
    {
        $message = new LoanPickupSoon($this->loanPayload(
            $loan,
            key: 'loan.pickup_soon',
            title: 'Jadwal pengambilan segera',
            body: sprintf(
                'Ambil %s pada %s. Serah terima sebelum %s.',
                $this->assetsSummary($loan),
                $this->shortDate($loan->starts_at),
                $this->shortDate($loan->pickupDeadline($this->pickupWindow($loan))),
            ),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function loanDueSoon(Loan $loan): void
    {
        $message = new LoanDueSoon($this->loanPayload(
            $loan,
            key: 'loan.due_soon',
            title: 'Tenggat pengembalian mendekat',
            body: sprintf('Kembalikan %s sebelum %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function loanOverdue(Loan $loan): void
    {
        $message = new LoanOverdue($this->loanPayload(
            $loan,
            key: 'loan.overdue',
            title: 'Peminjaman melewati tenggat',
            body: sprintf('%s belum dikembalikan; tenggatnya %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
        ));

        $this->send($this->single($this->borrowerRecipient($loan)), $message);
    }

    public function issueReported(Issue $issue, User $actor): void
    {
        if (! in_array($issue->severity, [Issue::SEVERITY_HIGH, Issue::SEVERITY_CRITICAL], true)) {
            return;
        }

        $payload = [
            'key' => 'issue.reported',
            'title' => 'Laporan masalah baru',
            'body' => sprintf(
                '%s pada %s (prioritas %s).',
                Str::limit($issue->description, 120),
                $issue->asset?->name ?? 'aset',
                $issue->severityLabel(),
            ),
            'action_url' => '/app/issues/'.$issue->getKey(),
            'action_label' => 'Lihat laporan',
            'organization_id' => $issue->organization_id,
            'organization_name' => $issue->organization?->name ?? '',
        ];

        $this->send($this->managementRecipients($issue->organization_id, $actor), new IssueReported($payload));
    }

    private function pickupWindow(Loan $loan): int
    {
        return OrganizationPolicy::resolve($loan->organization_id)->pickup_window_hours;
    }

    /**
     * @return array{key: string, title: string, body: string, action_url: string, action_label: string, organization_id: int, organization_name: string, loan_id: int}
     */
    private function loanPayload(Loan $loan, string $key, string $title, string $body, string $actionLabel): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'body' => $body,
            'action_url' => '/app/loans/'.$loan->getKey(),
            'action_label' => $actionLabel,
            'organization_id' => $loan->organization_id,
            'organization_name' => $loan->organization?->name ?? '',
            'loan_id' => (int) $loan->getKey(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function managementRecipients(int $organizationId, ?User $except = null): Collection
    {
        return Membership::query()
            ->withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->whereIn('role', [Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN])
            ->where('status', Membership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn (?User $user): bool => $user !== null && $user->isActive())
            ->reject(fn (User $user): bool => $except !== null && $user->is($except))
            ->unique('id')
            ->values();
    }

    private function borrowerRecipient(Loan $loan): ?User
    {
        $borrower = $loan->borrower;

        if ($borrower === null || ! $borrower->isActive()) {
            return null;
        }

        $isStillMember = Membership::query()
            ->withoutOrganizationScope()
            ->where('organization_id', $loan->organization_id)
            ->where('user_id', $borrower->getKey())
            ->where('status', Membership::STATUS_ACTIVE)
            ->exists();

        return $isStillMember ? $borrower : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function single(?User $recipient): Collection
    {
        return $recipient === null ? collect() : collect([$recipient]);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, AppNotification $message): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function assetsSummary(Loan $loan): string
    {
        $names = $loan->items
            ->pluck('asset.name')
            ->filter()
            ->values();

        return $names->isEmpty() ? 'aset' : $names->implode(', ');
    }

    private function rejectionBody(Loan $loan): string
    {
        $reason = $loan->rejection_reason !== null && $loan->rejection_reason !== ''
            ? ' Alasan: '.$loan->rejection_reason
            : '';

        return sprintf('%s untuk %s ditolak.%s', $this->assetsSummary($loan), $this->dateRange($loan), $reason);
    }

    private function dateRange(Loan $loan): string
    {
        return $this->shortDate($loan->starts_at).' sampai '.$this->shortDate($loan->ends_at);
    }

    private function shortDate(?Carbon $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->format('j').' '.self::MONTHS_SHORT[(int) $date->format('n')];
    }
}
