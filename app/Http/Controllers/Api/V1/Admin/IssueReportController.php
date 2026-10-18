<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\IssueReportResource;
use App\Models\IssueReport;
use App\Services\Access\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Problems users reported from the "?" help panel (sent via POST /issue-reports). */
class IssueReportController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    /** Filters: search, status, airline_id, agency_id, user_id, page_path, from, to. Newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:'.IssueReport::STATUS_OPEN.','.IssueReport::STATUS_RESOLVED],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $reports = $this->access->airlineOwned(IssueReport::query(), $request->user())
            ->with(['user', 'airline', 'agency'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($x) => $x->where('message', 'like', $term)
                    ->orWhere('page_path', 'like', $term)
                    ->orWhere('page_title', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('airline_id'), fn ($q) => $q->where('airline_id', $request->integer('airline_id')))
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->integer('agency_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('page_path'), fn ($q) => $q->where('page_path', 'like', $request->string('page_path').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return IssueReportResource::collection($reports);
    }
}
