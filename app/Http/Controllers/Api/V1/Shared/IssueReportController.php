<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\IssueReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Problems reported from the "?" help panel — any signed-in user may send one. */
class IssueReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page_path' => ['required', 'string', 'max:255'],
            'page_title' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $user = $request->user();

        $report = IssueReport::create($data + [
            'user_id' => $user->id,
            'airline_id' => $user->airline_id,
            'agency_id' => $user->agency_id,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            'status' => IssueReport::STATUS_OPEN,
        ]);

        return $this->created('Thank you, your report has been sent.', ['id' => $report->id]);
    }
}
