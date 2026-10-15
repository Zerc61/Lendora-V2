<?php

namespace App\Http\Requests\Api;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Filter periode untuk laporan Fase 9. `from` dan `to` memakai tanggal; jam
 * mengikuti awal dan akhir hari. `limit` membatasi jumlah baris respons.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi (admin-only) ditegakkan di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function from(): ?Carbon
    {
        $value = $this->validated('from');

        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function to(): ?Carbon
    {
        $value = $this->validated('to');

        return $value === null ? null : Carbon::parse($value)->endOfDay();
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? AnalyticsService::DEFAULT_LIMIT);
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function period(): array
    {
        return [
            'from' => $this->from()?->toDateString(),
            'to' => $this->to()?->toDateString(),
        ];
    }
}
