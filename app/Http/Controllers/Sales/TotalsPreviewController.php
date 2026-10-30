<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\TotalsPreviewRequest;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Support\Documents\LineInput;
use Illuminate\Http\JsonResponse;

class TotalsPreviewController extends Controller
{
    public function __invoke(TotalsPreviewRequest $request, DocumentTotalsCalculator $calculator): JsonResponse
    {
        return response()->json($calculator->compute(array_values(array_map(LineInput::fromArray(...), $request->validated('lines'))))->toArray());
    }
}
