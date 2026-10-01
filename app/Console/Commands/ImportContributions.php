<?php

namespace App\Console\Commands;

use App\Services\ExcelImporter;
use Illuminate\Console\Command;

class ImportContributions extends Command
{
    protected $signature = 'michango:import {path : Njia ya faili la Excel} {--year= : Mwaka (hutambuliwa kutoka kwenye faili)} {--replace : Badilisha rekodi zilizopo badala ya kuziruka}';

    protected $description = 'Ingiza data ya michango kutoka faili la Excel (No. | JINA LA MWALIMU | JAN..DEC | TOTAL)';

    public function handle(ExcelImporter $importer): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Faili halipo: {$path}");

            return self::FAILURE;
        }

        $preview = $importer->parse($path, $this->option('year') ? (int) $this->option('year') : null);
        $this->info("Mwaka {$preview['year']}: walimu {$preview['teachers_count']}, rekodi {$preview['records_count']}, jumla TZS ".number_format($preview['total_amount']));

        foreach ($preview['errors'] as $error) {
            $this->warn("Safu {$error['row']} ({$error['name']}): {$error['message']}");
        }
        if ($preview['duplicates']) {
            $this->warn(count($preview['duplicates']).' rekodi tayari zipo kwenye mfumo - '.($this->option('replace') ? 'zitabadilishwa.' : 'zitarukwa.'));
        }

        $stats = $importer->commit($preview['rows'], $preview['year'], $this->option('replace') ? 'replace' : 'skip', null);
        $this->table(array_keys($stats), [$stats]);

        return self::SUCCESS;
    }
}
