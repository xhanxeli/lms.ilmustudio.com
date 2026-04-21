<?php

namespace App\Http\Controllers\Api\Panel;

use App\Http\Controllers\Api\Controller;
use App\Models\Api\Bundle;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Accounting;
use App\Models\Api\Webinar;
use App\User;
use App\Models\SubscribeUse;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use App\Models\Api\Subscribe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\URL;


class SubscribesController extends Controller
{
    public function index(Request $request)
    {
        $user = apiAuth();
        $subscribes = Subscribe::all()->map(function ($subscribe) {
            return $subscribe->details;
        });

        $activeSubscribe = Subscribe::getActiveSubscribe($user->id); // First subscription for backward compatibility
        $activeSubscribes = Subscribe::getActiveSubscribes($user->id); // All active subscriptions
        $dayOfUse = Subscribe::getDayOfUse($user->id);

        $data = [
            'subscribes' => $subscribes,
            'subscribed' => ($activeSubscribe) ? true : false,
            'subscribe_id' => ($activeSubscribe) ? $activeSubscribe->id : null,
            'subscribed_title' => ($activeSubscribe) ? $activeSubscribe->title : null,
            'remained_downloads' => ($activeSubscribe) ? $activeSubscribe->usable_count - $activeSubscribe->used_count : null,
            'days_remained' => ($activeSubscribe) ? $activeSubscribe->days - $dayOfUse : null,
            'dayOfUse' => $dayOfUse,
            'active_subscribes' => $activeSubscribes->map(function($subscribe) {
                return [
                    'id' => $subscribe->id,
                    'title' => $subscribe->title,
                    'days_remaining' => $subscribe->days_remaining ?? $subscribe->days,
                    'remained_downloads' => $subscribe->infinite_use ? null : ($subscribe->usable_count - $subscribe->used_count),
                    'infinite_use' => $subscribe->infinite_use,
                ];
            })->toArray(),
        ];
        return apiResponse2(1, 'retrieved', trans('public.retrieved'), $data);
    }

    public function webPayGenerator(Request $request)
    {

        validateParam($request->all(), [
            'subscribe_id' => ['required', Rule::exists('subscribes', 'id')]
        ]);

        $user = apiAuth();
        // Allow users to purchase multiple subscriptions - removed the check that prevented it

        return apiResponse2(1, 'generated', trans('api.link.generated'),
            [
                'link' => URL::signedRoute('my_api.web.subscribe', [apiAuth()->id
                    , $request->input('subscribe_id')
                ])

            ]
        );
    }

    public function webPayRender(Request $request, User $user, $subscribe_id)
    {
        $id = $subscribe_id;
        $subscribe = Subscribe::find($id);
        $amount = $subscribe->price;

        Auth::login($user);

        return view('api.subscribe', compact('amount', 'id'));
    }

    public function pay(Request $request)
    {
        validateParam($request->all(), [
            'subscribe_id' => ['required', Rule::exists('subscribes', 'id')]
        ]);
        $paymentChannels = PaymentChannel::where('status', 'active')->get();

        $subscribe_id = $request->input('subscribe_id');
        $subscribe = Subscribe::find($subscribe_id);

        $user = apiAuth();

        // Note: Trial usage limits are enforced during payment, not during apply

        $activeSubscribe = Subscribe::getActiveSubscribe($user->id);

        if ($activeSubscribe) {

            return apiResponse2(0, 'has_active_subscribe', trans('api.subscribe.has_active_subscribe'));
        }

        $financialSettings = getFinancialSettings();
        $tax = $financialSettings['tax'] ?? 0;

        $amount = $subscribe->price;

        $taxPrice = $tax ? $amount * $tax / 100 : 0;

        $order = Order::create([
            "user_id" => $user->id,
            "status" => Order::$pending,
            'tax' => $taxPrice,
            'commission' => 0,
            "amount" => $amount,
            "total_amount" => $amount + $taxPrice,
            "created_at" => time(),
        ]);

        $orderItem = OrderItem::updateOrCreate([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'subscribe_id' => $subscribe->id,
        ], [
            'amount' => $order->amount,
            'total_amount' => $amount + $taxPrice,
            'tax' => $tax,
            'tax_price' => $taxPrice,
            'commission' => 0,
            'commission_price' => 0,
            'created_at' => time(),
        ]);

        // Note: We don't automatically reactivate previous uses on manual renewal
        // Users get a fresh start with their new subscription
        // Previous expired uses remain expired and users can re-subscribe to subjects if they want

        $razorpay = false;
        foreach ($paymentChannels as $paymentChannel) {
            if ($paymentChannel->class_name == 'Razorpay') {
                $razorpay = true;
            }
        }


        $data = [
            //  'pageTitle' => trans('public.checkout_page_title'),
            'paymentChannels' => $paymentChannels,
            'total' => $order->total_amount,
            'order' => $order,
            // 'count' => 1,
            'userCharge' => $user->getAccountingCharge(),
            'razorpay' => $razorpay
        ];

        return apiResponse2(1, 'retrieved', trans('api.public.retrieved'), $data);

    }

    public function apply(Request $request)
    {
        validateParam($request->all(), [

            'webinar_id' => ['required',
                Rule::exists('webinars', 'id')->where('private', false)
                    ->where('status', 'active')]
        ]);

        $user = apiAuth();

        $subscribe = Subscribe::getActiveSubscribe($user->id);
        $webinar = Webinar::find($request->input('webinar_id'));

        if (!$webinar->subscribe) {
            return apiResponse2(0, 'not_subscribable', trans('api.course.not_subscribable'));

        }

        if (!$subscribe) {

            return apiResponse2(0, 'no_active_subscribe',
                trans('site.you_dont_have_active_subscribe'), null,
                trans('public.request_failed')

            );

        }

        // Note: Trial usage limits are enforced during payment, not during apply

        // Check if user has already used all their subscription slots
        if (!$subscribe->infinite_use && $subscribe->used_count >= $subscribe->usable_count) {
            return apiResponse2(0, 'subscription_limit_reached',
                trans('site.subscription_limit_reached'), null,
                trans('public.request_failed')
            );
        }

        // Check if user already has an active subscription for this webinar
        // Only consider SubscribeUse records where the subscription sale is not refunded
        $existingUse = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where('webinar_id', $webinar->id)
            ->where('active', true)
            ->where(function($query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
            })
            ->whereHas('sale', function($query) {
                $query->whereNull('refund_at');
            })
            ->first();

        if ($existingUse) {
            // Validate "active" based on the subscription sale tied to this use (sale_id), not latest sale.
            $subscriptionSale = null;

            if (!empty($existingUse->sale_id)) {
                $subscriptionSale = \App\Models\Sale::where('id', $existingUse->sale_id)
                    ->whereNull('refund_at')
                    ->first();
            }

            if (empty($subscriptionSale) || $subscriptionSale->type !== \App\Models\Sale::$subscribe) {
                $subscriptionSale = \App\Models\Sale::where('buyer_id', $user->id)
                    ->where('type', \App\Models\Sale::$subscribe)
                    ->where('subscribe_id', $existingUse->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
            }

            if ($subscriptionSale && $subscriptionSale->subscribe) {
                $daysSincePurchase = (int)diffTimestampDay(time(), $subscriptionSale->created_at);
                $isSubscriptionExpired = $subscriptionSale->subscribe->days > 0 &&
                    $subscriptionSale->subscribe->days <= $daysSincePurchase;

                if (!$isSubscriptionExpired) {
                    return apiResponse2(0, 'already_subscribed',
                        trans('site.you_already_have_active_subscription_for_this_item'), null,
                        trans('public.request_failed')
                    );
                }
            }
        }

        // Also check for existing Sale records to prevent duplicates
        $existingSale = \App\Models\Sale::where('buyer_id', $user->id)
            ->where('webinar_id', $webinar->id)
            ->where('type', \App\Models\Sale::$webinar)
            ->where('payment_method', \App\Models\Sale::$subscribe)
            ->whereNull('refund_at')
            ->first();

        // Don't block purely because a historical webinar subscribe-sale exists; rely on SubscribeUse+expiry checks.

        $checkCourseForSale = $webinar->canAddToCart($user);

        if ($checkCourseForSale == 'free') {
            return apiResponse2(0, 'free',
                trans('api.cart.free')

            );
        }

        if ($checkCourseForSale != 'ok') {
            return apiResponse2(0, $checkCourseForSale,
                $webinar->checkCourseForSaleMsg(), null,
                trans('public.request_failed')

            );
        }

        $sale = Sale::create([
            'buyer_id' => $user->id,
            'seller_id' => $webinar->creator_id,
            'webinar_id' => $webinar->id,
            'subscribe_id' => $subscribe->id,
            'type' => Sale::$webinar,
            'payment_method' => Sale::$subscribe,
            'amount' => 0,
            'total_amount' => 0,
            'created_at' => time(),
        ]);

        Accounting::createAccountingForSaleWithSubscribe($webinar, $subscribe,Sale::$webinar."_id");

        // Get the subscription sale_id (the sale where user bought the subscription plan)
        // This is needed because used_count is calculated based on subscription sale_id
        $subscriptionSaleId = $subscribe->sale_id ?? null;
        
        // If sale_id is not set on subscribe object, find it
        if (empty($subscriptionSaleId)) {
            $subscriptionSale = Sale::where('buyer_id', $user->id)
                ->where('type', Sale::$subscribe)
                ->where('subscribe_id', $subscribe->id)
                ->whereNull('refund_at')
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($subscriptionSale) {
                $subscriptionSaleId = $subscriptionSale->id;
            }
        }

        // Final category validation before creating SubscribeUse
        if (!\App\Models\Subscribe::checkCategoryMatch($subscribe, $webinar)) {
            \Log::error('Category mismatch prevented SubscribeUse creation (API)', [
                'user_id' => $user->id,
                'subscribe_id' => $subscribe->id,
                'webinar_id' => $webinar->id,
                'webinar_category_id' => $webinar->category_id ?? null,
                'allowed_categories' => $subscribe->categories ? $subscribe->categories->pluck('id')->toArray() : []
            ]);
            
            return apiResponse2(0, 'category_mismatch',
                trans('site.subscription_category_mismatch'), null,
                trans('public.request_failed')
            );
        }

        // Final category validation before creating SubscribeUse
        if (!\App\Models\Subscribe::checkCategoryMatch($subscribe, $webinar)) {
            \Log::error('Category mismatch prevented SubscribeUse creation (API apply)', [
                'user_id' => $user->id,
                'subscribe_id' => $subscribe->id,
                'webinar_id' => $webinar->id,
                'webinar_category_id' => $webinar->category_id ?? null,
                'allowed_categories' => $subscribe->categories ? $subscribe->categories->pluck('id')->toArray() : []
            ]);
            
            return apiResponse2(0, 'category_mismatch',
                trans('site.subscription_category_mismatch'), null,
                trans('public.request_failed')
            );
        }

        SubscribeUse::create([
            'user_id' => $user->id,
            'subscribe_id' => $subscribe->id,
            'webinar_id' => $webinar->id,
            'sale_id' => $subscriptionSaleId ?? $sale->id, // Use subscription sale_id for proper counting
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
            "subscribe_use_count_{$user->id}_{$subscribe->id}_{$subscriptionSaleId}",
        ];
        foreach ($subscribeCacheKeys as $key) {
            \Cache::forget($key);
        }

        return apiResponse2(1, 'subscribed',
            trans('cart.success_pay_msg_subscribe'),
            null,
            trans('cart.success_pay_title')

        );
    }

    public function generalApply(Request $request)
    {
        validateParam($request->all(), [
            'item_id' => 'required',
            'item_name' => 'required|in:webinar,bundle',
        ]);
        $item_name = $request->input('item_name');
        $item_id = $request->input('item_id');
        $user = apiAuth();

        $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user->id);

        if ($activeSubscribes->isEmpty()) {
            return apiResponse2(0, 'no_active_subscribe',
                trans('site.you_dont_have_active_subscribe')
            );
        }

        if ($item_name == 'webinar') {
            $item = Webinar::where('id', $item_id)
                ->where('status', 'active')
                ->first();
        } elseif ($item_name == 'bundle') {
            $item = Bundle::where('id', $item_id)
                ->first();
        }
        
        if (!$item || !$item->subscribe) {
            return apiResponse2(0, 'not_subscribable', trans('api.course.not_subscribable'));
        }

        // Find the best subscription that can be used for this item
        $subscribe = null;
        $bestScore = -1;
        
        foreach ($activeSubscribes as $sub) {
            // Check if subscription has category restrictions
            $categoryAllowed = true;
            if ($sub->categories && $sub->categories->count() > 0) {
                $allowedCategoryIds = $sub->categories->pluck('id')->toArray();
                if (!in_array($item->category_id, $allowedCategoryIds)) {
                    $categoryAllowed = false;
                }
            }

            // Check if subscription has available slots
            $hasAvailableSlots = $sub->infinite_use || $sub->used_count < $sub->usable_count;

            if ($categoryAllowed && $hasAvailableSlots) {
                // Calculate score: prioritize subscriptions with most remaining slots
                $remainingSlots = $sub->infinite_use ? 999999 : ($sub->usable_count - $sub->used_count);
                $daysRemaining = isset($sub->days_remaining) ? $sub->days_remaining : $sub->days;
                $score = $remainingSlots * 1000 + $daysRemaining;
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $subscribe = $sub;
                }
            }
        }

        if (!$subscribe) {
            return apiResponse2(0, 'no_available_subscription',
                trans('site.no_available_subscription_for_this_item'), null,
                trans('public.request_failed')
            );
        }

        $checkCourseForSale = $item->checkWebinarForSale($user);

        if ($checkCourseForSale != 'ok') {
            return $checkCourseForSale;
        }

        $sale = Sale::create([
            'buyer_id' => $user->id,
            'seller_id' => $item->creator_id,
            $item_name . '_id' => $item->id,
            'subscribe_id' => $subscribe->id,
            'type' => $item_name == 'webinar' ? Sale::$webinar : Sale::$bundle,
            'payment_method' => Sale::$subscribe,
            'amount' => 0,
            'total_amount' => 0,
            'created_at' => time(),
        ]);

        Accounting::createAccountingForSaleWithSubscribe($item, $subscribe, $item_name . '_id');

        // Get the subscription sale_id (the sale where user bought the subscription plan)
        // This is needed because used_count is calculated based on subscription sale_id
        $subscriptionSaleId = $subscribe->sale_id ?? null;
        
        // If sale_id is not set on subscribe object, find it
        if (empty($subscriptionSaleId)) {
            $subscriptionSale = Sale::where('buyer_id', $user->id)
                ->where('type', Sale::$subscribe)
                ->where('subscribe_id', $subscribe->id)
                ->whereNull('refund_at')
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($subscriptionSale) {
                $subscriptionSaleId = $subscriptionSale->id;
            }
        }

        // Final category validation before creating SubscribeUse
        if (!\App\Models\Subscribe::checkCategoryMatch($subscribe, $item)) {
            \Log::error('Category mismatch prevented SubscribeUse creation (API generalApply)', [
                'user_id' => $user->id,
                'subscribe_id' => $subscribe->id,
                'item_id' => $item->id,
                'item_name' => $item_name,
                'item_category_id' => $item->category_id ?? null,
                'allowed_categories' => $subscribe->categories ? $subscribe->categories->pluck('id')->toArray() : []
            ]);
            
            return apiResponse2(0, 'category_mismatch',
                trans('site.subscription_category_mismatch'), null,
                trans('public.request_failed')
            );
        }

        SubscribeUse::create([
            'user_id' => $user->id,
            'subscribe_id' => $subscribe->id,
            $item_name . '_id' => $item->id,
            'sale_id' => $subscriptionSaleId ?? $sale->id, // Use subscription sale_id for proper counting
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
            "subscribe_use_count_{$user->id}_{$subscribe->id}_{$subscriptionSaleId}",
        ];
        foreach ($subscribeCacheKeys as $key) {
            \Cache::forget($key);
        }

        return apiResponse2(1, 'subscribed',
            trans('cart.success_pay_msg_subscribe')

        );

    }

}


