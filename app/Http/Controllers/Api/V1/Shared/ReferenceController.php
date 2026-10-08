<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\AirportResource;
use App\Http\Resources\CountryResource;
use App\Http\Resources\SsrCodeResource;
use App\Models\Airport;
use App\Models\Country;
use App\Models\SsrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            if ($class === PermissionKey::class || ! enum_exists($class)) {
                continue;
            }
            $enums[str(class_basename($class))->snake()->value()] = array_map(fn ($c) => $c->value, $class::cases());
        }

        return response()->json(['data' => $enums]);
    }
}
