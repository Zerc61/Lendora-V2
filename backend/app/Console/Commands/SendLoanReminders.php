<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\User;
use App\Notifications\LoanDueSoon;
use App\Notifications\LoanOverdue;
use App\Notifications\LoanPickupSoon;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengingat waktu peminjaman: jadwal ambil, tenggat dekat, dan keterlambatan.
 *
 * Peristiwa ini tidak bisa diturunkan dari aksi pengguna, jadi dijadwalkan
 * lewat `Schedule` (lihat routes/console.php) — bukan dibuat saat dashboard
 * dibuka. Setiap kirim dijaga idempoten: pengingat yang sama untuk loan yang
 * sama tidak pernah dobel (keterlambatan dihitung per hari).
 */
class SendLoanReminders extends Command
{
    protected $signature = 'loans:send-reminders';

    protected $description = 'Kirim notifikasi pengingat pengambilan, tenggat, dan keterlambatan peminjaman';

    /** Jendela "segera" untuk jadwal pengambilan. */
    private const PICKUP_HOURS = 24;

    /** Jendela "segera" untuk tenggat pengembalian. */
    private const DUE_HOURS = 48;

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $sent = 0;
        $sent += $this->pickupSoon($dispatcher);
        $sent += $this->dueSoon($dispatcher);
        $sent += $this->overdue($dispatcher);

        $this->info(sprintf('%d pengingat dikirim.', $sent));

        return self::SUCCESS;
    }

    private function pickupSoon(NotificationDispatcher $dispatcher): int
    {
        $count = 0;

        $this->candidates(
            fn () => Loan::query()
                // Pekerjaan lintas organisasi: scope tenant dilepas eksplisit.
                ->withoutOrganizationScope()
                ->where('status', Loan::STATUS_APPROVED)
                ->whereNotNull('approved_at')
                ->whereNotNull('starts_at')
                ->where('starts_at', '>=', now())
                ->where('starts_at', '<=', now()->addHours(self::PICKUP_HOURS)),
        )->each(function (Loan $loan) use ($dispatcher, &$count): void {
            if ($this->alreadySent($loan, LoanPickupSoon::class, $loan->created_at)) {
                return;
            }

            $dispatcher->loanPickupSoon($loan);
            $count++;
        });

        return $count;
    }

    private function dueSoon(NotificationDispatcher $dispatcher): int
    {
        $count = 0;

        $this->candidates(
            fn () => Loan::query()
                // Pekerjaan lintas organisasi: scope tenant dilepas eksplisit.
                ->withoutOrganizationScope()
                ->where('status', Loan::STATUS_CHECKED_OUT)
                ->whereNotNull('due_at')
                ->where('due_at', '>=', now())
                ->where('due_at', '<=', now()->addHours(self::DUE_HOURS)),
        )->each(function (Loan $loan) use ($dispatcher, &$count): void {
            if ($this->alreadySent($loan, LoanDueSoon::class, $loan->created_at)) {
                return;
            }

            $dispatcher->loanDueSoon($loan);
            $count++;
        });

        return $count;
    }

    private function overdue(NotificationDispatcher $dispatcher): int
    {
        $count = 0;

        $this->candidates(
            fn () => Loan::query()
                // Pekerjaan lintas organisasi: scope tenant dilepas eksplisit.
                ->withoutOrganizationScope()
                ->where('status', Loan::STATUS_CHECKED_OUT)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()),
        )->each(function (Loan $loan) use ($dispatcher, &$count): void {
            if ($this->alreadySent($loan, LoanOverdue::class, now()->startOfDay())) {
                return;
            }

            $dispatcher->loanOverdue($loan);
            $count++;
        });

        return $count;
    }

    /**
     * Kandidat loan untuk satu jenis pengingat. Batch dibatasi 200 per putaran
     * supaya satu putaran aman dijalankan berulang tiap jam.
     *
     * @param  callable(): Builder<Loan>  $query
     * @return Collection<int, Loan>
     */
    private function candidates(callable $query)
    {
        return $query()->with('items.asset')->orderBy('id')->limit(200)->get();
    }

    /**
     * Idempotensi. `notifications.data` bertipe teks sehingga pencocokan
     * `loan_id` dilakukan setelah membaca notifikasi tipe terkait — bukan lewat
     * query JSON path yang tidak didukung kolom teks.
     */
    private function alreadySent(Loan $loan, string $type, ?Carbon $since): bool
    {
        $borrowerId = (int) $loan->borrower_id;

        if ($borrowerId === 0) {
            return true;
        }

        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $borrowerId)
            ->where('type', $type)
            ->where('created_at', '>=', $since ?? $loan->created_at)
            ->get()
            ->contains(function (object $row) use ($loan): bool {
                $data = json_decode((string) $row->data, true);

                return is_array($data) && (int) ($data['loan_id'] ?? 0) === (int) $loan->getKey();
            });
    }
}
