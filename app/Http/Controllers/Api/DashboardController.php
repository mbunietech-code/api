<?php

namespace App\Http\Controllers\Api;

use App\Services\ContributionLedger;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ContributionLedger $ledger) {}

    public function index(Request $request)
    {
        $month = (int) $request->query('month', 0);

        return $this->ledger->dashboard($this->year($request), $month >= 1 && $month <= 12 ? $month : null);
    }

    public function monthly(Request $request)
    {
        $year = $this->year($request);

        return [
            'year' => $year,
            'current_month' => $this->ledger->currentMonth($year),
            'months' => $this->ledger->monthlySummary($year),
        ];
    }
}
