<?php

namespace App\Http\Controllers;

use App\Services\WorkingDayCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkingDayController extends Controller
{
    public function __invoke(Request $request, WorkingDayCalculator $calculator): JsonResponse
    {
        $dates = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        return response()->json([
            'working_days' => $calculator->count($dates['start'], $dates['end']),
        ]);
    }
}
