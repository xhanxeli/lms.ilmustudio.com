<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\Webinar;
use App\User;
use Illuminate\Console\Command;

class CheckRefundedAccess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refunds:check-access {--fix : Fix access issues by revoking access}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check all users with refunded sales and verify they do not have access';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Checking users with refunded sales...');
        $this->newLine();

        // Get all refunded sales for courses (webinars)
        $refundedSales = Sale::whereNotNull('refund_at')
            ->where('type', 'webinar')
            ->whereNotNull('webinar_id')
            ->with(['buyer', 'webinar'])
            ->orderBy('refund_at', 'desc')
            ->get();

        if ($refundedSales->isEmpty()) {
            $this->info('No refunded sales found.');
            return 0;
        }

        $this->info("Found {$refundedSales->count()} refunded sales.");
        $this->newLine();

        $issues = [];
        $fixed = 0;

        foreach ($refundedSales as $sale) {
            if (empty($sale->buyer) || empty($sale->webinar)) {
                continue;
            }

            $user = $sale->buyer;
            $course = $sale->webinar;

            // Check if this is the most recent sale for this course
            $mostRecentSale = Sale::where('buyer_id', $user->id)
                ->where('webinar_id', $course->id)
                ->where('type', 'webinar')
                ->orderBy('created_at', 'desc')
                ->first();

            // Only flag if this refunded sale is the most recent one
            if (empty($mostRecentSale) || $mostRecentSale->id != $sale->id) {
                continue; // There's a more recent sale, skip this one
            }

            // Check if there's a valid non-refunded sale
            $validSale = Sale::where('buyer_id', $user->id)
                ->where('webinar_id', $course->id)
                ->where('type', 'webinar')
                ->whereNull('refund_at')
                ->where('access_to_purchased_item', true)
                ->orderBy('created_at', 'desc')
                ->first();

            // If there's a valid non-refunded sale, skip
            if (!empty($validSale)) {
                continue;
            }

            // Check if user still has access
            $hasAccess = $course->checkUserHasBought($user, true, true);

            if ($hasAccess) {
                $issue = [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_name' => $user->full_name,
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'sale_id' => $sale->id,
                    'refunded_at' => date('Y-m-d H:i:s', $sale->refund_at),
                    'access_to_purchased_item' => $sale->access_to_purchased_item,
                ];

                $issues[] = $issue;

                $this->warn("ISSUE FOUND:");
                $this->line("  User: {$user->email} ({$user->full_name})");
                $this->line("  Course: {$course->title}");
                $this->line("  Sale ID: {$sale->id}");
                $this->line("  Refunded At: " . date('Y-m-d H:i:s', $sale->refunded_at));
                $this->line("  access_to_purchased_item: " . ($sale->access_to_purchased_item ? 'true' : 'false'));
                $this->newLine();

                // Fix if --fix option is provided
                if ($this->option('fix')) {
                    if ($sale->access_to_purchased_item) {
                        $sale->update(['access_to_purchased_item' => false]);
                        $this->info("  ✓ Fixed: Set access_to_purchased_item to false");
                        $fixed++;
                    } else {
                        $this->line("  - Already has access_to_purchased_item = false");
                    }
                }
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->line("  Total refunded sales checked: {$refundedSales->count()}");
        $this->line("  Users with access issues: " . count($issues));

        if ($this->option('fix')) {
            $this->line("  Issues fixed: {$fixed}");
        } else {
            $this->line("  Run with --fix option to automatically fix issues");
        }

        // Save report to file
        if (!empty($issues)) {
            $reportFile = storage_path('logs/refunded_access_issues_' . date('Y-m-d_His') . '.json');
            file_put_contents($reportFile, json_encode($issues, JSON_PRETTY_PRINT));
            $this->info("  Report saved to: {$reportFile}");
        }

        return 0;
    }
}

