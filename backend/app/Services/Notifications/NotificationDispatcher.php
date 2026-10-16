<?php

namespace App\Services\Notifications;

use App\Models\Issue;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Models\WalletTransaction;
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
use App\Notifications\WalletToppedUp;
use App\Notifications\WalletTopUpReversed;
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
        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanApproved($this->loanPayload(
            $loan,
            key: 'loan.approved',
            title: 'Requestmu disetujui',
            body: sprintf('Ambil %s pada %s.', $this->assetsSummary($loan), $this->dateRange($loan)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    public function loanRejected(Loan $loan): void
    {
        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanRejected($this->loanPayload(
            $loan,
            key: 'loan.rejected',
            title: 'Requestmu ditolak',
            body: $this->rejectionBody($loan),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
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

        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanCancelled($this->loanPayload(
            $loan,
            key: 'loan.cancelled',
            title: 'Peminjaman dibatalkan',
            body: sprintf('%s untuk %s dibatalkan pengelola.', $this->assetsSummary($loan), $this->dateRange($loan)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    public function loanCheckedOut(Loan $loan): void
    {
        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanCheckedOut($this->loanPayload(
            $loan,
            key: 'loan.checked_out',
            title: 'Aset sudah diserahkan',
            body: sprintf('%s. Kembalikan sebelum %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
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

        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanReturnConfirmed($this->loanPayload(
            $loan,
            key: 'loan.return_confirmed',
            title: 'Pengembalian dikonfirmasi',
            body: sprintf('Pengembalian %s telah dikonfirmasi petugas.', $this->assetsSummary($loan)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    /**
     * Pengingat jadwal. Hanya dipanggil dari pekerjaan terjadwal
     * (`loans:send-reminders`), tidak pernah dari request dashboard, dan
     * selalu lewat guard idempoten supaya tidak dobel.
     */
    public function loanPickupSoon(Loan $loan): void
    {
        $recipient = $this->borrowerRecipient($loan);

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
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    public function loanDueSoon(Loan $loan): void
    {
        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanDueSoon($this->loanPayload(
            $loan,
            key: 'loan.due_soon',
            title: 'Tenggat pengembalian mendekat',
            body: sprintf('Kembalikan %s sebelum %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    public function loanOverdue(Loan $loan): void
    {
        $recipient = $this->borrowerRecipient($loan);

        $message = new LoanOverdue($this->loanPayload(
            $loan,
            key: 'loan.overdue',
            title: 'Peminjaman melewati tenggat',
            body: sprintf('%s belum dikembalikan; tenggatnya %s.', $this->assetsSummary($loan), $this->shortDate($loan->due_at)),
            actionLabel: 'Lihat detail',
            recipient: $recipient,
        ));

        $this->send($this->single($recipient), $message);
    }

    /**
     * Top up dompet berhasil dikreditkan. Dipanggil setelah transaksi basis data
     * selesai, dan hanya saat saldo benar-benar bertambah.
     */
    public function walletToppedUp(WalletTransaction $transaction): void
    {
        $user = $transaction->user()->first();

        if ($user === null) {
            return;
        }

        $message = new WalletToppedUp([
            'key' => 'wallet.topped_up',
            'title' => 'Saldo dompet bertambah',
            'body' => sprintf(
                'Top up %s berhasil. Saldo dompet kini %s.',
                $this->rupiah((float) $transaction->amount),
                $this->rupiah((float) $user->wallet_balance),
            ),
            'action_url' => '/app/wallet',
            'action_label' => 'Lihat dompet',
        ]);

        $this->send($this->single($user), $message);
    }

    /**
     * Saldo ditarik kembali karena Midtrans mengembalikan dana pembeli.
     * Pengurangan saldo tidak boleh terjadi diam-diam.
     */
    public function walletTopUpReversed(WalletTransaction $transaction): void
    {
        $user = $transaction->user()->first();

        if ($user === null) {
            return;
        }

        $metadata = $transaction->metadata ?? [];
        $refunds = $metadata['refunds'] ?? [];
        $latest = end($refunds) ?: [];

        $amount = (float) ($latest['amount'] ?? $transaction->amount);
        $shortfall = (float) ($latest['shortfall'] ?? 0);

        $body = sprintf(
            'Dana %s dikembalikan Midtrans, saldo dompet dikurangi. Saldo kini %s.',
            $this->rupiah($amount),
            $this->rupiah((float) $user->wallet_balance),
        );

        if ($shortfall > 0) {
            $body .= sprintf(' %s belum tertutup dan menunggu tindak lanjut.', $this->rupiah($shortfall));
        }

        $message = new WalletTopUpReversed([
            'key' => 'wallet.reversed',
            'title' => 'Dana top up dikembalikan',
            'body' => $body,
            'action_url' => '/app/wallet',
            'action_label' => 'Lihat dompet',
        ]);

        $this->send($this->single($user), $message);
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
    /**
     * @param  ?User  $recipient  Penerima tunggal; null berarti audiens pengelola
     *                            organisasi, yang selalu punya akses `/app`.
     */
    private function loanPayload(Loan $loan, string $key, string $title, string $body, string $actionLabel, ?User $recipient = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'body' => $body,
            'action_url' => $this->loanActionUrl($loan, $recipient),
            'action_label' => $actionLabel,
            'organization_id' => $loan->organization_id,
            'organization_name' => $loan->organization?->name ?? '',
            'loan_id' => (int) $loan->getKey(),
        ];
    }

    /**
     * Tujuan tautan mengikuti kemampuan penerima membuka `/app`: pengelola dan
     * anggota masuk ke detail loan, customer tanpa membership ke halaman
     * booking miliknya sendiri.
     */
    private function loanActionUrl(Loan $loan, ?User $recipient): string
    {
        if ($recipient === null || $this->isMemberOf($loan->organization_id, $recipient)) {
            return '/app/loans/'.$loan->getKey();
        }

        return '/booking';
    }

    private function isMemberOf(int $organizationId, User $user): bool
    {
        return Membership::query()
            ->withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->getKey())
            ->where('status', Membership::STATUS_ACTIVE)
            ->exists();
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

    /**
     * Peminjam adalah pemilik booking, jadi membership-nya di organisasi ini
     * bukan syarat: customer yang memesan lewat katalog publik tidak punya
     * membership, dan tetap harus menerima kabar pinjamannya. Yang disaring
     * hanya akun yang sudah tidak aktif.
     */
    private function borrowerRecipient(Loan $loan): ?User
    {
        $borrower = $loan->borrower;

        if ($borrower === null || ! $borrower->isActive()) {
            return null;
        }

        return $borrower;
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

    private function rupiah(float $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private function shortDate(?Carbon $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->format('j').' '.self::MONTHS_SHORT[(int) $date->format('n')];
    }
}
