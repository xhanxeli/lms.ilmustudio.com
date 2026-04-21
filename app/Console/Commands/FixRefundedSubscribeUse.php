<?php

namespace App\Console\Commands;

use App\Models\SubscribeUse;
use App\User;
use Illuminate\Console\Command;

class FixRefundedSubscribeUse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:fix-refunded-uses {--fix : Actually fix the issues by expiring the SubscribeUse records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and fix all active SubscribeUse records associated with refunded subscription sales';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Finding active SubscribeUse records from refunded subscriptions...');
        $this->newLine();

        // Find all active SubscribeUse records where the associated sale is refunded
        $issues = SubscribeUse::where('active', true)
            ->whereHas('sale', function($query) {
                $query->whereNotNull('refund_at');
            })
            ->with('sale')
            ->get();

        if ($issues->isEmpty()) {
            $this->info('No issues found. All SubscribeUse records are properly handled.');
            return 0;
        }

        $this->info("Found {$issues->count()} active SubscribeUse records from refunded subscriptions.");
        $this->newLine();

        // Group by user
        $userCounts = [];
        foreach ($issues as $issue) {
            $userId = $issue->user_id;
            if (!isset($userCounts[$userId])) {
                $user = User::find($userId);
                $userCounts[$userId] = [
                    'email' => $user ? $user->email : 'N/A',
                    'name' => $user ? $user->full_name : 'N/A',
                    'count' => 0,
                    'records' => []
                ];
            }
            $userCounts[$userId]['count']++;
            $userCounts[$userId]['records'][] = [
                'id' => $issue->id,
                'webinar_id' => $issue->webinar_id,
                'sale_id' => $issue->sale_id,
                'sale_refunded_at' => $issue->sale ? date('Y-m-d H:i:s', $issue->sale->refund_at) : 'N/A'
            ];
        }

        $this->info("Affected users: " . count($userCounts));
        $this->newLine();

        // Show top affected users
        $sorted = collect($userCounts)->sortByDesc('count')->take(20);
        $this->info("Top 20 affected users:");
        foreach ($sorted as $userId => $data) {
            $this->line("  - User ID: {$userId}, Email: {$data['email']}, Affected records: {$data['count']}");
        }
        $this->newLine();

        if (!$this->option('fix')) {
            $this->warn('This is a dry run. No changes were made.');
            $this->info('Run with --fix option to actually expire these SubscribeUse records.');
            
            // Save report
            $reportFile = storage_path('logs/refunded_subscribe_uses_' . date('Y-m-d_His') . '.json');
            file_put_contents($reportFile, json_encode([
                'total_affected_records' => $issues->count(),
                'affected_users' => count($userCounts),
                'users' => $userCounts
            ], JSON_PRETTY_PRINT));
            $this->info("Report saved to: {$reportFile}");
            
            return 0;
        }

        // Fix the issues
        $this->info('Fixing issues...');
        $this->newLine();

        $fixed = 0;
        $bar = $this->output->createProgressBar($issues->count());
        $bar->start();

        foreach ($issues as $issue) {
            // Expire the SubscribeUse record
            $issue->active = false;
            $issue->expired_at = time();
            $issue->save();
            $fixed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Summary:");
        $this->line("  Total affected SubscribeUse records: {$issues->count()}");
        $this->line("  Affected users: " . count($userCounts));
        $this->line("  Records fixed (expired): {$fixed}");

        // Save report
        $reportFile = storage_path('logs/refunded_subscribe_uses_fixed_' . date('Y-m-d_His') . '.json');
        file_put_contents($reportFile, json_encode([
            'total_affected_records' => $issues->count(),
            'affected_users' => count($userCounts),
            'fixed_records' => $fixed,
            'users' => $userCounts
        ], JSON_PRETTY_PRINT));
        $this->info("Report saved to: {$reportFile}");

        $this->newLine();
        $this->info('✓ All refunded SubscribeUse records have been expired.');

        return 0;
    }
}

