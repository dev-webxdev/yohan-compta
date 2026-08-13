<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\View\View;

final class YearController
{
    public function __invoke(ReportService $reports, ?int $year = null): View
    {
        $year ??= (int) now()->format('Y');
        abort_unless($year >= 2000 && $year <= 2200, 404);

        return view('year', [
            'report' => $reports->year($year),
            'balance' => $reports->balance(),
        ]);
    }
}
