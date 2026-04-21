<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Webinar;

class AutoDisableLiveNow extends Command
{
    protected $signature = 'webinars:auto-disable-live-now';
    protected $description = 'Automatically disable Live Now for webinars that have been active for more than 12 hours';

    public function handle()
    {
        $currentTime = time();
        $twelveHoursInSeconds = 12 * 60 * 60; // 12 hours
        
        // Find all webinars where live_now is true and live_now_at is set
        $webinars = Webinar::where('live_now', true)
            ->whereNotNull('live_now_at')
            ->where('type', Webinar::$webinar)
            ->get();
        
        $count = 0;
        
        foreach ($webinars as $webinar) {
            $timeElapsed = $currentTime - $webinar->live_now_at;
            
            if ($timeElapsed >= $twelveHoursInSeconds) {
                $webinar->update([
                    'live_now' => false,
                    'live_now_at' => null
                ]);
                
                $this->info("Disabled Live Now for webinar ID: {$webinar->id} - {$webinar->title}");
                $count++;
            }
        }
        
        if ($count > 0) {
            $this->info("Done. Disabled Live Now for {$count} webinar(s).");
        } else {
            $this->info("No webinars needed to be disabled.");
        }
        
        return 0;
    }
}






