<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\traits\InstallmentsTrait;
use App\Mixins\Installment\InstallmentPlans;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use App\Models\Sale;
use App\Models\Subscribe;
use Illuminate\Http\Request;

class SubscribesController extends Controller
{
    use InstallmentsTrait;

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

    public function index(Request $request)
    {
        $this->authorize("panel_financial_subscribes");

        $user = auth()->user();

        if (!$user){
            $user = apiAuth();
        }

        $search = $request->get('search', '');
        $totalSubjectsFilter = $request->get('total_subjects', '');

        $subscribesQuery = Subscribe::query();

        // Search by subscription plan name
        if (!empty($search)) {
            $subscribesQuery->whereTranslationLike('title', "%{$search}%");
        }

        // Filter by total subjects (usable_count)
        if (!empty($totalSubjectsFilter)) {
            if ($totalSubjectsFilter == 'unlimited') {
                $subscribesQuery->where('infinite_use', true);
            } else {
                $subscribesQuery->where('infinite_use', false)
                    ->where('usable_count', $totalSubjectsFilter);
            }
        }

        $subscribes = $subscribesQuery->get();

        $installmentPlans = new InstallmentPlans($user);
        foreach ($subscribes as $subscribe) {
            if (getInstallmentsSettings('status') and $user->enable_installments and $subscribe->price > 0) {
                $installments = $installmentPlans->getPlans('subscription_packages', $subscribe->id);

                $subscribe->has_installment = (!empty($installments) and count($installments));
            }
        }

        // Get unique usable_count values for filter dropdown
        $uniqueUsableCounts = Subscribe::where('infinite_use', false)
            ->distinct()
            ->orderBy('usable_count', 'asc')
            ->pluck('usable_count')
            ->toArray();

        $data = [
            'pageTitle' => trans('financial.subscribes'),
            'subscribes' => $subscribes,
            'activeSubscribe' => Subscribe::getActiveSubscribe($user->id), // For backward compatibility
            'activeSubscribes' => Subscribe::getActiveSubscribes($user->id), // All active subscriptions
            'expiredSubscribes' => Subscribe::getExpiredSubscribes($user->id), // Expired subscriptions (within 5 days)
            'dayOfUse' => Subscribe::getDayOfUse($user->id),
            'search' => $search,
            'totalSubjectsFilter' => $totalSubjectsFilter,
            'uniqueUsableCounts' => $uniqueUsableCounts,
        ];

        return view(getTemplate() . '.panel.financial.subscribes', $data);
    }

    public function pay(Request $request)
    {
        $paymentChannels = PaymentChannel::where('status', 'active')
            ->where('class_name', '!=', PaymentChannel::$billplz)
            ->get();

        $subscribe = Subscribe::where('id', $request->input('id'))->first();

        if (empty($subscribe)) {
            $toastData = [
                'msg' => trans('site.subscribe_not_valid'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $user = auth()->user();
        
        // Check if user already has an active subscription for this plan
        $existingSubscribeSales = Sale::where('buyer_id', $user->id)
            ->where('type', Sale::$subscribe)
            ->where('subscribe_id', $subscribe->id)
            ->whereNull('refund_at')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($existingSubscribeSales as $existingSale) {
            // Use effective expiration to support custom expiration dates.
            $effectiveExpiration = $this->getEffectiveExpirationTimestamp($existingSale, $subscribe);

            // Check if subscription is still active (not expired)
            if (!empty($effectiveExpiration) && $effectiveExpiration > time()) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('update.you_already_have_active_subscription_plan'),
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }
        }

        $financialSettings = getFinancialSettings();
        $tax = $financialSettings['tax'] ?? 0;

        $amount = $subscribe->getPrice();
        $amount = $amount > 0 ? $amount : 0;

        // Handle discount coupon
        $discountCoupon = null;
        $discountCouponPrice = 0;
        $discountId = $request->input('discount_id');

        if (!empty($discountId)) {
            $discountCoupon = Discount::find($discountId);
            
            if (!empty($discountCoupon)) {
                // Re-validate discount
                $validation = $this->validateDiscountForSubscribe($discountCoupon, $user, $amount);
                
                if ($validation['valid']) {
                    if ($discountCoupon->discount_type == Discount::$discountTypeFixedAmount) {
                        $discountCouponPrice = ($amount > $discountCoupon->amount) ? $discountCoupon->amount : $amount;
                    } else {
                        $discountAmount = $amount * ($discountCoupon->percent / 100);
                        
                        if (!empty($discountCoupon->max_amount) and $discountAmount > $discountCoupon->max_amount) {
                            $discountCouponPrice = $discountCoupon->max_amount;
                        } else {
                            $discountCouponPrice = $discountAmount;
                        }
                    }
                    
                    if ($discountCouponPrice > $amount) {
                        $discountCouponPrice = $amount;
                    }
                } else {
                    $discountCoupon = null;
                    $discountCouponPrice = 0;
                }
            }
        }

        $amountAfterDiscount = $amount - $discountCouponPrice;
        if ($amountAfterDiscount < 0) {
            $amountAfterDiscount = 0;
        }

        $taxPrice = $tax ? $amountAfterDiscount * $tax / 100 : 0;

        // Enforce: Free trial can only be subscribed once and only for new users (no prior subscribes)
        if ($amount == 0) {
            $hasAnySubscribeSale = Sale::where('buyer_id', $user->id)
                ->where('type', Sale::$subscribe)
                ->exists();

            if ($hasAnySubscribeSale) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('update.free_trial_only_once_for_new_users'),
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }
        }

        $order = Order::create([
            "user_id" => $user->id,
            "status" => Order::$pending,
            'tax' => $taxPrice,
            'commission' => 0,
            "amount" => $amount,
            'total_discount' => $discountCouponPrice,
            "total_amount" => $amountAfterDiscount + $taxPrice,
            "created_at" => time(),
        ]);

        $orderItem = OrderItem::updateOrCreate([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'subscribe_id' => $subscribe->id,
        ], [
            'amount' => $amount,
            'total_amount' => $amountAfterDiscount + $taxPrice,
            'tax' => $tax,
            'tax_price' => $taxPrice,
            'commission' => 0,
            'commission_price' => 0,
            'discount' => $discountCouponPrice,
            'discount_id' => !empty($discountCoupon) ? $discountCoupon->id : null,
            'created_at' => time(),
        ]);

        if ($amount > 0) {

            $razorpay = false;
            foreach ($paymentChannels as $paymentChannel) {
                if ($paymentChannel->class_name == 'Razorpay') {
                    $razorpay = true;
                }
            }

            $data = [
                'pageTitle' => trans('public.checkout_page_title'),
                'paymentChannels' => $paymentChannels,
                'total' => $order->total_amount,
                'order' => $order,
                'count' => 1,
                'userCharge' => $user->getAccountingCharge(),
                'razorpay' => $razorpay,
                'totalDiscount' => $discountCouponPrice,
                'subscribe_id' => $subscribe->id,
                'original_amount' => $amount
            ];

            return view(getTemplate() . '.cart.payment', $data);
        }

        // Handle Free
        $sale = Sale::createSales($orderItem, Sale::$credit);
        
        // When a subscription is renewed (even free), expire all previous uses
        // This ensures users get a fresh start and must re-subscribe to subjects
        if (!empty($sale) && $sale->type == Sale::$subscribe) {
            // Check if this is a renewal (user had this subscription before)
            $previousSales = Sale::where('buyer_id', $user->id)
                ->where('type', Sale::$subscribe)
                ->where('subscribe_id', $subscribe->id)
                ->whereNull('refund_at')
                ->where('id', '!=', $sale->id)
                ->orderBy('created_at', 'desc')
                ->get();
            
            // If there are previous sales, this is a renewal - expire all previous uses
            if ($previousSales->count() > 0) {
                // Expire all uses from previous subscription periods
                foreach ($previousSales as $previousSale) {
                    Subscribe::expireUsesForSale(
                        $user->id, 
                        $subscribe->id, 
                        $previousSale->id
                    );
                }
                
                // Also expire any active uses that might still be linked to old sales
                \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where('subscribe_id', $subscribe->id)
                    ->where('active', true)
                    ->whereNotIn('sale_id', [$sale->id])
                    ->get()
                    ->each(function($use) {
                        $use->expire();
                    });
                
                \Log::info('Expired previous subscription uses on free renewal', [
                    'user_id' => $user->id,
                    'subscribe_id' => $subscribe->id,
                    'new_sale_id' => $sale->id,
                    'previous_sales_count' => $previousSales->count()
                ]);
                
                // Clear all cache for this subscription (including all sales)
                \App\Models\Subscribe::clearSubscriptionCache($user->id, $subscribe->id);
            }
        }

        $toastData = [
            'title' => 'public.request_success',
            'msg' => trans('update.success_pay_msg_for_free_subscribe'),
            'status' => 'success'
        ];
        return back()->with(['toast' => $toastData]);
    }

    // Auto-renew functionality removed
    // public function toggleAutoRenew(Request $request)
    // {
    //     $this->authorize("panel_financial_subscribes");
    //
    //     $user = auth()->user();
    //
    //     $data = $request->validate([
    //         'sale_id' => 'required|integer',
    //         'enable' => 'required|in:0,1',
    //     ]);
    //
    //     $sale = Sale::where('id', $data['sale_id'])
    //         ->where('buyer_id', $user->id)
    //         ->where('type', Sale::$subscribe)
    //         ->whereNull('refund_at')
    //         ->first();
    //
    //     if (!$sale) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => trans('public.request_failed'),
    //         ], 404);
    //     }
    //
    //     $sale->auto_renew = (bool)$data['enable'];
    //     $sale->save();
    //
    //     return response()->json([
    //         'status' => 'success',
    //         'auto_renew' => (bool)$sale->auto_renew,
    //     ], 200);
    // }

    public function couponValidate(Request $request)
    {
        $user = auth()->user();
        $coupon = $request->get('coupon');
        $subscribe_id = $request->get('subscribe_id');

        if (empty($coupon)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        $discountCoupon = Discount::where('code', $coupon)->first();

        if (empty($discountCoupon)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        // Check if discount is expired
        if ($discountCoupon->expired_at < time()) {
            return response()->json([
                'status' => 422,
                'msg' => trans('update.discount_code_has_expired')
            ]);
        }

        // Check if discount source is 'all' (can be used for subscriptions)
        if ($discountCoupon->source != Discount::$discountSourceAll) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        // Check user type restrictions
        if ($discountCoupon->user_type == 'special_users') {
            $userDiscount = \App\Models\DiscountUser::where('user_id', $user->id)
                ->where('discount_id', $discountCoupon->id)
                ->first();

            if (empty($userDiscount)) {
                return response()->json([
                    'status' => 422,
                    'msg' => trans('cart.coupon_invalid')
                ]);
            }
        }

        // Check group restrictions
        if (!empty($discountCoupon->discountGroups) and count($discountCoupon->discountGroups)) {
            $groupsIds = $discountCoupon->discountGroups()->pluck('group_id')->toArray();

            if (empty($user->userGroup) or !in_array($user->userGroup->group_id, $groupsIds)) {
                return response()->json([
                    'status' => 422,
                    'msg' => trans('update.discount_code_group_error')
                ]);
            }
        }

        // Check first purchase restriction
        if ($discountCoupon->for_first_purchase) {
            $checkIsFirstPurchase = Sale::where('buyer_id', $user->id)
                ->whereNull('refund_at')
                ->count();

            if ($checkIsFirstPurchase > 0) {
                return response()->json([
                    'status' => 422,
                    'msg' => trans('update.discount_code_for_first_purchase_error')
                ]);
            }
        }

        // Check usage count
        $usedCount = 0;
        $orderItems = OrderItem::where('discount_id', $discountCoupon->id)
            ->groupBy('order_id')
            ->get();

        foreach ($orderItems as $orderItem) {
            if (!empty($orderItem) and !empty($orderItem->order) and $orderItem->order->status == 'paid') {
                $usedCount += 1;
            }
        }

        if ($usedCount >= $discountCoupon->count) {
            return response()->json([
                'status' => 422,
                'msg' => trans('update.discount_code_used_count_error')
            ]);
        }

        // Get subscribe price
        $subscribe = Subscribe::find($subscribe_id);
        if (empty($subscribe)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('site.subscribe_not_valid')
            ]);
        }

        $amount = $subscribe->getPrice();

        // Check minimum order
        if (!empty($discountCoupon->minimum_order) and $discountCoupon->minimum_order > $amount) {
            return response()->json([
                'status' => 422,
                'msg' => trans('update.discount_code_minimum_order_error', ['min_order' => handlePrice($discountCoupon->minimum_order)])
            ]);
        }

        // Calculate discount
        $totalDiscount = 0;
        $financialSettings = getFinancialSettings();
        $tax = $financialSettings['tax'] ?? 0;

        if ($discountCoupon->discount_type == Discount::$discountTypeFixedAmount) {
            $totalDiscount = ($amount > $discountCoupon->amount) ? $discountCoupon->amount : $amount;
        } else {
            $discountAmount = $amount * ($discountCoupon->percent / 100);
            
            if (!empty($discountCoupon->max_amount) and $discountAmount > $discountCoupon->max_amount) {
                $totalDiscount = $discountCoupon->max_amount;
            } else {
                $totalDiscount = $discountAmount;
            }
        }

        if ($totalDiscount > $amount) {
            $totalDiscount = $amount;
        }

        $subTotalAfterDiscount = $amount - $totalDiscount;
        $taxPrice = $tax ? $subTotalAfterDiscount * $tax / 100 : 0;
        $totalAmount = $subTotalAfterDiscount + $taxPrice;

        return response()->json([
            'status' => 200,
            'discount_id' => $discountCoupon->id,
            'total_discount' => handlePrice($totalDiscount),
            'total_tax' => handlePrice($taxPrice),
            'total_amount' => handlePrice($totalAmount),
            'sub_total' => handlePrice($amount),
        ], 200);
    }

    private function validateDiscountForSubscribe($discountCoupon, $user, $amount)
    {
        // Check if discount is expired
        if ($discountCoupon->expired_at < time()) {
            return ['valid' => false, 'msg' => trans('update.discount_code_has_expired')];
        }

        // Check if discount source is 'all' (can be used for subscriptions)
        if ($discountCoupon->source != Discount::$discountSourceAll) {
            return ['valid' => false, 'msg' => trans('cart.coupon_invalid')];
        }

        // Check user type restrictions
        if ($discountCoupon->user_type == 'special_users') {
            $userDiscount = \App\Models\DiscountUser::where('user_id', $user->id)
                ->where('discount_id', $discountCoupon->id)
                ->first();

            if (empty($userDiscount)) {
                return ['valid' => false, 'msg' => trans('cart.coupon_invalid')];
            }
        }

        // Check group restrictions
        if (!empty($discountCoupon->discountGroups) and count($discountCoupon->discountGroups)) {
            $groupsIds = $discountCoupon->discountGroups()->pluck('group_id')->toArray();

            if (empty($user->userGroup) or !in_array($user->userGroup->group_id, $groupsIds)) {
                return ['valid' => false, 'msg' => trans('update.discount_code_group_error')];
            }
        }

        // Check first purchase restriction
        if ($discountCoupon->for_first_purchase) {
            $checkIsFirstPurchase = Sale::where('buyer_id', $user->id)
                ->whereNull('refund_at')
                ->count();

            if ($checkIsFirstPurchase > 0) {
                return ['valid' => false, 'msg' => trans('update.discount_code_for_first_purchase_error')];
            }
        }

        // Check usage count
        $usedCount = 0;
        $orderItems = OrderItem::where('discount_id', $discountCoupon->id)
            ->groupBy('order_id')
            ->get();

        foreach ($orderItems as $orderItem) {
            if (!empty($orderItem) and !empty($orderItem->order) and $orderItem->order->status == 'paid') {
                $usedCount += 1;
            }
        }

        if ($usedCount >= $discountCoupon->count) {
            return ['valid' => false, 'msg' => trans('update.discount_code_used_count_error')];
        }

        // Check minimum order
        if (!empty($discountCoupon->minimum_order) and $discountCoupon->minimum_order > $amount) {
            return ['valid' => false, 'msg' => trans('update.discount_code_minimum_order_error', ['min_order' => handlePrice($discountCoupon->minimum_order)])];
        }

        return ['valid' => true];
    }

    public function applyCouponToOrder(Request $request)
    {
        $user = auth()->user();
        $orderId = $request->input('order_id');
        $coupon = $request->input('coupon');
        $subscribe_id = $request->input('subscribe_id');

        if (empty($orderId) || empty($coupon) || empty($subscribe_id)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('status', Order::$pending)
            ->first();

        if (empty($order)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        $orderItem = OrderItem::where('order_id', $order->id)
            ->where('subscribe_id', $subscribe_id)
            ->first();

        if (empty($orderItem)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        $discountCoupon = Discount::where('code', $coupon)->first();

        if (empty($discountCoupon)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('cart.coupon_invalid')
            ]);
        }

        $subscribe = Subscribe::find($subscribe_id);
        if (empty($subscribe)) {
            return response()->json([
                'status' => 422,
                'msg' => trans('site.subscribe_not_valid')
            ]);
        }

        $amount = $subscribe->getPrice();

        // Validate discount
        $validation = $this->validateDiscountForSubscribe($discountCoupon, $user, $amount);
        
        if (!$validation['valid']) {
            return response()->json([
                'status' => 422,
                'msg' => $validation['msg']
            ]);
        }

        // Calculate discount
        $discountCouponPrice = 0;
        if ($discountCoupon->discount_type == Discount::$discountTypeFixedAmount) {
            $discountCouponPrice = ($amount > $discountCoupon->amount) ? $discountCoupon->amount : $amount;
        } else {
            $discountAmount = $amount * ($discountCoupon->percent / 100);
            
            if (!empty($discountCoupon->max_amount) and $discountAmount > $discountCoupon->max_amount) {
                $discountCouponPrice = $discountCoupon->max_amount;
            } else {
                $discountCouponPrice = $discountAmount;
            }
        }
        
        if ($discountCouponPrice > $amount) {
            $discountCouponPrice = $amount;
        }

        $amountAfterDiscount = $amount - $discountCouponPrice;
        if ($amountAfterDiscount < 0) {
            $amountAfterDiscount = 0;
        }

        $financialSettings = getFinancialSettings();
        $tax = $financialSettings['tax'] ?? 0;
        $taxPrice = $tax ? $amountAfterDiscount * $tax / 100 : 0;
        $totalAmount = $amountAfterDiscount + $taxPrice;

        // Update order
        $order->update([
            'amount' => $amount,
            'total_discount' => $discountCouponPrice,
            'tax' => $taxPrice,
            'total_amount' => $totalAmount,
        ]);

        // Update order item
        $orderItem->update([
            'amount' => $amount,
            'total_amount' => $totalAmount,
            'tax' => $tax,
            'tax_price' => $taxPrice,
            'discount' => $discountCouponPrice,
            'discount_id' => $discountCoupon->id,
        ]);

        return response()->json([
            'status' => 200,
            'discount_id' => $discountCoupon->id,
            'total_discount' => handlePrice($discountCouponPrice),
            'total_tax' => handlePrice($taxPrice),
            'total_amount' => handlePrice($totalAmount),
            'total_amount_numeric' => $totalAmount, // Add numeric value for JavaScript comparison
            'sub_total' => handlePrice($amount),
        ], 200);
    }
}
