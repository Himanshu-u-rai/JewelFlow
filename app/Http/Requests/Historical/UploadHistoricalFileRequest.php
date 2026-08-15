<?php

namespace App\Http\Requests\Historical;

use App\Services\Historical\HistoricalSourceFileReader;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural gate for an uploaded source workbook. The deep safety checks
 * (readable archive, sheet/row/column ceilings, hidden sheets, formula cells)
 * live in HistoricalSourceFileReader, which reads the file itself — this request
 * only rejects the obviously-wrong before a byte is stored.
 *
 * Authorization is the route's job: can:historical.import + edition:retailer.
 */
class UploadHistoricalFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) (HistoricalSourceFileReader::MAX_FILE_BYTES / 1024);

        return [
            // mimes over extension alone: a renamed .exe does not become a workbook.
            'file'                        => ['required', 'file', 'mimes:csv,txt,xlsx', "max:{$maxKb}"],
            'historical_import_profile_id' => ['nullable', 'integer', 'exists:historical_import_profiles,id'],
            'label'                       => ['nullable', 'string', 'max:180'],
            'source_system'               => ['nullable', 'string', 'max:80'],
            'cutover_date'                => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only .csv and .xlsx files can be imported. Re-save the file in one of those formats.',
            'file.max'   => 'That file is larger than the import limit. Split it or re-export the rows you need.',
        ];
    }
}
