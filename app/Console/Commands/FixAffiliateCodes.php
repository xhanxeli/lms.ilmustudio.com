<?php

namespace App\Console\Commands;

use App\Models\AffiliateCode;
use App\User;
use Illuminate\Console\Command;

class FixAffiliateCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affiliate:fix-codes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix missing affiliate codes for users marked as affiliates';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Fixing Affiliate Codes ===');

        // Find users who are affiliates but don't have affiliate codes
        $affiliateUsers = User::where('affiliate', true)->get();

        $fixedCount = 0;
        $alreadyHaveCodes = 0;

        foreach ($affiliateUsers as $user) {
            $existingCode = AffiliateCode::where('user_id', $user->id)->first();
            
            if ($existingCode) {
                $alreadyHaveCodes++;
                $this->line("User {$user->id} ({$user->full_name}) already has affiliate code: {$existingCode->code}");
            } else {
                $code = mt_rand(100000, 999999);
                
                // Ensure unique code
                while (AffiliateCode::where('code', $code)->exists()) {
                    $code = mt_rand(100000, 999999);
                }
                
                AffiliateCode::create([
                    'user_id' => $user->id,
                    'code' => $code,
                    'created_at' => time()
                ]);
                
                $fixedCount++;
                $this->info("Created affiliate code {$code} for user {$user->id} ({$user->full_name})");
            }
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Total affiliate users: " . $affiliateUsers->count());
        $this->line("Users who already had codes: {$alreadyHaveCodes}");
        $this->line("Users who got new codes: {$fixedCount}");

        if ($fixedCount > 0) {
            $this->info('✅ Affiliate system should now work correctly!');
        } else {
            $this->info('ℹ️  All affiliate users already have codes.');
        }

        return 0;
    }
}
