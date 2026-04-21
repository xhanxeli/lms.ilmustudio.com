<?php

namespace App\Console\Commands;

use App\Models\Accounting;
use App\Models\Sale;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixAffiliateCommissionRefunds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affiliate:fix-refunds 
                            {--dry-run : Run without making changes}
                            {--fix-duplicates : Remove incorrect duplicate refund entries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix affiliate commission refunds for all users affected by incorrect refunds';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $fixDuplicates = $this->option('fix-duplicates');

        $this->info('=== Affiliate Commission Refund Fix ===');
        $this->info('Mode: ' . ($dryRun ? 'DRY RUN (no changes will be made)' : 'LIVE (changes will be applied)'));
        $this->info('Fix Duplicates: ' . ($fixDuplicates ? 'Yes' : 'No (only fix missing refunds)'));
        $this->newLine();

        $totalFixed = 0;
        $totalDuplicatesRemoved = 0;
        $totalAmountFixed = 0;
        $totalAmountRemoved = 0;
        $errors = [];

        // Find all refunded sales
        $this->info('Finding all refunded sales...');
        $refundedSales = Sale::whereNotNull('refund_at')
            ->orderBy('refund_at', 'asc')
            ->get();

        $this->info('Found ' . $refundedSales->count() . ' refunded sales');
        $this->newLine();

        $bar = $this->output->createProgressBar($refundedSales->count());
        $bar->start();

        foreach ($refundedSales as $sale) {
            try {
                // Find order items for this sale
                $orderItemIds = [];

                if (!empty($sale->order_id)) {
                    $query = OrderItem::where('order_id', $sale->order_id);

                    if (!empty($sale->webinar_id)) {
                        $query->where('webinar_id', $sale->webinar_id);
                    } elseif (!empty($sale->bundle_id)) {
                        $query->where('bundle_id', $sale->bundle_id);
                    } elseif (!empty($sale->subscribe_id)) {
                        $query->where('subscribe_id', $sale->subscribe_id);
                    } elseif (!empty($sale->promotion_id)) {
                        $query->where('promotion_id', $sale->promotion_id);
                    } elseif (!empty($sale->registration_package_id)) {
                        $query->where('registration_package_id', $sale->registration_package_id);
                    } elseif (!empty($sale->product_order_id)) {
                        $query->where('product_order_id', $sale->product_order_id);
                    }

                    $orderItems = $query->get();
                    $orderItemIds = $orderItems->pluck('id')->toArray();
                }

                // Find affiliate commissions for these order items
                $affiliateCommissions = collect();

                if (!empty($orderItemIds)) {
                    $affiliateCommissions = Accounting::whereIn('order_item_id', $orderItemIds)
                        ->where('is_affiliate_commission', true)
                        ->where('type', Accounting::$addiction)
                        ->where('type_account', Accounting::$income)
                        ->where('system', false)
                        ->get();
                }

                // Fallback: Find by sale attributes if order items not found
                if ($affiliateCommissions->isEmpty()) {
                    $fallbackQuery = Accounting::where('is_affiliate_commission', true)
                        ->where('type', Accounting::$addiction)
                        ->where('type_account', Accounting::$income)
                        ->where('system', false);

                    if (!empty($sale->webinar_id)) {
                        $fallbackQuery->where('webinar_id', $sale->webinar_id);
                    } elseif (!empty($sale->bundle_id)) {
                        $fallbackQuery->where('bundle_id', $sale->bundle_id);
                    } elseif (!empty($sale->subscribe_id)) {
                        $fallbackQuery->where('subscribe_id', $sale->subscribe_id);
                    } elseif (!empty($sale->promotion_id)) {
                        $fallbackQuery->where('promotion_id', $sale->promotion_id);
                    }

                    if (!empty($orderItemIds)) {
                        $fallbackQuery->whereIn('order_item_id', $orderItemIds);
                    }

                    $affiliateCommissions = $fallbackQuery->get();
                }

                if ($affiliateCommissions->isEmpty()) {
                    $bar->advance();
                    continue; // No commissions to refund for this sale
                }

                // Process each commission
                foreach ($affiliateCommissions as $affiliateCommission) {
                    // Check for existing refund entries
                    $refundEntries = Accounting::where('order_item_id', $affiliateCommission->order_item_id)
                        ->where('is_affiliate_commission', true)
                        ->where('type_account', Accounting::$income)
                        ->where('description', 'like', '%' . trans('public.refund_affiliate_commission') . '%')
                        ->where('user_id', $affiliateCommission->user_id)
                        ->where('amount', $affiliateCommission->amount)
                        ->get();

                    $deductionEntries = $refundEntries->where('type', Accounting::$deduction);
                    $addictionEntries = $refundEntries->where('type', Accounting::$addiction);

                    $hasCorrectRefund = $deductionEntries->count() > 0;
                    $hasIncorrectRefund = $addictionEntries->count() > 0;

                    if ($hasIncorrectRefund && $fixDuplicates) {
                        // Remove incorrect addiction entries (these were adding money back)
                        foreach ($addictionEntries as $incorrectEntry) {
                            if (!$dryRun) {
                                $incorrectEntry->delete();
                                Log::info('Removed incorrect affiliate commission refund entry', [
                                    'entry_id' => $incorrectEntry->id,
                                    'sale_id' => $sale->id,
                                    'user_id' => $affiliateCommission->user_id,
                                    'amount' => $incorrectEntry->amount,
                                ]);
                            }

                            $totalDuplicatesRemoved++;
                            $totalAmountRemoved += $incorrectEntry->amount;
                        }
                    }

                    if (!$hasCorrectRefund) {
                        // Create correct deduction entry
                        if (!$dryRun) {
                            Accounting::create([
                                'user_id' => $affiliateCommission->user_id,
                                'order_item_id' => $affiliateCommission->order_item_id,
                                'system' => false,
                                'referred_user_id' => $affiliateCommission->referred_user_id,
                                'is_affiliate_commission' => true,
                                'amount' => $affiliateCommission->amount,
                                'webinar_id' => $affiliateCommission->webinar_id,
                                'bundle_id' => $affiliateCommission->bundle_id,
                                'product_id' => $affiliateCommission->product_id,
                                'meeting_time_id' => $affiliateCommission->meeting_time_id,
                                'subscribe_id' => $affiliateCommission->subscribe_id,
                                'promotion_id' => $affiliateCommission->promotion_id,
                                'type_account' => Accounting::$income,
                                'type' => Accounting::$deduction,
                                'description' => trans('public.refund_affiliate_commission') . ' (Fixed by script)',
                                'created_at' => $sale->refund_at, // Use the refund date
                            ]);

                            Log::info('Created missing affiliate commission refund entry', [
                                'sale_id' => $sale->id,
                                'user_id' => $affiliateCommission->user_id,
                                'amount' => $affiliateCommission->amount,
                                'order_item_id' => $affiliateCommission->order_item_id,
                            ]);
                        }

                        $totalFixed++;
                        $totalAmountFixed += $affiliateCommission->amount;
                    }
                }
            } catch (\Exception $e) {
                $errorMsg = "Error processing sale ID {$sale->id}: " . $e->getMessage();
                $errors[] = $errorMsg;
                Log::error('Error in affiliate commission refund fix command', [
                    'sale_id' => $sale->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('=== Summary ===');
        $this->info("Total refund entries fixed: {$totalFixed}");
        $this->info("Total amount fixed: " . number_format($totalAmountFixed, 2));

        if ($fixDuplicates) {
            $this->info("Total duplicate entries removed: {$totalDuplicatesRemoved}");
            $this->info("Total amount removed from duplicates: " . number_format($totalAmountRemoved, 2));
        }

        if (!empty($errors)) {
            $this->error("\nErrors encountered: " . count($errors));
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        $this->newLine();
        $this->info('Done!');

        return 0;
    }
}
