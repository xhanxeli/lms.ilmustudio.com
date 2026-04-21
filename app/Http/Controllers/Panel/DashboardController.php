<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Mixins\RegistrationPackage\UserPackage;
use App\Models\Comment;
use App\Models\Gift;
use App\Models\Meeting;
use App\Models\ReserveMeeting;
use App\Models\Sale;
use App\Models\Subscribe;
use App\Models\Support;
use App\Models\Webinar;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();

        $nextBadge = $user->getBadges(true, true);

        $data = [
            'pageTitle' => trans('panel.dashboard'),
            'nextBadge' => $nextBadge
        ];

        if (!$user->isUser()) {
            $meetingIds = Meeting::where('creator_id', $user->id)->pluck('id')->toArray();
            $pendingAppointments = ReserveMeeting::whereIn('meeting_id', $meetingIds)
                ->whereHas('sale')
                ->where('status', ReserveMeeting::$pending)
                ->count();

            $userWebinarsIds = $user->webinars->pluck('id')->toArray();
            $supports = Support::whereIn('webinar_id', $userWebinarsIds)
                ->where('status', 'open')
                ->select('id') // Only need count
                ->get();

            $comments = Comment::whereIn('webinar_id', $userWebinarsIds)
                ->where('status', 'active')
                ->whereNull('viewed_at')
                ->select('id') // Only need count
                ->get();

            $time = time();
            $firstDayMonth = strtotime(date('Y-m-01', $time));// First day of the month.
            $lastDayMonth = strtotime(date('Y-m-t', $time));// Last day of the month.

            // Use sum() directly instead of loading all records
            $monthlySalesSum = Sale::where('seller_id', $user->id)
                ->whereNull('refund_at')
                ->whereBetween('created_at', [$firstDayMonth, $lastDayMonth])
                ->sum('total_amount');

            $data['pendingAppointments'] = $pendingAppointments;
            $data['supportsCount'] = count($supports);
            $data['commentsCount'] = count($comments);
            $data['monthlySalesCount'] = round($monthlySalesSum, 2);
            $data['monthlyChart'] = $this->getMonthlySalesOrPurchase($user);
        } else {
            // Use the optimized method that includes both regular purchases and active subscription purchases
            // Cache purchased courses IDs for 2 minutes to reduce database load
            $cacheKey = 'user_purchased_courses_with_active_subscriptions_' . $user->id;
            $webinarsIds = \Cache::remember($cacheKey, 120, function () use ($user) {
                return $user->getPurchasedCoursesIdsWithActiveSubscriptions();
            });

            // Only query if we have webinar IDs
            if (!empty($webinarsIds)) {
                $webinars = Webinar::whereIn('id', $webinarsIds)
                    ->where('status', Webinar::$active)
                    ->select('id', 'slug', 'type', 'status') // Only select columns that exist in webinars table (title is in translations)
                    ->get();
            } else {
                $webinars = collect([]);
            }

            // Optimize: Use join instead of whereHas to avoid subquery
            $reserveMeetings = ReserveMeeting::where('user_id', $user->id)
                ->whereHas('sale', function ($query) {
                    $query->whereNull('refund_at');
                })
                ->where('status', ReserveMeeting::$open)
                ->select('id') // Only need count
                ->get();

            $supports = Support::where('user_id', $user->id)
                ->whereNotNull('webinar_id')
                ->where('status', 'open')
                ->select('id') // Only need count
                ->get();

            $comments = Comment::where('user_id', $user->id)
                ->whereNotNull('webinar_id')
                ->where('status', 'active')
                ->select('id') // Only need count
                ->get();

            // Count active subscription plans only (not expired) - Use optimized method
            $activeSubscribes = Subscribe::getActiveSubscribes($user->id);
            $subscriptionPlansCount = $activeSubscribes->count();

            // Count purchased subjects with LIVE NOW enabled (deduplicated by webinar_id)
            $liveNowSales = Sale::where('buyer_id', $user->id)
                ->whereNull('refund_at')
                ->where('access_to_purchased_item', true)
                ->whereNotNull('webinar_id')
                ->where('type', 'webinar')
                ->whereHas('webinar', function ($query) {
                    $query->where('status', 'active')
                        ->where('type', 'webinar')
                        ->where('live_now', true);
                })
                ->get();
            
            // Deduplicate by webinar_id (keep most recent sale per webinar)
            $liveNowWebinarIdsFromSales = $liveNowSales->unique('webinar_id')->pluck('webinar_id')->toArray();
            
            // Also check for subscription-based purchases (SubscribeUse) that might not have Sale records
            // First get webinar IDs that are live now
            $liveNowWebinarIds = \App\Models\Webinar::where('status', 'active')
                ->where('type', 'webinar')
                ->where('live_now', true)
                ->pluck('id')
                ->toArray();
            
            $subscribeUseLiveNow = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->whereNotNull('webinar_id')
                ->whereIn('webinar_id', $liveNowWebinarIds)
                ->where('active', true)
                ->where(function ($q) {
                    $q->whereNull('expired_at')
                        ->orWhere('expired_at', '>', time());
                })
                ->whereHas('sale', function ($query) {
                    $query->whereNull('refund_at');
                })
                ->whereIn('sale_id', function ($query) {
                    $query->select('id')
                        ->from('sales')
                        ->where('type', Sale::$subscribe)
                        ->whereNull('refund_at');
                })
                ->get();
            
            // Check if subscription plans are not expired and get unique webinar_ids
            $subscribeUseLiveNowWebinarIds = [];
            foreach ($subscribeUseLiveNow as $use) {
                $subscribeSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $use->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($subscribeSale && $subscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                    $isExpired = $subscribeSale->subscribe->days > 0 && 
                               $subscribeSale->subscribe->days <= $daysSincePurchase;
                    
                    if (!$isExpired && !in_array($use->webinar_id, $liveNowWebinarIdsFromSales)) {
                        $subscribeUseLiveNowWebinarIds[] = $use->webinar_id;
                    }
                }
            }
            
            // Combine and count unique webinar IDs
            $liveNowCount = count(array_unique(array_merge($liveNowWebinarIdsFromSales, $subscribeUseLiveNowWebinarIds)));

            // Calculate expired subjects count - Cached to prevent repeated calculations
            $cacheKey = "expired_purchases_count_{$user->id}";
            $expiredPurchasesCount = \Cache::remember($cacheKey, 300, function () use ($user) {
                $expiredCount = 0;
                $allSubscribeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where(function ($q) {
                        $q->whereNotNull('webinar_id')
                            ->orWhereNotNull('bundle_id');
                    })
                    ->with(['sale'])
                    ->get();
                
                // Group SubscribeUse records by item to check if ALL active uses are expired
                $usesByItem = [];
                foreach ($allSubscribeUses as $use) {
                    $itemId = !empty($use->webinar_id) ? $use->webinar_id : $use->bundle_id;
                    $itemName = !empty($use->webinar_id) ? 'webinar_id' : 'bundle_id';
                    
                    if (empty($itemId)) {
                        continue;
                    }
                    
                    $key = $itemName . '_' . $itemId;
                    if (!isset($usesByItem[$key])) {
                        $usesByItem[$key] = [
                            'item_id' => $itemId,
                            'item_name' => $itemName,
                            'uses' => []
                        ];
                    }
                    $usesByItem[$key]['uses'][] = $use;
                }
                
                // Check each item to see if ALL active uses have expired subscriptions
                foreach ($usesByItem as $itemData) {
                    $itemId = $itemData['item_id'];
                    $itemName = $itemData['item_name'];
                    $uses = $itemData['uses'];
                    
                    // Find all active SubscribeUse records for this item
                    $activeUses = [];
                    foreach ($uses as $use) {
                        $isUseActive = $use->active && (is_null($use->expired_at) || $use->expired_at > time());
                        if ($isUseActive) {
                            $activeUses[] = $use;
                        }
                    }
                    
                    // If no active uses, subject is expired
                    if (empty($activeUses)) {
                        $expiredCount++;
                        continue;
                    }
                    
                    // Check if ALL active uses have expired subscriptions
                    $allExpired = true;
                    foreach ($activeUses as $use) {
                        $subscribeSale = Sale::where('buyer_id', $user->id)
                            ->where('type', Sale::$subscribe)
                            ->where('subscribe_id', $use->subscribe_id)
                            ->whereNull('refund_at')
                            ->latest('created_at')
                            ->first();
                        
                        if ($subscribeSale && $subscribeSale->subscribe) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                            $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                            
                            $isSubscriptionExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isSubscriptionExpired = $effectiveExpiration <= time();
                            } else {
                                $isSubscriptionExpired = $subscribeSale->subscribe->days > 0 && 
                                                       $subscribeSale->subscribe->days <= $daysSincePurchase;
                            }
                            
                            // If at least one active use has a non-expired subscription, subject is not expired
                            if (!$isSubscriptionExpired) {
                                $allExpired = false;
                                break;
                            }
                        } else {
                            // No subscription sale found - assume not expired
                            $allExpired = false;
                            break;
                        }
                    }
                    
                    // Only count as expired if ALL active uses have expired subscriptions
                    if ($allExpired) {
                        $expiredCount++;
                    }
                }
                
                return $expiredCount;
            });

            $data['webinarsCount'] = count($webinars);
            $data['supportsCount'] = count($supports);
            $data['expiredSubjectsCount'] = $expiredPurchasesCount;
            $data['subscriptionPlansCount'] = $subscriptionPlansCount;
            $data['reserveMeetingsCount'] = count($reserveMeetings);
            $data['liveNowCount'] = $liveNowCount;
            $data['monthlyChart'] = $this->getMonthlySalesOrPurchase($user);
        }

        $data['giftModal'] = $this->showGiftModal($user);

        return view(getTemplate() . '.panel.dashboard.index', $data);
    }

    private function showGiftModal($user)
    {
        $gift = Gift::query()->where('email', $user->email)
            ->where('status', 'active')
            ->where('viewed', false)
            ->where(function ($query) {
                $query->whereNull('date');
                $query->orWhere('date', '<', time());
            })
            ->whereHas('sale')
            ->first();

        if (!empty($gift)) {
            $gift->update([
                'viewed' => true
            ]);

            $data = [
                'gift' => $gift
            ];

            $result = (string)view()->make('web.default.panel.dashboard.gift_modal', $data);
            $result = str_replace(array("\r\n", "\n", "  "), '', $result);

            return $result;
        }

        return null;
    }

    private function getMonthlySalesOrPurchase($user)
    {
        // Cache monthly chart data for 5 minutes to reduce database load
        $cacheKey = 'user_monthly_chart_' . $user->id . '_' . date('Y');
        
        return \Cache::remember($cacheKey, 300, function () use ($user) {
            $months = [];
            $data = [];

            // all 12 months
            for ($month = 1; $month <= 12; $month++) {
                $date = Carbon::create(date('Y'), $month);

                $start_date = $date->timestamp;
                $end_date = $date->copy()->endOfMonth()->timestamp;

                $months[] = trans('panel.month_' . $month);

                if (!$user->isUser()) {
                    $monthlySales = Sale::where('seller_id', $user->id)
                        ->whereNull('refund_at')
                        ->whereBetween('created_at', [$start_date, $end_date])
                        ->sum('total_amount');

                    $data[] = round($monthlySales, 2);
                } else {
                    $monthlyPurchase = Sale::where('buyer_id', $user->id)
                        ->whereNull('refund_at')
                        ->whereBetween('created_at', [$start_date, $end_date])
                        ->count();

                    $data[] = $monthlyPurchase;
                }
            }

            return [
                'months' => $months,
                'data' => $data
            ];
        });
    }
}
