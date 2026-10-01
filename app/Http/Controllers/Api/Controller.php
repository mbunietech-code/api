<?php

namespace App\Http\Controllers\Api;

use App\Services\AppSettings;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function year(Request $request): int
    {
        $year = (int) $request->query('year', 0);

        return $year >= 2000 && $year <= 2100 ? $year : (int) app(AppSettings::class)->get('active_year');
    }
}
