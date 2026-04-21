<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Accounting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\Subscribe;
use App\Models\SubscribeUse;
use App\User;
use Carbon\Carbon;

class ExpireAllExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire-expired';
    protected $description = 'Expire all SubscribeUse records for users whose subscriptions have expired.';

    private function getEffectiveExpirationTimestamp(Sale $subscribeSale, Subscribe $subscribe): ?int
    {
        $saleCreatedAt = (int)$subscribeSale->created_at;
        $days = (int)($subscribe->days ?? 0);

        if ($saleCreatedAt <= 0 || $days <= 0) {
            return null;
        }

        $calculatedExpiration = $saleCreatedAt + ($days * 86400);
        $maxReasonableExpiration = $saleCreatedAt + (($days * 3) + 7) * 86400;

        if (!empty($subscribeSale->custom_expiration_date)) {
            $custom = (int)$subscribeSale->custom_expiration_date;
            return ($custom > $maxReasonableExpiration) ? $calculatedExpiration : $custom;
        }

        return $calculatedExpiration;
    }

    public function handle()
    {
        $activeUseUserIds = SubscribeUse::where('active', true)->distinct()->pluck('user_id')->toArray();
        $autoRenewUserIds = Sale::where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->where('auto_renew', true)
            ->distinct()
            ->pluck('buyer_id')
            ->toArray();

        $userIds = collect(array_unique(array_merge($activeUseUserIds, $autoRenewUserIds)));

        $expiredCount = 0;
        $renewedCount = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            $subscribeSales = Sale::where('buyer_id', $userId)
                ->where('type', Sale::$subscribe)
                ->whereNull('refund_at')
                ->with('subscribe')
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($subscribeSales as $subscribeSale) {
                $subscribe = $subscribeSale->subscribe;
                if (!$subscribe) {
                    continue;
                }

                $effectiveExpiration = $this->getEffectiveExpirationTimestamp($subscribeSale, $subscribe);

                // If days is invalid, skip expiration handling for safety
                if (empty($effectiveExpiration)) {
                    continue;
                }

                if ($effectiveExpiration <= time()) {
                    // If user already has a newer active sale for this same plan, just expire the old uses and skip renewing
                    $hasNewerActiveSamePlan = Sale::where('buyer_id', $userId)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereNull('refund_at')
                        ->where('created_at', '>', $subscribeSale->created_at)
                        ->get()
                        ->contains(function ($newerSale) use ($subscribe) {
                            $createdAt = Carbon::createFromTimestamp($newerSale->created_at);
                            $days = $createdAt->diffInDays(Carbon::now());
                            return $subscribe->days > $days;
                        });

                    Subscribe::expireUsesForSale($userId, $subscribe->id, $subscribeSale->id);
                    $expiredCount++;

                    if ($hasNewerActiveSamePlan) {
                        continue;
                    }

                    if (!empty($subscribeSale->auto_renew)) {
                        $newSale = $this->renewSubscriptionByCredit($user, $subscribe, (bool)$subscribeSale->auto_renew);

                        if ($newSale) {
                            $renewedCount++;
                            $this->info("Auto-renewed subscription for user {$userId}, subscribe_id {$subscribe->id}, new_sale_id {$newSale->id}");
                        } else {
                            $this->info("Auto-renew failed (insufficient credit or error) for user {$userId}, subscribe_id {$subscribe->id}");
                        }
                    }
                }
            }
        }

        $this->info("Done. Expired uses processed: {$expiredCount}. Auto-renewed: {$renewedCount}.");
    }

    private function renewSubscriptionByCredit(User $user, Subscribe $subscribe, bool $autoRenew)
    {
        try {
            $financialSettings = getFinancialSettings();
            $tax = $financialSettings['tax'] ?? 0;

            $amount = $subscribe->getPrice();
            $amount = $amount > 0 ? $amount : 0;

            $taxPrice = $tax ? $amount * $tax / 100 : 0;
            $totalAmount = $amount + $taxPrice;

            // Must have enough wallet balance
            if ($user->getAccountingCharge() < $totalAmount) {
                return null;
            }

            $order = Order::create([
                "user_id" => $user->id,
                "status" => Order::$paid,
                'payment_method' => Order::$credit,
                'tax' => $taxPrice,
                'commission' => 0,
                "amount" => $amount,
                "total_amount" => $totalAmount,
                "created_at" => time(),
                'type' => Order::$subscribe,
            ]);

            $orderItem = OrderItem::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'subscribe_id' => $subscribe->id,
                'amount' => $amount,
                'total_amount' => $totalAmount,
                'tax' => $tax,
                'tax_price' => $taxPrice,
                'commission' => 0,
                'commission_price' => 0,
                'discount' => 0,
                'created_at' => time(),
            ]);

            $sale = Sale::createSales($orderItem, Order::$credit);

            // Carry over auto-renew flag to the new subscription sale
            if (Schema::hasColumn('sales', 'auto_renew')) {
                $sale->auto_renew = $autoRenew;
                $sale->save();
            }

            Accounting::createAccountingForSubscribe($orderItem, 'credit');

            // Reactivate previous uses up to the plan limit and link them to the new subscription sale
            Subscribe::reactivatePreviousUsesOnRenewal($user->id, $subscribe->id, $subscribe->usable_count, $sale->id);

            return $sale;
        } catch (\Throwable $e) {
            // swallow to avoid breaking the whole cron run
            return null;
        }
    }
} 