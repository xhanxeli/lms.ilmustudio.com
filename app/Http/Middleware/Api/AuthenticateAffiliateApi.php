<?php

namespace App\Http\Middleware\Api;

use App\Models\AffiliateApiClient;
use Closure;
use Illuminate\Support\Facades\Hash;

class AuthenticateAffiliateApi
{
    public function handle($request, Closure $next)
    {
        $accessKey = $request->header('X-Access-Key');
        $secret = $request->header('X-Secret-Key');

        if (empty($accessKey) || empty($secret)) {
            return apiResponse2(0, 'invalid', 'Missing X-Access-Key or X-Secret-Key header.');
        }

        $client = AffiliateApiClient::where('access_key', $accessKey)->first();

        if (!$client || !$client->is_active || !Hash::check($secret, $client->secret_hash)) {
            return apiResponse2(0, 'invalid', 'Invalid or inactive API credentials.');
        }

        $now = time();
        if ($client->last_used_at === null || ($now - (int) $client->last_used_at) > 120) {
            $client->last_used_at = $now;
            $client->save();
        }

        $request->attributes->set('affiliate_api_client', $client);

        return $next($request);
    }
}
