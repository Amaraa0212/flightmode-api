<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Models\Airline;
use App\Models\AirlineIpAddress;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Airline IP restriction: on / off and the addresses (IPv4 / IPv6, single or CIDR) its back-office staff may use.
 * Airline users manage their own airline, system administrators any. A change that would lock out the airline
 * user making it (their current address no longer allowed) is refused.
 */
class AirlineIpController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** GET /admin/ip-access?airline_id= (system administrators pick the airline) */
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        $airlineId = $actor->isSystemAdmin() ? $request->integer('airline_id') : $actor->airline_id;
        if (! $airlineId) {
            return response()->json(['data' => null, 'current_ip' => $request->ip()]);
        }

        return response()->json(['data' => $this->present($this->airline($actor, $airlineId)), 'current_ip' => $request->ip()]);
    }

    public function setEnabled(Request $request, Airline $airline): JsonResponse
    {
        $airline = $this->airline($request->user(), $airline->id);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($enabled) {
            if (! $airline->ipAddresses()->exists()) {
                throw ValidationException::withMessages(['enabled' => 'Add at least one IP address first.']);
            }
            $this->ensureNotLockedOut($request, $airline, $airline->ipAddresses()->pluck('ip')->all());
        }

        $airline->update(['ip_restriction_enabled' => $enabled]);
        $this->audit($airline, $enabled ? 'airline.ip_restriction_on' : 'airline.ip_restriction_off', 'IP restriction '.($enabled ? 'on' : 'off'));

        return $this->success($enabled ? 'IP restriction turned on.' : 'IP restriction turned off.', $this->present($airline));
    }

    public function store(Request $request, Airline $airline): JsonResponse
    {
        $airline = $this->airline($request->user(), $airline->id);
        $data = $this->validated($request, $airline);
        $airline->ipAddresses()->create($data);
        $this->audit($airline, 'airline.ip_added', 'IP address added: '.$data['ip']);

        return $this->created('IP address added.', $this->present($airline));
    }

    public function update(Request $request, Airline $airline, AirlineIpAddress $address): JsonResponse
    {
        $airline = $this->airline($request->user(), $airline->id);
        $this->ensureBelongs($airline, $address);
        $data = $this->validated($request, $airline, $address);

        if ($airline->ip_restriction_enabled) {
            $ips = $airline->ipAddresses()->whereKeyNot($address->id)->pluck('ip')->push($data['ip'])->all();
            $this->ensureNotLockedOut($request, $airline, $ips);
        }

        $address->update($data);
        $this->audit($airline, 'airline.ip_updated', 'IP address changed: '.$data['ip']);

        return $this->success('IP address updated.', $this->present($airline));
    }

    public function destroy(Request $request, Airline $airline, AirlineIpAddress $address): JsonResponse
    {
        $airline = $this->airline($request->user(), $airline->id);
        $this->ensureBelongs($airline, $address);

        if ($airline->ip_restriction_enabled) {
            $ips = $airline->ipAddresses()->whereKeyNot($address->id)->pluck('ip')->all();
            if ($ips === []) {
                throw ValidationException::withMessages(['ip' => 'Turn the IP restriction off before removing the last address.']);
            }
            $this->ensureNotLockedOut($request, $airline, $ips);
        }

        $address->delete();
        $this->audit($airline, 'airline.ip_removed', 'IP address removed: '.$address->ip);

        return $this->success('IP address removed.', $this->present($airline));
    }

    /** @return array{ip: string, note: ?string} */
    private function validated(Request $request, Airline $airline, ?AirlineIpAddress $address = null): array
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! self::validIpOrCidr((string) $value)) {
                    $fail('Enter an IPv4 / IPv6 address or a CIDR range (e.g. 203.0.113.0/24).');
                }
            }, Rule::unique('airline_ip_addresses', 'ip')->where('airline_id', $airline->id)->ignore($address?->id)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return ['ip' => trim($data['ip']), 'note' => $data['note'] ?? null];
    }

    public static function validIpOrCidr(string $value): bool
    {
        [$ip, $mask] = array_pad(explode('/', trim($value), 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($mask === null) {
            return true;
        }
        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

        return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= $max;
    }

    /** An airline user cannot leave their own current address outside the list */
    private function ensureNotLockedOut(Request $request, Airline $airline, array $ips): void
    {
        $actor = $request->user();
        if ($actor->isAirlineStaff() && (int) $actor->airline_id === $airline->id && ! IpUtils::checkIp((string) $request->ip(), $ips)) {
            throw ValidationException::withMessages([
                'ip' => 'Your current address ('.$request->ip().') would no longer be allowed. Add it to the list first.',
            ]);
        }
    }

    private function airline(User $actor, int $airlineId): Airline
    {
        if (! $actor->isSystemAdmin() && (int) $actor->airline_id !== $airlineId) {
            throw new AuthorizationException('You can only manage your own airline.');
        }

        return Airline::query()->findOrFail($airlineId);
    }

    private function ensureBelongs(Airline $airline, AirlineIpAddress $address): void
    {
        if ((int) $address->airline_id !== $airline->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function present(Airline $airline): array
    {
        return [
            'airline' => ['id' => $airline->id, 'name' => $airline->name, 'iata_code' => $airline->iata_code],
            'enabled' => (bool) $airline->ip_restriction_enabled,
            'addresses' => $airline->ipAddresses()->orderBy('id')->get(['id', 'ip', 'note', 'created_at']),
        ];
    }

    private function audit(Airline $airline, string $event, string $description): void
    {
        $this->logger->log(ActivityChannel::Admin, $event, $description, $airline);
    }
}
