<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Support\DateRange;
use Illuminate\View\View;

final class YearController
{
    public function __invoke(ReportService $reports, ?int $year = null): View
    {
        $year ??= (int) now()->format('Y');
        abort_unless(DateRange::containsYear($year), 404);

        return view('year', [
            'report' => $reports->year($year),
            'balance' => $reports->balance(),
        ]);
    }
}
