<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Accounting;
use App\Models\Bundle;
use App\Models\Sale;
use App\Models\Subscribe;
use App\Models\SubscribeUse;
use App\Models\Webinar;
use App\User;
use Illuminate\Http\Request;

class SubscribeController extends Controller
{
    public function apply(Request $request, $webinarSlug)
    {
        $webinar = Webinar::where('slug', $webinarSlug)
            ->where('status', 'active')
            ->where('subscribe', true)
            ->first();

        if (!empty($webinar)) {
            return $this->handleSale($webinar, 'webinar_id');
        }

        abort(404);
    }

    public function bundleApply($bundleSlug)
    {
        $bundle = Bundle::where('slug', $bundleSlug)
            ->where('subscribe', true)
            ->first();

        if (!empty($bundle)) {
            return $this->handleSale($bundle, 'bundle_id');
        }

        abort(404);
    }

    public function remove($webinarSlug)
    {
        if (!auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();
        $webinar = Webinar::where('slug', $webinarSlug)->first();
        if (empty($webinar)) {
            abort(404);
        }

        $use = SubscribeUse::where('user_id', $user->id)
            ->where('webinar_id', $webinar->id)
            ->where('active', true)
            ->first();

        if (empty($use)) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('site.not_found'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        // Block delete while a subscription is still active
        if (Subscribe::getActiveSubscribe($user->id)) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => 'You can unsubscribe only after your subscription expires.',
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $use->expire();

        $toastData = [
            'title' => trans('public.request_success'),
            'msg' => trans('site.remove_success'),
            'status' => 'success'
        ];
        return back()->with(['toast' => $toastData]);
    }

    public function removeBundle($bundleSlug)
    {
        if (!auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();
        $bundle = Bundle::where('slug', $bundleSlug)->first();
        if (empty($bundle)) {
            abort(404);
        }

        $use = SubscribeUse::where('user_id', $user->id)
            ->where('bundle_id', $bundle->id)
            ->where('active', true)
            ->first();

        if (empty($use)) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('site.not_found'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        // Block delete while a subscription is still active
        if (Subscribe::getActiveSubscribe($user->id)) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => 'You can unsubscribe only after your subscription expires.',
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $use->expire();

        $toastData = [
            'title' => trans('public.request_success'),
            'msg' => trans('site.remove_success'),
            'status' => 'success'
        ];
        return back()->with(['toast' => $toastData]);
    }

    private function handleSale($item, $itemName = 'webinar_id')
    {
        if (auth()->check()) {
            $user = auth()->user();

            // Get all active subscriptions
            $activeSubscribes = Subscribe::getActiveSubscribes($user->id);

            if ($activeSubscribes->isEmpty()) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('site.you_dont_have_active_subscribe'),
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }

            // Find the best subscription that can be used for this item
            // Priority: 1) Category match, 2) Most remaining slots, 3) Longest remaining days
            $usableSubscribe = null;
            $bestScore = -1;
            
            foreach ($activeSubscribes as $subscribe) {
                // Check if subscription has category restrictions
                $categoryAllowed = true;
                if ($subscribe->categories && $subscribe->categories->count() > 0) {
                    $allowedCategoryIds = $subscribe->categories->pluck('id')->toArray();
                    if (!in_array($item->category_id, $allowedCategoryIds)) {
                        $categoryAllowed = false;
                    }
                }

                // Check if subscription has available slots
                $hasAvailableSlots = $subscribe->infinite_use || $subscribe->used_count < $subscribe->usable_count;

                if ($categoryAllowed && $hasAvailableSlots) {
                    // Calculate score: prioritize subscriptions with most remaining slots
                    $remainingSlots = $subscribe->infinite_use ? 999999 : ($subscribe->usable_count - $subscribe->used_count);
                    $daysRemaining = isset($subscribe->days_remaining) ? $subscribe->days_remaining : $subscribe->days;
                    $score = $remainingSlots * 1000 + $daysRemaining; // Weight slots more than days
                    
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $usableSubscribe = $subscribe;
                    }
                }
            }

            if (!$usableSubscribe) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('site.no_available_subscription_for_this_item'),
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }

            // Check if user already has an active subscription for this item
            // Only consider SubscribeUse records where the subscription sale is not refunded
            $existingUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where($itemName, $item->id)
                ->where('active', true)
                ->where(function($query) {
                    $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                })
                ->whereHas('sale', function($query) {
                    $query->whereNull('refund_at');
                })
                ->first();

            if ($existingUse) {
                /**
                 * IMPORTANT:
                 * A SubscribeUse can remain active even after the subscription plan expires
                 * (if the expiration job didn't run). So we must validate "active" based on
                 * the subscription *sale* tied to this use (sale_id), not the latest sale.
                 */
                $subscriptionSale = null;

                if (!empty($existingUse->sale_id)) {
                    $subscriptionSale = Sale::where('id', $existingUse->sale_id)
                        ->whereNull('refund_at')
                        ->first();
                }

                // If not found or not a subscribe sale, fallback to latest subscribe sale for that subscribe_id
                if (empty($subscriptionSale) || $subscriptionSale->type !== Sale::$subscribe) {
                    $subscriptionSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $existingUse->subscribe_id)
                        ->whereNull('refund_at')
                        ->latest('created_at')
                        ->first();
                }

                // If we can prove the subscription sale is still active, block. Otherwise allow resubscribe.
                if ($subscriptionSale && $subscriptionSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $subscriptionSale->created_at);
                    $isSubscriptionExpired = $subscriptionSale->subscribe->days > 0 &&
                        $subscriptionSale->subscribe->days <= $daysSincePurchase;

                    if (!$isSubscriptionExpired) {
                        $toastData = [
                            'title' => trans('public.request_failed'),
                            'msg' => trans('site.you_already_have_active_subscription_for_this_item'),
                            'status' => 'error'
                        ];
                        return back()->with(['toast' => $toastData]);
                    }
                } else {
                    // Can't verify an active subscription sale => treat as expired and allow resubscribe
                }
            }

            // Also check for existing Sale records to prevent duplicates
            $existingSale = \App\Models\Sale::where('buyer_id', $user->id)
                ->where($itemName, $item->id)
                ->where('type', $itemName == 'webinar_id' ? \App\Models\Sale::$webinar : \App\Models\Sale::$bundle)
                ->where('payment_method', \App\Models\Sale::$subscribe)
                ->whereNull('refund_at')
                ->first();

            // Note: existing webinar/bundle subscribe "sale" rows can exist historically;
            // the real guard should be based on SubscribeUse + non-expired subscription sale.
            // So we no longer block purely because a historical sale exists.

            $checkCourseForSale = checkCourseForSale($item, $user);

            if ($checkCourseForSale != 'ok') {
                return $checkCourseForSale;
            }

            $sale = Sale::create([
                'buyer_id' => $user->id,
                'seller_id' => $item->creator_id,
                $itemName => $item->id,
                'subscribe_id' => $usableSubscribe->id,
                'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                'payment_method' => Sale::$subscribe,
                'amount' => 0,
                'total_amount' => 0,
                'created_at' => time(),
            ]);

            Accounting::createAccountingForSaleWithSubscribe($item, $usableSubscribe, $itemName);

            // Get the subscription sale_id (the sale where user bought the subscription plan)
            // This is needed because used_count is calculated based on subscription sale_id
            $subscriptionSaleId = $usableSubscribe->sale_id ?? null;
            
            // If sale_id is not set on subscribe object, find it
            if (empty($subscriptionSaleId)) {
                $subscriptionSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $usableSubscribe->id)
                    ->whereNull('refund_at')
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($subscriptionSale) {
                    $subscriptionSaleId = $subscriptionSale->id;
                }
            }

            // Final category validation before creating SubscribeUse
            if (!\App\Models\Subscribe::checkCategoryMatch($usableSubscribe, $item)) {
                \Log::error('Category mismatch prevented SubscribeUse creation', [
                    'user_id' => $user->id,
                    'subscribe_id' => $usableSubscribe->id,
                    'item_id' => $item->id,
                    'item_category_id' => $item->category_id ?? null,
                    'allowed_categories' => $usableSubscribe->categories ? $usableSubscribe->categories->pluck('id')->toArray() : []
                ]);
                
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('site.subscription_category_mismatch'),
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }

            SubscribeUse::create([
                'user_id' => $user->id,
                'subscribe_id' => $usableSubscribe->id,
                $itemName => $item->id,
                'sale_id' => $subscriptionSaleId ?? $sale->id, // Use subscription sale_id for proper counting
                'installment_order_id' => $usableSubscribe->installment_order_id ?? null,
            ]);

            // Clear cache for purchased courses to update dashboard and sidebar counts immediately
            $cacheKeys = [
                "purchased_courses_ids_{$user->id}",
                "user_purchased_courses_with_active_subscriptions_{$user->id}",
                "active_subscribes_installments_{$user->id}",
                "active_subscribes_sales_{$user->id}",
            ];
            foreach ($cacheKeys as $key) {
                \Cache::forget($key);
            }

            // Clear subscription use count caches
            $subscribeCacheKeys = [
                "subscribe_use_count_{$user->id}_{$usableSubscribe->id}_{$subscriptionSaleId}",
                "subscribe_use_count_installment_{$user->id}_{$usableSubscribe->id}_{$usableSubscribe->installment_order_id}",
            ];
            foreach ($subscribeCacheKeys as $key) {
                \Cache::forget($key);
            }

            $toastData = [
                'title' => trans('cart.success_pay_title'),
                'msg' => trans('cart.success_pay_msg_subscribe'),
                'status' => 'success'
            ];
            return back()->with(['toast' => $toastData]);
        } else {
            return redirect('/login');
        }
    }
}
