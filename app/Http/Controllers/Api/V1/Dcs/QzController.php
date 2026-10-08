<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QZ Tray trust (legacy DCS kept the private key in page JavaScript — here it stays on the server).
 * The counter sends every QZ request string to `sign`; QZ Tray checks the signature against the certificate.
 */
class QzController extends Controller
{
    /** Site certificate, or null when none is installed (QZ Tray then asks the agent to allow each connection). */
    public function certificate(): JsonResponse
    {
        $path = config('flightmode.qz.certificate');

        return response()->json(['data' => [
            'certificate' => is_readable($path) ? file_get_contents($path) : null,
        ]]);
    }

    /** SHA512 / RSA signature of one QZ Tray request, base64 — what qz.security.setSignaturePromise expects. */
    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate(['request' => ['required', 'string', 'max:65535']]);

        $path = config('flightmode.qz.private_key');
        $key = is_readable($path) ? openssl_pkey_get_private(file_get_contents($path)) : false;
        if ($key === false) {
            throw BusinessRuleException::make('qz_not_configured', 'QZ Tray signing key is not installed on the server.');
        }

        if (! openssl_sign($data['request'], $signature, $key, OPENSSL_ALGO_SHA512)) {
            throw BusinessRuleException::make('qz_sign_failed', 'Could not sign the QZ Tray request.');
        }

        return response()->json(['data' => ['signature' => base64_encode($signature)]]);
    }
}
