<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\Country;
use App\Models\SsrCode;
use Illuminate\Database\Seeder;

/** Countries (ISO 3166), IATA SSR codes and a few airports to start with. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (json_decode(file_get_contents(database_path('data/countries.json')), true) as $country) {
            Country::query()->updateOrCreate(['alpha3' => $country['alpha3']], $country);
        }

        foreach (json_decode(file_get_contents(database_path('data/ssr_codes.json')), true) as $ssr) {
            SsrCode::query()->updateOrCreate(['code' => $ssr['code']], $ssr);
        }

        $countries = Country::query()->pluck('id', 'alpha2');
        $airports = [
            ['iata_code' => 'UBN', 'icao_code' => 'ZMCK', 'name' => 'Chinggis Khaan International Airport', 'city' => 'Ulaanbaatar', 'city_code' => 'ULN', 'country' => 'MN', 'timezone' => 'Asia/Ulaanbaatar'],
            ['iata_code' => 'PQC', 'icao_code' => 'VVPQ', 'name' => 'Phu Quoc International Airport', 'city' => 'Phu Quoc', 'city_code' => 'PQC', 'country' => 'VN', 'timezone' => 'Asia/Ho_Chi_Minh'],
            ['iata_code' => 'CXR', 'icao_code' => 'VVCR', 'name' => 'Cam Ranh International Airport', 'city' => 'Nha Trang', 'city_code' => 'NHA', 'country' => 'VN', 'timezone' => 'Asia/Ho_Chi_Minh'],
            ['iata_code' => 'ICN', 'icao_code' => 'RKSI', 'name' => 'Incheon International Airport', 'city' => 'Seoul', 'city_code' => 'SEL', 'country' => 'KR', 'timezone' => 'Asia/Seoul'],
            ['iata_code' => 'PEK', 'icao_code' => 'ZBAA', 'name' => 'Beijing Capital International Airport', 'city' => 'Beijing', 'city_code' => 'BJS', 'country' => 'CN', 'timezone' => 'Asia/Shanghai'],
        ];

        foreach ($airports as $airport) {
            $countryCode = $airport['country'];
            unset($airport['country']);
            Airport::query()->updateOrCreate(['iata_code' => $airport['iata_code']], $airport + ['country_id' => $countries[$countryCode] ?? null]);
        }
    }
}
