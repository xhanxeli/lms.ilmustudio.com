<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AffiliateApiClient extends Model
{
    protected $table = 'affiliate_api_clients';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return array{0: AffiliateApiClient, 1: string} Client and plain-text secret (show once)
     */
    public static function createWithKeys(?string $name = null): array
    {
        $plainSecret = Str::random(48);
        $client = self::create([
            'name' => $name,
            'access_key' => 'ak_' . Str::random(32),
            'secret_hash' => Hash::make($plainSecret),
            'is_active' => true,
        ]);

        return [$client, $plainSecret];
    }

    public function rotateSecret(): string
    {
        $plainSecret = Str::random(48);
        $this->secret_hash = Hash::make($plainSecret);
        $this->save();

        return $plainSecret;
    }
}
