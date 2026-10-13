<?php

return [

    'currency' => env('FLIGHTMODE_CURRENCY', 'MNT'),

    'timezone' => env('FLIGHTMODE_TIMEZONE', 'Asia/Ulaanbaatar'),

    'booking' => [
        // International flights: passport must be valid this many months after departure.
        'passport_min_validity_months' => 6,
        // Mongolian national ID card: issued at this age or later it has no end date (expiry 0000/00/00) — not checked.
        'national_id_no_expiry_age' => 45,
        // PNR alphabet & length.
        'pnr_length' => 6,
    ],

    'checkin' => [
        // Boarding starts this many minutes before departure (printed on the boarding pass).
        'boarding_minutes_before_departure' => 30,
    ],

    'online_checkin' => [
        // Seat zones that can never be chosen online (legacy: zone A is kept for crew / balance).
        'excluded_zones' => array_filter(explode(',', env('ONLINE_CHECKIN_EXCLUDED_ZONES', 'A'))),
        // Verification code sent by e-mail.
        'code_ttl_minutes' => 5,
        // Session token after the code is verified.
        'session_ttl_minutes' => 60,
    ],

    'documents' => [
        // Cabin baggage allowance (kg) printed on the e-ticket for non-infants.
        'eticket_cabin_baggage_kg' => 5,
        // Free checked baggage (kg) used on the e-ticket / PNL when no FBAG rule is linked to the flight.
        'default_free_baggage_kg' => 20,
        // PNL SITA header routing per departure airport, e.g. "PQC=PQCKLXH,CXR=CXRLLVN".
        'pnl_routing' => collect(explode(',', (string) env('PNL_ROUTING', 'PQC=PQCKLXH,CXR=CXRLLVN')))
            ->filter()
            ->mapWithKeys(function ($pair) {
                [$airport, $address] = array_pad(explode('=', $pair, 2), 2, '');

                return [trim($airport) => trim($address)];
            })->all(),
        'pnl_sender' => env('PNL_SENDER', '.BJSAV8X'),
    ],

    /*
    | QZ Tray (counter printers). The counter asks the API to sign every QZ request, so the private key
    | never reaches the browser. Without these files QZ Tray still prints but asks "Allow?" on each connect.
    */
    'qz' => [
        'certificate' => env('QZ_CERTIFICATE_PATH', storage_path('app/private/qz/digital-certificate.txt')),
        'private_key' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/private/qz/private-key.pem')),
    ],
];
