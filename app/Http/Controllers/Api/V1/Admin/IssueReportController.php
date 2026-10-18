<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\IssueReportResource;
use App\Models\IssueReport;
use App\Services\Access\AccessScope;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Problems users reported from the "?" help panel (sent via POST /issue-reports). */
class IssueReportController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly ActivityLogger $logger,
    ) {}

    /** Filters: search, status, airline_id, agency_id, user_id, page_path, from, to. Newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(IssueReport::statuses())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $reports = $this->access->airlineOwned(IssueReport::query(), $request->user())
            ->with(['user', 'airline', 'agency', 'resolver'])
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

    public function show(Request $request, IssueReport $issueReport): IssueReportResource
    {
        $this->ensureVisible($request, $issueReport);

        return new IssueReportResource($issueReport->load(['user', 'airline', 'agency', 'resolver']));
    }

    /** Change the status / resolution note. Closing (resolved, rejected) stamps who and when; reopening clears it. */
    public function update(Request $request, IssueReport $issueReport): JsonResponse
    {
        $this->ensureVisible($request, $issueReport);
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(IssueReport::statuses())],
            'resolution_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $original = $issueReport->getOriginal();
        $issueReport->fill($data);

        if ($issueReport->isDirty('status')) {
            $closed = in_array($issueReport->status, IssueReport::CLOSED_STATUSES, true);
            $issueReport->fill([
                'resolved_by' => $closed ? $request->user()->id : null,
                'resolved_at' => $closed ? now() : null,
            ]);
        }

        $issueReport->save();
        $this->logger->logChanges(ActivityChannel::Admin, 'issue_report.updated', $issueReport, $original, "Issue #{$issueReport->id}");

        return $this->success('Issue report updated.', new IssueReportResource($issueReport->load(['user', 'airline', 'agency', 'resolver'])));
    }

    private function ensureVisible(Request $request, IssueReport $issueReport): void
    {
        $visible = $this->access->airlineOwned(IssueReport::query()->whereKey($issueReport->id), $request->user())->exists();
        if (! $visible) {
            throw new AuthorizationException('This report belongs to another airline.');
        }
    }
}
