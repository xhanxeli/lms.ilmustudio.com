<?php

namespace App\Http\Middleware;

use App\Models\IpRestriction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Torann\GeoIP\Facades\GeoIP;

class CheckRestriction
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse) $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $userIp = $request->ip();

        // Cache restrictions briefly to avoid hitting DB on every request
        $restrictions = Cache::remember('ip_restrictions_all', 300, function () {
            return IpRestriction::query()->get();
        });

        if ($restrictions->isEmpty()) {
            return $next($request);
        }

        // Only resolve GeoIP once per request, and only if we have country-based rules
        $hasCountryRules = $restrictions->contains(function ($restriction) {
            return ($restriction->type === 'country');
        });

        $location = null;
        $userCountryCode = null;

        if ($hasCountryRules) {
            // Cache GeoIP lookup per IP to avoid repeated slow external calls
            $geoCacheKey = 'geoip_iso_code_' . $userIp;
            $userCountryCode = Cache::remember($geoCacheKey, 60 * 24, function () use ($userIp) {
                try {
                    $location = GeoIP::getLocation($userIp);
                    return $location['iso_code'] ?? null;
                } catch (\Throwable $e) {
                    // Fail open: if GeoIP service is down/slow, don't block the request
                    return null;
                }
            });
        }

        // Evaluate rules; stop at first match
        foreach ($restrictions as $restriction) {
            if ($restriction->type === 'country') {
                if (!empty($userCountryCode) && $restriction->value == $userCountryCode) {
                    return redirect(route('restrictionRoute'));
                }
            } elseif ($restriction->type === 'full_ip') {
                if ($restriction->value == $userIp) {
                    return redirect(route('restrictionRoute'));
                }
            } elseif ($restriction->type === 'ip_range') {
                if ($this->checkIpRange($userIp, $restriction->value)) {
                    return redirect(route('restrictionRoute'));
                }
            }
        }

        return $next($request);
    }

    /*
     * Old implementation (kept for reference):
     * - Loaded restrictions from DB on every request
     * - Called GeoIP repeatedly inside the loop (slow external dependency)
     * - Overwrote $block each iteration (logic bug)
     */
    /*
    public function handle(Request $request, Closure $next)
    {
        $block = false;
        $userIp = $request->ip();

        $restrictions = IpRestriction::query()->get();

        foreach ($restrictions as $restriction) {
            $block = $this->checkIpRestriction($restriction, $userIp);
        }

        if ($block) {
            return redirect(route('restrictionRoute'));
        }

        return $next($request);
    }
    */

    private function checkIpRestriction($restriction, $ip): bool
    {
        $block = false;

        if ($restriction->type == "country") {
            try {
                $location = GeoIP::getLocation($ip);

                if (!empty($location)) {
                    $userCountryCode = $location['iso_code'] ?? null;

                    $block = !!($restriction->value == $userCountryCode);
                }
            } catch (\Exception $exception) {

            }
        } elseif ($restriction->type == "full_ip") {
            $block = !!($restriction->value == $ip);
        } elseif ($restriction->type == "ip_range") {
            $block = $this->checkIpRange($ip, $restriction->value);
        }

        return $block;
    }

    private function checkIpRange($ip, $ipRange)
    {
        $ipParts = explode('.', $ip);
        $ipRangeParts = explode('.', $ipRange);

        for ($i = 0; $i < count($ipParts); $i++) {
            if ($ipRangeParts[$i] != '*' and $ipParts[$i] != $ipRangeParts[$i]) {
                return false;
            }
        }

        return true;
    }
}
