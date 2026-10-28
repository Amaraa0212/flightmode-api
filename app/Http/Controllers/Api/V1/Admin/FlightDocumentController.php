<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exports\ChinaApisExport;
use App\Exports\FlightPassengersExport;
use App\Exports\ManifestExport;
use App\Exports\NoShowExport;
use App\Exports\ReconciliationExport;
use App\Http\Controllers\Controller;
use App\Mail\FlightDocumentMail;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use App\Services\Documents\ManifestService;
use App\Services\Documents\PassengerReconciliation;
use App\Services\Documents\PnlGenerator;
use App\Support\Spreadsheet\SpreadsheetExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Flight documents: PNL, PNL reconciliation, passenger list, APIS (China), manifests, no-show list. Download or e-mail. */
class FlightDocumentController extends Controller
{
    private const PARTS = ['passengers', 'baggage', 'balance', 'excess_baggage'];

    public function __construct(
        private readonly AccessScope $access,
        private readonly PnlGenerator $pnl,
        private readonly ManifestService $manifest,
        private readonly PassengerReconciliation $reconciliation,
    ) {}

    public function pnl(Request $request, Flight $flight): Response
    {
        $this->access->ensureFlight($request->user(), $flight);
        $file = $this->pnl->generate($flight);

        return response($file['content'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** PNL vs. who flew: flown / no-show / go-show / offloaded with ADT/CHD/INF totals. ?format=json|xlsx */
    public function reconciliation(Request $request, Flight $flight): JsonResponse|Response
    {
        $this->access->ensureFlight($request->user(), $flight);
        $format = $request->validate(['format' => ['nullable', Rule::in(['json', 'xlsx'])]])['format'] ?? 'json';
        $document = $this->reconciliation->build($flight);

        if ($format === 'xlsx') {
            return (new ReconciliationExport($document))->download($this->name($flight, 'pnl-report', SpreadsheetExport::XLSX));
        }

        return response()->json(['data' => $document]);
    }

    /** ?format=xlsx|csv */
    public function passengers(Request $request, Flight $flight): Response
    {
        $this->access->ensureFlight($request->user(), $flight);
        $format = $request->input('format') === 'csv' ? SpreadsheetExport::CSV : SpreadsheetExport::XLSX;

        return (new FlightPassengersExport($flight))->download($this->name($flight, 'passengers', $format), $format);
    }

    public function apis(Request $request, Flight $flight): Response
    {
        $this->access->ensureFlight($request->user(), $flight);

        return (new ChinaApisExport($flight))->download($this->name($flight, 'apis', SpreadsheetExport::XLSX));
    }

    public function noShow(Request $request, Flight $flight): Response
    {
        $this->access->ensureFlight($request->user(), $flight);

        return (new NoShowExport($flight))->download($this->name($flight, 'no-show', SpreadsheetExport::XLSX));
    }

    /** ?parts[]=passengers&parts[]=baggage&parts[]=balance&parts[]=excess_baggage&format=json|xlsx */
    public function manifest(Request $request, Flight $flight): JsonResponse|Response
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'parts' => ['sometimes', 'array'],
            'parts.*' => [Rule::in(self::PARTS)],
            'format' => ['nullable', Rule::in(['json', 'xlsx'])],
        ]);
        $document = $this->manifest->build($flight, $data['parts'] ?? self::PARTS);

        if (($data['format'] ?? 'json') === 'xlsx') {
            return (new ManifestExport($document))->download($this->name($flight, 'manifest', SpreadsheetExport::XLSX));
        }

        return response()->json(['data' => $document]);
    }

    /** E-mail a document: { document: pnl|passengers|apis|manifest, email } */
    public function email(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'document' => ['required', Rule::in(['pnl', 'passengers', 'apis', 'manifest'])],
            'email' => ['required', 'email'],
        ]);

        [$filename, $content, $mime] = match ($data['document']) {
            'pnl' => (function () use ($flight) {
                $file = $this->pnl->generate($flight);

                return [$file['filename'], $file['content'], 'text/plain'];
            })(),
            'passengers' => [$this->name($flight, 'passengers', SpreadsheetExport::CSV),
                (new FlightPassengersExport($flight))->raw(SpreadsheetExport::CSV), SpreadsheetExport::mime(SpreadsheetExport::CSV)],
            'apis' => [$this->name($flight, 'apis', SpreadsheetExport::XLSX),
                (new ChinaApisExport($flight))->raw(), SpreadsheetExport::mime(SpreadsheetExport::XLSX)],
            'manifest' => [$this->name($flight, 'manifest', SpreadsheetExport::XLSX),
                (new ManifestExport($this->manifest->build($flight, self::PARTS)))->raw(), SpreadsheetExport::mime(SpreadsheetExport::XLSX)],
        };

        Mail::to($data['email'])->send(new FlightDocumentMail(strtoupper($data['document']).' '.$flight->flight_number, $filename, $content, $mime));

        return $this->success("Sent to {$data['email']}.");
    }

    private function name(Flight $flight, string $document, string $format): string
    {
        $date = $flight->scheduled_departure_at->format('Ymd');

        return "{$flight->flight_number}-{$date}-".str_replace('-', '', $flight->route())."-{$document}.".strtolower($format);
    }
}
