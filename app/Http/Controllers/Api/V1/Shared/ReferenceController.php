<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\AirportResource;
use App\Http\Resources\CountryResource;
use App\Http\Resources\SsrCodeResource;
use App\Models\Airport;
use App\Models\Country;
use App\Models\SsrCode;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Read-only lookup lists every signed-in user needs (dropdowns). */
class ReferenceController extends Controller
{
    public function countries(): AnonymousResourceCollection
    {
        return CountryResource::collection(Country::query()->orderBy('name')->get());
    }

    public function airports(Request $request): AnonymousResourceCollection
    {
        $airports = Airport::query()->with('country')->active()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('iata_code', 'like', $request->string('search')->upper().'%')
                ->orWhere('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('city', 'like', '%'.$request->string('search').'%')))
            ->orderBy('iata_code')
            ->get();

        return AirportResource::collection($airports);
    }

    /** Worldwide airports by code / name / city (?search=), or one airport (?code=), with our countries.id */
    public function iataAirports(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:60'], 'code' => ['nullable', 'string', 'size:3']]);
        $search = trim((string) $request->input('search'));

        $airports = DB::table('iata_airports')
            ->when($request->filled('code'), fn ($q) => $q->where('code', strtoupper($request->string('code'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('code', 'like', strtoupper($search).'%')
                ->orWhere('name', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%")))
            ->orderByRaw('code = ? desc', [strtoupper($search)])->orderBy('code')
            ->limit(30)->get();
        $countries = Country::query()->whereIn('alpha2', $airports->pluck('country')->unique())->pluck('id', 'alpha2');

        return response()->json(['data' => $airports->map(fn ($a) => [
            'code' => $a->code,
            'icao' => $a->icao,
            'name' => $a->name,
            'city' => $a->city,
            'city_code' => $a->city_code,
            'timezone' => $a->timezone,
            'country' => $a->country,
            'country_id' => $countries[$a->country] ?? null,
        ])->values()]);
    }

    /** Cities of a country (?country_id=), with their city code and time zone */
    public function cities(Request $request): JsonResponse
    {
        $request->validate(['country_id' => ['required', 'integer'], 'search' => ['nullable', 'string', 'max:60']]);
        $alpha2 = Country::query()->whereKey($request->integer('country_id'))->value('alpha2');

        $cities = DB::table('iata_airports')->where('country', $alpha2)->whereNotNull('city')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('city', 'like', '%'.$request->string('search').'%')
                ->orWhere('city_code', 'like', $request->string('search')->upper().'%')))
            ->select('city', 'city_code', DB::raw('MIN(timezone) as timezone'))
            ->groupBy('city', 'city_code')->orderBy('city')->limit(300)->get();

        return response()->json(['data' => $cities]);
    }

    /** IANA time zones, of one country when ?country_id= is given */
    public function timezones(Request $request): JsonResponse
    {
        $alpha2 = $request->filled('country_id')
            ? Country::query()->whereKey($request->integer('country_id'))->value('alpha2')
            : null;

        return response()->json(['data' => $alpha2
            ? DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $alpha2)
            : DateTimeZone::listIdentifiers()]);
    }

    public function ssrCodes(): AnonymousResourceCollection
    {
        return SsrCodeResource::collection(SsrCode::query()->orderBy('category')->orderBy('code')->get());
    }

    /** Values of every enum the frontend shows in selects / badges. */
    public function enums(): JsonResponse
    {
        $enums = [];
        foreach (glob(app_path('Enums/*.php')) as $file) {
            $class = 'App\\Enums\\'.basename($file, '.php');
            if (! enum_exists($class)) {
                continue;
            }
            $enums[str(class_basename($class))->snake()->value()] = array_map(fn ($c) => $c->value, $class::cases());
        }

        return response()->json(['data' => $enums]);
    }
}
