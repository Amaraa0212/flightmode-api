<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AirlineRequest;
use App\Http\Resources\AgencyResource;
use App\Http\Resources\AirlineResource;
use App\Models\Agency;
use App\Models\Airline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AirlineController extends Controller
{
    /** What an airline's own users may change: contacts, plus the settings other screens save here. */
    private const LOGO_DIR = 'logos';

    private const OWN_FIELDS = ['email', 'phone', 'address', 'logo_path', 'brand_color', 'conditions_of_carriage', 'bag_tag_layout', 'bag_tag_rotation'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return AirlineResource::collection(
            Airline::query()->withCount('agencies')
                ->when(! $user->isSystemAdmin(), fn ($q) => $q->whereKey($user->airline_id))
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(AirlineRequest $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $airline = Airline::create($request->validated());

        return $this->created('Airline created.', new AirlineResource($airline));
    }

    public function show(Request $request, Airline $airline): AirlineResource
    {
        $this->ensureOwn($request, $airline);

        return new AirlineResource($airline->load('agencies'));
    }

    public function update(AirlineRequest $request, Airline $airline): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        // codes, name, currency and status are set by the system administrator only
        $data = $request->user()->isSystemAdmin() ? $request->validated() : $request->safe()->only(self::OWN_FIELDS);
        $airline->update($data);

        return $this->success('Airline updated.', new AirlineResource($airline));
    }

    /**
     * The airline's logo (e-ticket, boarding pass, bag tag). Saved under public/logos so the web server serves it
     * and the PDFs read it from disk; a new upload replaces the old file.
     */
    public function uploadLogo(Request $request, Airline $airline): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);

        $file = $request->file('logo');
        $svg = in_array(strtolower($file->getClientOriginalExtension()), ['svg'], true) || $file->getMimeType() === 'image/svg+xml';
        // an SVG is served from the API's own domain: only a plain drawing — no script, handlers, links or outside files
        if ($svg && ! $this->isSafeSvg((string) file_get_contents($file->getRealPath()))) {
            throw ValidationException::withMessages(['logo' => 'This SVG has scripts, links or outside references — export it as a plain SVG or a PNG.']);
        }
        $name = 'airline-'.$airline->id.'-'.Str::lower(Str::random(8)).'.'.($svg ? 'svg' : $file->extension());
        $file->move(public_path(self::LOGO_DIR), $name);
        $this->removeLogoFile($airline);
        $airline->update(['logo_path' => self::LOGO_DIR.'/'.$name]);

        return $this->success('Logo saved.', new AirlineResource($airline));
    }

    public function removeLogo(Request $request, Airline $airline): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $this->removeLogoFile($airline);
        $airline->update(['logo_path' => null]);

        return $this->success('Logo removed.', new AirlineResource($airline));
    }

    /** A plain SVG drawing: an <svg> root and nothing that runs code or loads something from elsewhere */
    private function isSafeSvg(string $content): bool
    {
        if (! preg_match('/<svg[\s>]/i', $content)) {
            return false;
        }

        return ! preg_match('/<\s*(script|foreignObject|iframe|object|embed)\b|\son[a-z]+\s*=|javascript:|<!ENTITY|@import|url\(\s*["\']?\s*(?!#|data:image\/)[a-z]+:|(?:xlink:)?href\s*=\s*["\'](?!#|data:image\/)/i', $content);
    }

    /** Only a file this screen uploaded is deleted (never an external URL or another path) */
    private function removeLogoFile(Airline $airline): void
    {
        $path = (string) $airline->logo_path;
        if (str_starts_with($path, self::LOGO_DIR.'/') && ! str_contains($path, '..') && is_file(public_path($path))) {
            @unlink(public_path($path));
        }
    }

    public function destroy(Request $request, Airline $airline): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $airline->delete();

        return $this->deleted('Airline deleted.');
    }

    /** Agencies contracted with the airline. */
    public function agencies(Request $request, Airline $airline): AnonymousResourceCollection
    {
        $this->ensureOwn($request, $airline);

        return AgencyResource::collection($airline->agencies()->orderBy('name')->get());
    }

    /** Add / enable / disable the contract between an airline and an agency. */
    public function setAgencyContract(Request $request, Airline $airline, Agency $agency): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $data = $request->validate([
            'is_active' => ['sometimes', 'required', 'boolean'],
            // Excel import of the quota's passengers / seat reservation on charter flights
            'can_import_passengers' => ['sometimes', 'boolean'],
            'can_assign_seats' => ['sometimes', 'boolean'],
        ]);
        // an airline's own sales channel sells for that airline only
        if ($agency->owner_airline_id && $agency->owner_airline_id !== $airline->id) {
            throw new AuthorizationException("{$agency->name} is a sales channel of another airline.");
        }

        $exists = $airline->agencies()->whereKey($agency->id)->exists();
        $exists
            ? $airline->agencies()->updateExistingPivot($agency->id, $data)
            : $airline->agencies()->attach($agency->id, $data + ['is_active' => true]);

        return $this->success('Contract saved.');
    }

    public function removeAgencyContract(Request $request, Airline $airline, Agency $agency): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $airline->agencies()->detach($agency->id);

        return $this->deleted('Contract removed.');
    }

    private function ensureOwn(Request $request, Airline $airline): void
    {
        $user = $request->user();
        if (! $user->isSystemAdmin() && (int) $user->airline_id !== $airline->id) {
            throw new AuthorizationException('You can only manage your own airline.');
        }
    }

    private function ensureSystemAdmin(Request $request): void
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only system administrators can do this.');
        }
    }
}
