<?php

namespace App\Http\Controllers\Api;

use App\Services\ExcelImporter;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ImportController extends Controller
{
    public function __construct(private ExcelImporter $importer) {}

    /**
     * Reads the uploaded file and returns the detected (or the given) column
     * mapping plus a preview. Send `mapping` (JSON), `sheet` and/or `year`
     * again with the same file to re-read it with the user's corrections.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv,ods'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'sheet' => ['nullable', 'string', 'max:100'],
            'mapping' => ['nullable', 'json'],
            'kind' => ['nullable', 'in:contributions,teachers'],
        ], [
            'file.required' => 'Chagua faili la Excel.',
            'file.extensions' => 'Faili lazima liwe la aina ya .xlsx, .xls, .csv au .ods.',
        ]);

        $file = $request->file('file');
        $mapping = $request->filled('mapping') ? json_decode($request->input('mapping'), true) : null;

        try {
            $preview = $this->importer->parse(
                $file->getRealPath(),
                $request->filled('year') ? (int) $request->input('year') : null,
                is_array($mapping) ? $mapping : null,
                $request->filled('sheet') ? $request->input('sheet') : null,
                $file->getClientOriginalName(),
                $request->input('kind', 'contributions'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Imeshindikana kusoma faili. Hakikisha ni faili halali la Excel.'], 422);
        }

        return $preview + ['file_name' => $file->getClientOriginalName()];
    }

    public function commit(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'duplicate_mode' => ['required', 'in:skip,replace'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:150'],
            'rows.*.number' => ['nullable', 'integer'],
            'rows.*.phone' => ['nullable', 'string', 'max:30'],
            'rows.*.email' => ['nullable', 'string', 'max:150'],
            'rows.*.months' => ['required', 'array', 'size:12'],
            'rows.*.months.*' => ['integer', 'min:0'],
            'rows.*.paid_dates' => ['nullable', 'array', 'size:12'],
            'rows.*.paid_dates.*' => ['nullable', 'date'],
            'rows.*.teacher_id' => ['nullable', 'integer'],
            'rows.*.action' => ['nullable', 'in:existing,new,skip'],
        ]);

        $stats = $this->importer->commit($data['rows'], $data['year'], $data['duplicate_mode'], $request->user()->id);

        return ['message' => 'Data imeingizwa kikamilifu.'] + $stats;
    }

    /** Teacher list import: creates new teachers, updates contacts of existing ones. */
    public function commitTeachers(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:150'],
            'rows.*.number' => ['nullable', 'integer'],
            'rows.*.phone' => ['nullable', 'string', 'max:30'],
            'rows.*.email' => ['nullable', 'email', 'max:150'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.teacher_id' => ['nullable', 'integer'],
            'rows.*.action' => ['nullable', 'in:existing,new,skip'],
        ]);

        return ['message' => 'Walimu wameingizwa kikamilifu.'] + $this->importer->commitTeachers($data['rows']);
    }
}
