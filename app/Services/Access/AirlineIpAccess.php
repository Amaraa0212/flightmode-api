<?php

namespace App\Services\Access;

use App\Enums\ActivityChannel;
use App\Models\Airline;
use App\Models\AirlineIpAddress;
use App\Models\User;
use App\Services\ActivityLogger;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Airline IP restriction: when an airline turns it on, its back-office staff (role scope "airline") may sign in
 * and call the API only from the airline's registered addresses (IPv4 / IPv6, single or CIDR).
 * Airport (DCS / gate), agency and system administrator logins are not affected.
 */
class AirlineIpAccess
{
    public const MESSAGE = 'Your airline does not allow sign-in from this network.';

    public function __construct(private readonly ActivityLogger $logger) {}

    public function allows(User $user, ?string $ip): bool
    {
        if (! $user->isAirlineStaff()) {
            return true;
        }

        // read on every request (no cache): turning it on or removing an address applies at once
        $enabled = Airline::query()->whereKey($user->airline_id)->value('ip_restriction_enabled');
        if (! $enabled) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, AirlineIpAddress::query()->where('airline_id', $user->airline_id)->pluck('ip')->all());
    }

    public function logBlocked(User $user, ?string $ip): void
    {
        $this->logger->log(ActivityChannel::Auth, 'auth.ip_blocked', 'Blocked by the airline IP restriction ('.$ip.')', $user,
            properties: ['ip' => $ip], userId: $user->id);
    }
}
