<?php

namespace App\Http\Requests\Api;

/**
 * Permintaan ekspor CSV. `report` menentukan laporan mana yang diekspor.
 */
class ExportReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'report' => ['required', 'string', 'in:utilization,problems,loans'],
        ]);
    }
}
