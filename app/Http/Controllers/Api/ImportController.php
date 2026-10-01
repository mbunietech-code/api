<?php

namespace App\Http\Controllers\Api;

use App\Services\ExcelImporter;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ImportController extends Controller
{
    public function __construct(private ExcelImporter $importer) {}

    public function preview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv'],
        ], [
            'file.required' => 'Chagua faili la Excel.',
            'file.extensions' => 'Faili lazima liwe la aina ya .xlsx, .xls au .csv.',
        ]);

        $year = (int) $request->input('year') ?: null;

        try {
            $preview = $this->importer->parse($request->file('file')->getRealPath(), $year);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Imeshindikana kusoma faili. Hakikisha ni faili halali la Excel.'], 422);
        }

        return $preview + ['file_name' => $request->file('file')->getClientOriginalName()];
    }

    public function commit(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'duplicate_mode' => ['required', 'in:skip,replace'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:150'],
            'rows.*.number' => ['nullable', 'integer'],
            'rows.*.months' => ['required', 'array', 'size:12'],
            'rows.*.months.*' => ['integer', 'min:0'],
        ]);

        $stats = $this->importer->commit($data['rows'], $data['year'], $data['duplicate_mode'], $request->user()->id);

        return ['message' => 'Data imeingizwa kikamilifu.'] + $stats;
    }
}
