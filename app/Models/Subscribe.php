<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Subscribe extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'subscribes';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public $translatedAttributes = ['title', 'description'];

    public function getTitleAttribute()
    {
        return getTranslateAttributeValue($this, 'title');
    }

    public function getDescriptionAttribute()
    {
        return getTranslateAttributeValue($this, 'description');
    }

    public function sales()
    {
        return $this->hasMany('App\Models\Sale', 'subscribe_id', 'id');
    }

    public function uses()
    {
        return $this->hasMany('App\Models\SubscribeUse', 'subscribe_id', 'id');
    }

    public function categories()
    {
        return $this->belongsToMany('App\Models\Category', 'subscribe_categories', 'subscribe_id', 'category_id');
    }

    /**
     * @deprecated Use checkAndExpireAllIfNeeded instead
     */
    public static function checkAndExpireIfNeeded($userId)
    {
        self::checkAndExpireAllIfNeeded($userId);
    }

    /**
     * Get all active subscriptions for a user
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    /**
     * Check if an item's category matches the subscription's category restrictions
     * @param Subscribe $subscribe
     * @param mixed $item (Webinar or Bundle)
     * @return bool
     */
    public static function checkCategoryMatch($subscribe, $item)
    {
        // If subscription has no category restrictions, allow all categories
        if (!$subscribe->categories || $subscribe->categories->count() == 0) {
            return true;
        }

        // Get item's category ID
        $itemCategoryId = null;
        if ($item instanceof \App\Models\Webinar) {
            $itemCategoryId = $item->category_id;
        } elseif ($item instanceof \App\Models\Bundle) {
            $itemCategoryId = $item->category_id;
        } elseif (is_object($item) && isset($item->category_id)) {
            $itemCategoryId = $item->category_id;
        }

        if (empty($itemCategoryId)) {
            return false;
        }

        // Check if item's category is in the allowed categories
        $allowedCategoryIds = $subscribe->categories->pluck('id')->toArray();
        return in_array($itemCategoryId, $allowedCategoryIds);
    }

    public static function getActiveSubscribes($userId)
    {
        // DISABLED: Automatic expiration check on every page load
        // This was causing severe performance issues with high concurrent traffic
        // Expiration is now handled by scheduled job: ExpireAllExpiredSubscriptions
        // Uncomment only for debugging or if scheduled job is not running
        // self::checkAndExpireAllIfNeeded($userId);
        
        $activeSubscribes = collect();
        $seenSubscribeIds = []; // Track which subscriptions we've already added (to avoid duplicates)

        // Get all subscription sales - Optimized to use indexes
        // Using select() to only fetch needed columns
        $subscribeSales = Sale::where('buyer_id', $userId)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->select('id', 'buyer_id', 'subscribe_id', 'created_at', 'type', 'refund_at', 'custom_expiration_date') // Only select needed columns
            ->with(['subscribe' => function ($query) {
                $query->select('id', 'days', 'usable_count', 'infinite_use', 'price', 'icon'); // Only select needed columns
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($subscribeSales as $subscribeSale) {
            if (!$subscribeSale->subscribe) {
                continue;
            }

            $subscribeId = $subscribeSale->subscribe->id;
            
            // Skip if we've already added this subscription (only keep the latest active sale)
            if (in_array($subscribeId, $seenSubscribeIds)) {
                continue;
            }

            $subscribe = $subscribeSale->subscribe;
            $subscribe->load('categories');
            $saleCreatedAt = $subscribeSale->created_at;

            $createdAt = Carbon::createFromTimestamp($saleCreatedAt);
            $now = Carbon::now();
            $countDayOfSale = $createdAt->diffInDays($now);

            // Check if subscription is still within time limit
            // Honor custom_expiration_date if it's set (could be from renewal extension).
            //
            // NOTE:
            // Users can renew the same plan many times; capping at (days * 3) will incorrectly
            // mark legitimately-renewed subscriptions as expired. We only guard against
            // obviously-corrupted timestamps.
            $isActive = false;
            $calculatedExpiration = $saleCreatedAt + ($subscribe->days * 86400);
            // Guardrail: allow up to 10 years from the original sale date.
            $maxReasonableExpiration = $saleCreatedAt + (10 * 365 * 86400);
            
            if (!empty($subscribeSale->custom_expiration_date)) {
                // Check if custom_expiration_date is within reasonable bounds
                // If it's beyond maxReasonableExpiration, it's likely a bug - use calculated expiration
                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                    // Custom expiration is unreasonably far in the future - likely a bug
                    // Use calculated expiration instead
                    $effectiveExpiration = $calculatedExpiration;
                    \Log::warning('Subscription custom_expiration_date exceeds reasonable limit, using calculated expiration', [
                        'sale_id' => $subscribeSale->id,
                        'custom_expiration' => date('Y-m-d H:i:s', $subscribeSale->custom_expiration_date),
                        'calculated_expiration' => date('Y-m-d H:i:s', $calculatedExpiration),
                        'max_reasonable' => date('Y-m-d H:i:s', $maxReasonableExpiration)
                    ]);
                } else {
                    // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                }
                $isActive = $effectiveExpiration > time();
            } else {
                // Use original logic: check if days > countDayOfSale
                $isActive = $subscribe->days > $countDayOfSale;
            }

            if (!$isActive) {
                // Subscription is expired - expire all active SubscribeUse records linked to this sale
                // This ensures expired subscriptions don't show incorrect slot counts
                $expiredUses = SubscribeUse::where('user_id', $userId)
                    ->where('subscribe_id', $subscribe->id)
                    ->where('sale_id', $subscribeSale->id)
                    ->where('active', true)
                    ->get();
                
                if ($expiredUses->count() > 0) {
                    foreach ($expiredUses as $use) {
                        $use->expire();
                    }
                    
                    \Log::info('Auto-expired SubscribeUse records for expired subscription', [
                        'user_id' => $userId,
                        'subscribe_id' => $subscribe->id,
                        'sale_id' => $subscribeSale->id,
                        'expired_uses_count' => $expiredUses->count(),
                        'days_since_purchase' => $countDayOfSale,
                        'subscription_days' => $subscribe->days
                    ]);
                }
                
                // Skip this expired subscription
                continue;
            }

            if ($isActive) {
                // Count unique active webinars/bundles for this user, subscription, and specific sale
                // Cache this count for 1 minute
                $useCountCacheKey = "subscribe_use_count_{$userId}_{$subscribe->id}_{$subscribeSale->id}";
                $useCount = \Cache::remember($useCountCacheKey, 60, function () use ($userId, $subscribe, $subscribeSale) {
                    // Count unique webinars and bundles (not all SubscribeUse records)
                    $uniqueWebinarIds = SubscribeUse::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->where('sale_id', $subscribeSale->id)
                        ->where('active', true)
                        ->where(function($query) {
                            $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                        })
                        ->whereNotNull('webinar_id')
                        ->distinct()
                        ->pluck('webinar_id')
                        ->count();
                    
                    $uniqueBundleIds = SubscribeUse::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->where('sale_id', $subscribeSale->id)
                        ->where('active', true)
                        ->where(function($query) {
                            $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                        })
                        ->whereNotNull('bundle_id')
                        ->distinct()
                        ->pluck('bundle_id')
                        ->count();
                    
                    return $uniqueWebinarIds + $uniqueBundleIds;
                });

                $subscribe->used_count = $useCount;
                $subscribe->sale_created_at = $saleCreatedAt;
                $subscribe->sale_id = $subscribeSale->id;
                $subscribe->custom_expiration_date = $subscribeSale->custom_expiration_date;
                
                // Calculate days remaining based on custom expiration date or calculated expiration
                if (!empty($subscribeSale->custom_expiration_date)) {
                    $expirationCarbon = Carbon::createFromTimestamp($subscribeSale->custom_expiration_date);
                    // diffInDays with false parameter returns negative if expiration is in the past
                    // We use max(0, ...) to ensure we never return negative days
                    $daysRemaining = max(0, (int)$now->diffInDays($expirationCarbon, false));
                    $subscribe->days_remaining = $daysRemaining;
                } else {
                    $daysRemaining = max(0, $subscribe->days - $countDayOfSale);
                    $subscribe->days_remaining = $daysRemaining;
                }
                
                $subscribe->auto_renew = (bool)($subscribeSale->auto_renew ?? false);
                
                $activeSubscribes->push($subscribe);
                $seenSubscribeIds[] = $subscribeId; // Mark this subscription as added
            }
        }

        // Check installment orders - Cache this query for 2 minutes
        $installmentCacheKey = "active_subscribes_installments_{$userId}";
        $installmentOrders = \Cache::remember($installmentCacheKey, 120, function () use ($userId) {
            return InstallmentOrder::query()
                ->where('user_id', $userId)
                ->whereNotNull('subscribe_id')
                ->where('status', 'open')
                ->whereNull('refund_at')
                ->select('id', 'user_id', 'subscribe_id', 'created_at', 'status', 'refund_at') // Only select needed columns
                ->with(['subscribe' => function ($query) {
                    $query->select('id', 'days', 'usable_count', 'infinite_use', 'price', 'icon'); // Only select needed columns
                }])
                ->orderBy('created_at', 'desc')
                ->get();
        });

        foreach ($installmentOrders as $installmentOrder) {
            if (!$installmentOrder->subscribe) {
                continue;
            }

            // Check if order has overdue and if it's beyond grace period
            if ($installmentOrder->checkOrderHasOverdue()) {
                $overdueIntervalDays = getInstallmentsSettings('overdue_interval_days');
                if (!empty($overdueIntervalDays) && $installmentOrder->overdueDaysPast() > $overdueIntervalDays) {
                    continue;
                }
            }

            $subscribe = $installmentOrder->subscribe;
            $subscribe->load('categories');
            $subscribe->installment_order_id = $installmentOrder->id;
            $saleCreatedAt = $installmentOrder->created_at;

            $createdAt = Carbon::createFromTimestamp($saleCreatedAt);
            $now = Carbon::now();
            $countDayOfSale = $createdAt->diffInDays($now);

            if ($subscribe->days <= $countDayOfSale) {
                // Installment subscription is expired - expire all active SubscribeUse records
                $expiredUses = SubscribeUse::where('user_id', $userId)
                    ->where('subscribe_id', $subscribe->id)
                    ->where('installment_order_id', $installmentOrder->id)
                    ->where('active', true)
                    ->get();
                
                if ($expiredUses->count() > 0) {
                    foreach ($expiredUses as $use) {
                        $use->expire();
                    }
                    
                    \Log::info('Auto-expired SubscribeUse records for expired installment subscription', [
                        'user_id' => $userId,
                        'subscribe_id' => $subscribe->id,
                        'installment_order_id' => $installmentOrder->id,
                        'expired_uses_count' => $expiredUses->count(),
                        'days_since_purchase' => $countDayOfSale,
                        'subscription_days' => $subscribe->days
                    ]);
                }
                
                // Skip this expired subscription
                continue;
            }

            if ($subscribe->days > $countDayOfSale) {
                // Count active uses for this user, subscription, and specific installment order
                // Cache this count for 1 minute
                $useCountCacheKey = "subscribe_use_count_installment_{$userId}_{$subscribe->id}_{$installmentOrder->id}";
                $useCount = \Cache::remember($useCountCacheKey, 60, function () use ($userId, $subscribe, $installmentOrder) {
                    return SubscribeUse::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->where('installment_order_id', $installmentOrder->id)
                        ->where('active', true)
                        ->where(function($query) {
                            $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                        })
                        ->count();
                });

                $subscribe->used_count = $useCount;
                $subscribe->sale_created_at = $saleCreatedAt;
                $subscribe->days_remaining = $subscribe->days - $countDayOfSale;
                
                // Only add if not already in the collection (avoid duplicates)
                if (!$activeSubscribes->contains('id', $subscribe->id)) {
                    $activeSubscribes->push($subscribe);
                }
            }
        }

        return $activeSubscribes;
    }

    /**
     * Get expired subscriptions that expired within the last 5 days
     * @param int $userId
     * @return \Illuminate\Support\Collection
     */
    public static function getExpiredSubscribes($userId)
    {
        $expiredSubscribes = collect();

        // Get all subscription sales - Optimized to use indexes and cache
        // Cache this query result for 2 minutes to reduce database load
        $cacheKey = "active_subscribes_sales_{$userId}";
        $subscribeSales = \Cache::remember($cacheKey, 120, function () use ($userId) {
            return Sale::where('buyer_id', $userId)
                ->where('type', Sale::$subscribe)
                ->whereNull('refund_at')
                ->select('id', 'buyer_id', 'subscribe_id', 'created_at', 'type', 'refund_at', 'auto_renew', 'custom_expiration_date') // Only select needed columns
                ->with(['subscribe' => function ($query) {
                    $query->select('id', 'days', 'usable_count', 'infinite_use', 'price', 'icon'); // Only select needed columns
                }])
                ->orderBy('created_at', 'desc')
                ->get();
        });

        foreach ($subscribeSales as $subscribeSale) {
            if (!$subscribeSale->subscribe) {
                continue;
            }

            $subscribe = $subscribeSale->subscribe;
            $subscribe->load('categories');
            $saleCreatedAt = $subscribeSale->created_at;

            $createdAt = Carbon::createFromTimestamp($saleCreatedAt);
            $now = Carbon::now();
            $countDayOfSale = $createdAt->diffInDays($now);

            // Check if subscription is expired - use custom_expiration_date if set, otherwise use calculated expiration
            $isExpired = false;
            $daysSinceExpiration = 0;
            
            if (!empty($subscribeSale->custom_expiration_date)) {
                // Check against custom expiration date
                if ($subscribeSale->custom_expiration_date <= time()) {
                    $isExpired = true;
                    $expirationCarbon = Carbon::createFromTimestamp($subscribeSale->custom_expiration_date);
                    $daysSinceExpiration = max(0, $now->diffInDays($expirationCarbon, false));
                }
            } else {
                // Use original logic: check if days <= countDayOfSale
                if ($subscribe->days <= $countDayOfSale) {
                    $isExpired = true;
                    $daysSinceExpiration = $countDayOfSale - $subscribe->days;
                }
            }
            
            if ($isExpired) {
                
                // Only include if expired within last 5 days
                if ($daysSinceExpiration <= 5) {
                    // Check if this subscription has been renewed (has a newer active sale)
                    $hasNewerActiveSale = Sale::where('buyer_id', $userId)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereNull('refund_at')
                        ->where('created_at', '>', $subscribeSale->created_at)
                        ->get()
                        ->contains(function ($newerSale) use ($subscribe) {
                            $newerSaleCreatedAt = Carbon::createFromTimestamp($newerSale->created_at);
                            $now = Carbon::now();
                            $newerCountDayOfSale = $newerSaleCreatedAt->diffInDays($now);
                            // Check if the newer sale is still active
                            return $subscribe->days > $newerCountDayOfSale;
                        });
                    
                    // Skip if subscription has been renewed
                    if ($hasNewerActiveSale) {
                        continue;
                    }
                    
                    $useCount = SubscribeUse::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->where('sale_id', $subscribeSale->id)
                        ->where('active', false)
                        ->count();

                    $subscribe->used_count = $useCount;
                    $subscribe->sale_created_at = $saleCreatedAt;
                    $subscribe->sale_id = $subscribeSale->id;
                    $subscribe->days_expired = $daysSinceExpiration;
                    $subscribe->auto_renew = (bool)($subscribeSale->auto_renew ?? false);
                    
                    // Only add if not already in the collection (avoid duplicates for same subscribe_id)
                    if (!$expiredSubscribes->contains('id', $subscribe->id)) {
                        $expiredSubscribes->push($subscribe);
                    }
                }
            }
        }

        // Check installment orders
        $installmentOrders = InstallmentOrder::query()
            ->where('user_id', $userId)
            ->whereNotNull('subscribe_id')
            ->whereIn('status', ['open', 'canceled'])
            ->whereNull('refund_at')
            ->with('subscribe')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($installmentOrders as $installmentOrder) {
            if (!$installmentOrder->subscribe) {
                continue;
            }

            $subscribe = $installmentOrder->subscribe;
            $subscribe->load('categories');
            $subscribe->installment_order_id = $installmentOrder->id;
            $saleCreatedAt = $installmentOrder->created_at;

            $createdAt = Carbon::createFromTimestamp($saleCreatedAt);
            $now = Carbon::now();
            $countDayOfSale = $createdAt->diffInDays($now);

            if ($subscribe->days <= $countDayOfSale) {
                // Calculate days since expiration
                $daysSinceExpiration = $countDayOfSale - $subscribe->days;
                
                // Only include if expired within last 5 days
                if ($daysSinceExpiration <= 5) {
                    // Check if this subscription has been renewed (has a newer active sale or installment order)
                    $hasNewerActiveSale = Sale::where('buyer_id', $userId)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereNull('refund_at')
                        ->where('created_at', '>', $installmentOrder->created_at)
                        ->get()
                        ->contains(function ($newerSale) use ($subscribe) {
                            $newerSaleCreatedAt = Carbon::createFromTimestamp($newerSale->created_at);
                            $now = Carbon::now();
                            $newerCountDayOfSale = $newerSaleCreatedAt->diffInDays($now);
                            // Check if the newer sale is still active
                            return $subscribe->days > $newerCountDayOfSale;
                        });
                    
                    // Also check for newer active installment orders
                    $hasNewerActiveInstallment = InstallmentOrder::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereIn('status', ['open'])
                        ->whereNull('refund_at')
                        ->where('created_at', '>', $installmentOrder->created_at)
                        ->get()
                        ->contains(function ($newerOrder) use ($subscribe) {
                            $newerOrderCreatedAt = Carbon::createFromTimestamp($newerOrder->created_at);
                            $now = Carbon::now();
                            $newerCountDayOfSale = $newerOrderCreatedAt->diffInDays($now);
                            // Check if the newer order is still active
                            return $subscribe->days > $newerCountDayOfSale;
                        });
                    
                    // Skip if subscription has been renewed
                    if ($hasNewerActiveSale || $hasNewerActiveInstallment) {
                        continue;
                    }
                    
                    $useCount = SubscribeUse::where('user_id', $userId)
                        ->where('subscribe_id', $subscribe->id)
                        ->where('installment_order_id', $installmentOrder->id)
                        ->where('active', false)
                        ->count();

                    $subscribe->used_count = $useCount;
                    $subscribe->sale_created_at = $saleCreatedAt;
                    $subscribe->days_expired = $daysSinceExpiration;
                    
                    // Only add if not already in the collection (avoid duplicates)
                    if (!$expiredSubscribes->contains('id', $subscribe->id)) {
                        $expiredSubscribes->push($subscribe);
                    }
                }
            }
        }

        return $expiredSubscribes;
    }

    /**
     * Get the first/primary active subscription (for backward compatibility)
     * @param int $userId
     * @return Subscribe|null
     */
    public static function getActiveSubscribe($userId)
    {
        $activeSubscribes = self::getActiveSubscribes($userId);
        return $activeSubscribes->first();
    }

    /**
     * Check and expire all subscriptions if needed
     * @param int $userId
     */
    public static function checkAndExpireAllIfNeeded($userId)
    {
        // Use cache to prevent repeated expiration checks within the same request
        // Cache key expires after 5 minutes to allow periodic checks
        $cacheKey = "subscription_expiration_check_{$userId}";
        
        // Check if we've already processed this user in the last 5 minutes
        if (\Cache::has($cacheKey)) {
            return;
        }
        
        $subscribeSales = Sale::where('buyer_id', $userId)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->with('subscribe')
            ->get();

        $expiredCount = 0;
        foreach ($subscribeSales as $subscribeSale) {
            if (!$subscribeSale->subscribe) {
                continue;
            }

            $subscribe = $subscribeSale->subscribe;
            $saleCreatedAt = $subscribeSale->created_at;

            $createdAt = Carbon::createFromTimestamp($saleCreatedAt);
            $now = Carbon::now();
            $countDayOfSale = $createdAt->diffInDays($now);

            // Only expire if the subscription is truly expired
            // Use custom_expiration_date if set, otherwise use calculated expiration
            $shouldExpire = false;
            if (!empty($subscribeSale->custom_expiration_date)) {
                // Check against custom expiration date
                $shouldExpire = $subscribeSale->custom_expiration_date <= time();
            } else {
                // Use original logic
                $shouldExpire = !empty($subscribe) && $subscribe->days > 0 && $subscribe->days <= $countDayOfSale;
            }
            
            if ($shouldExpire) {
                // Only log once per expiration, not on every check
                if ($expiredCount === 0) {
                    \Log::info("Expiring subscription for user $userId: subscribe_id={$subscribe->id}, sale_id={$subscribeSale->id}, days={$subscribe->days}, used_days={$countDayOfSale}");
                }
                self::expireUsesForSale($userId, $subscribe->id, $subscribeSale->id);
                $expiredCount++;
            }
        }
        
        // Cache the check for 5 minutes to prevent repeated processing
        if ($expiredCount > 0) {
            // If we expired something, cache for shorter time (1 minute) to allow re-checking
            \Cache::put($cacheKey, true, now()->addMinute());
        } else {
            // If nothing expired, cache for 5 minutes
            \Cache::put($cacheKey, true, now()->addMinutes(5));
        }
    }

    public static function getDayOfUse($userId)
    {
        $lastSubscribeSale = Sale::where('buyer_id', $userId)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->latest('created_at')
            ->first();

        if ($lastSubscribeSale) {
            $createdAt = Carbon::createFromTimestamp($lastSubscribeSale->created_at);
            $now = Carbon::now();
            return $createdAt->diffInDays($now);
        }
        return 0;
    }

    public function activeSpecialOffer()
    {
        $activeSpecialOffer = SpecialOffer::where('subscribe_id', $this->id)
            ->where('status', SpecialOffer::$active)
            ->where('from_date', '<', time())
            ->where('to_date', '>', time())
            ->first();

        return $activeSpecialOffer ?? false;
    }

    public function getPrice()
    {
        $price = $this->price;

        $specialOffer = $this->activeSpecialOffer();
        if (!empty($specialOffer)) {
            $price = $price - ($price * $specialOffer->percent / 100);
        }

        return $price;
    }

    public static function expireUsesForSale($userId, $subscribeId, $saleId = null, $installmentOrderId = null)
    {
        $query = SubscribeUse::where('user_id', $userId)
            ->where('subscribe_id', $subscribeId);
        if ($saleId) {
            $query->where('sale_id', $saleId);
        }
        if ($installmentOrderId) {
            $query->where('installment_order_id', $installmentOrderId);
        }
        $uses = $query->get();
        foreach ($uses as $use) {
            $use->expire();
        }
    }

    /**
     * Reactivate previous uses up to the plan's class limit when a subscription is renewed.
     * @param int $userId
     * @param int $subscribeId
     * @param int $usableCount
     * @param int|null $saleId
     */
    public static function reactivatePreviousUsesOnRenewal($userId, $subscribeId, $usableCount, $saleId = null)
    {
        // Get all previously expired uses for this user and subscription
        $query = SubscribeUse::where('user_id', $userId)
            ->where('subscribe_id', $subscribeId)
            ->where('active', false)
            ->orderByDesc('expired_at');
        
        // Only consider uses not already linked to the new sale
        if ($saleId) {
            $query->where('sale_id', '!=', $saleId);
        }

        $expiredUses = $query->get();

        // Reactivate up to the plan's class limit
        $count = 0;
        foreach ($expiredUses as $use) {
            if ($count >= $usableCount) break;
            $use->active = true;
            $use->expired_at = null;
            if ($saleId) {
                $use->sale_id = $saleId; // Optionally link to the new sale
            }
            $use->save();
            $count++;
        }
    }

    /**
     * Admin dashboard: active subscription metrics (cached).
     * — activeSubscribersCount: distinct users with at least one active plan (sales ∪ installment).
     */
    public static function getAdminDashboardActiveSubscriptionMetrics(int $cacheTtlSeconds = 600): array
    {
        return Cache::remember('admin_dashboard_active_subscription_metrics_v2', $cacheTtlSeconds, function () {
            $now = time();
            $subscribeType = Sale::$subscribe;

            $activeSubscribersCount = (int) DB::selectOne("
                SELECT COUNT(*) AS c FROM (
                    SELECT DISTINCT s.buyer_id AS uid FROM sales s
                    INNER JOIN subscribes sub ON s.subscribe_id = sub.id
                    INNER JOIN (
                        SELECT buyer_id, subscribe_id, MAX(id) AS mid
                        FROM sales
                        WHERE type = ? AND refund_at IS NULL AND product_order_id IS NULL
                        GROUP BY buyer_id, subscribe_id
                    ) x ON s.id = x.mid
                    WHERE s.type = ? AND s.refund_at IS NULL
                    AND (
                        (s.custom_expiration_date IS NOT NULL AND s.custom_expiration_date > ?)
                        OR (s.custom_expiration_date IS NULL AND (s.created_at + (sub.days * 86400)) > ?)
                    )
                    UNION
                    SELECT DISTINCT io.user_id AS uid FROM installment_orders io
                    INNER JOIN subscribes sub ON io.subscribe_id = sub.id
                    WHERE io.subscribe_id IS NOT NULL
                    AND io.status = 'open'
                    AND io.refund_at IS NULL
                    AND (io.created_at + (sub.days * 86400)) > ?
                ) t
            ", [$subscribeType, $subscribeType, $now, $now, $now])->c;

            return [
                'activeSubscribersCount' => $activeSubscribersCount,
            ];
        });
    }

    /**
     * Clear all cache related to a subscription for a user
     * This includes use count caches for all sales of this subscription
     * @param int $userId
     * @param int $subscribeId
     */
    public static function clearSubscriptionCache($userId, $subscribeId)
    {
        // Get all sales for this subscription
        $allSales = Sale::where('buyer_id', $userId)
            ->where('type', Sale::$subscribe)
            ->where('subscribe_id', $subscribeId)
            ->whereNull('refund_at')
            ->pluck('id')
            ->toArray();
        
        // Clear cache for all sales
        $cacheKeys = [
            "active_subscribes_installments_{$userId}",
            "active_subscribes_sales_{$userId}",
            "purchased_courses_ids_{$userId}",
            "user_purchased_courses_with_active_subscriptions_{$userId}",
            "all_purchased_webinars_ids_{$userId}",
        ];
        
        // Add cache keys for each sale
        foreach ($allSales as $saleId) {
            $cacheKeys[] = "subscribe_use_count_{$userId}_{$subscribeId}_{$saleId}";
        }
        
        // Also clear cache for installment orders if any
        $installmentOrders = \App\Models\InstallmentOrder::where('user_id', $userId)
            ->where('subscribe_id', $subscribeId)
            ->where('status', 'open')
            ->whereNull('refund_at')
            ->pluck('id')
            ->toArray();
        
        foreach ($installmentOrders as $installmentOrderId) {
            $cacheKeys[] = "subscribe_use_count_installment_{$userId}_{$subscribeId}_{$installmentOrderId}";
        }
        
        foreach ($cacheKeys as $key) {
            \Cache::forget($key);
        }
        
        \Log::info('Cleared all subscription cache', [
            'user_id' => $userId,
            'subscribe_id' => $subscribeId,
            'sales_count' => count($allSales),
            'installment_orders_count' => count($installmentOrders)
        ]);
    }
}
