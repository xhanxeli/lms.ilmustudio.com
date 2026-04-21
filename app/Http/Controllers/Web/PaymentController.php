<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mixins\Cashback\CashbackAccounting;
use App\Models\Accounting;
use App\Models\BecomeInstructor;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\ReserveMeeting;
use App\Models\Reward;
use App\Models\RewardAccounting;
use App\Models\Sale;
use App\Models\TicketUser;
use App\PaymentChannels\ChannelManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    protected $order_session_key = 'payment.order_id';

    public function paymentRequest(Request $request)
    {
        $this->validate($request, [
            'gateway' => 'required'
        ]);

        $user = auth()->user();
        $gateway = $request->input('gateway');
        $orderId = $request->input('order_id');

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if ($order->type === Order::$meeting) {
            $orderItem = OrderItem::where('order_id', $order->id)->first();
            $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
            $reserveMeeting->update(['locked_at' => time()]);
        }

        if ($gateway === 'credit') {
            // Idempotency protection for wallet payments:
            // only one request can transition pending -> paying and process accounting.
            $lockedOrder = null;
            $lockAcquired = DB::transaction(function () use ($order, $user, &$lockedOrder) {
                $lockedOrder = Order::where('id', $order->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (empty($lockedOrder)) {
                    return false;
                }

                if ($lockedOrder->status === Order::$paid) {
                    return false;
                }

                if ($lockedOrder->status !== Order::$pending) {
                    return false;
                }

                $lockedOrder->update([
                    'status' => Order::$paying,
                    'payment_method' => Order::$credit,
                ]);

                return true;
            });

            // Another request already processed (or is processing) this order.
            if (!$lockAcquired || empty($lockedOrder)) {
                session()->put($this->order_session_key, $order->id);
                return redirect('/payments/status');
            }

            // Check wallet balance after acquiring lock to avoid concurrent over-deduction.
            if ($user->getAccountingCharge() < $lockedOrder->total_amount) {
                $lockedOrder->update(['status' => Order::$fail]);
                session()->put($this->order_session_key, $lockedOrder->id);
                return redirect('/payments/status');
            }

            $this->setPaymentAccounting($lockedOrder, 'credit');

            Order::where('id', $lockedOrder->id)
                ->where('status', Order::$paying)
                ->update(['status' => Order::$paid]);

            session()->put($this->order_session_key, $lockedOrder->id);
            return redirect('/payments/status');
        }

        $paymentChannel = PaymentChannel::where('id', $gateway)
            ->where('status', 'active')
            ->first();

        if (!$paymentChannel) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('public.channel_payment_disabled'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $order->payment_method = Order::$paymentChannel;
        $order->save();


        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);
            $redirect_url = $channelManager->paymentRequest($order);

            if (in_array($paymentChannel->class_name, PaymentChannel::$gatewayIgnoreRedirect)) {
                return $redirect_url;
            }

            return Redirect::away($redirect_url);

        } catch (\Exception $exception) {
            
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }
    }

    public function paymentVerify(Request $request, $gateway)
    {
        $paymentChannel = PaymentChannel::where('class_name', $gateway)
            ->where('status', 'active')
            ->first();

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);
            $order = $channelManager->verify($request);

            \Log::info('Payment verify completed', [
                'gateway' => $gateway,
                'order_id' => $order ? $order->id : null,
                'order_status' => $order ? $order->status : null,
                'is_charge_account' => $order ? $order->is_charge_account : null,
            ]);

            return $this->paymentOrderAfterVerify($order);

        } catch (\Exception $exception) {
            \Log::error('Payment verify exception', [
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];
            return redirect('cart')->with(['toast' => $toastData]);
        }
    }

    /*
     * | this methode only run for payku.result
     * */
    public function paykuPaymentVerify(Request $request, $id)
    {
        $paymentChannel = PaymentChannel::where('class_name', PaymentChannel::$payku)
            ->where('status', 'active')
            ->first();

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);

            $request->request->add(['transaction_id' => $id]);

            $order = $channelManager->verify($request);

            return $this->paymentOrderAfterVerify($order);

        } catch (\Exception $exception) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];
            return redirect('cart')->with(['toast' => $toastData]);
        }
    }

    private function paymentOrderAfterVerify($order)
    {
        if (!empty($order)) {
            // Refresh the order to ensure we have the latest status from database
            $order->refresh();
            
            \Log::info('paymentOrderAfterVerify called', [
                'order_id' => $order->id,
                'order_status' => $order->status,
                'is_charge_account' => $order->is_charge_account,
                'total_amount' => $order->total_amount,
            ]);

            // Add a check to prevent duplicate processing if the order is already paid
            if ($order->status == Order::$paid) {
                // For charge account orders, verify that accounting record exists
                if ($order->is_charge_account) {
                    $hasAccounting = \App\Models\Accounting::where('user_id', $order->user_id)
                        ->where('type', \App\Models\Accounting::$addiction)
                        ->where('type_account', \App\Models\Accounting::$asset)
                        ->where('description', 'like', '%Charge account%')
                        ->where('amount', $order->total_amount)
                        ->whereBetween('created_at', [$order->created_at - 3600, $order->created_at + 86400])
                        ->exists();
                    
                    if (!$hasAccounting) {
                        \Log::warning('Order is paid but accounting record missing, creating now', [
                            'order_id' => $order->id,
                            'user_id' => $order->user_id,
                            'amount' => $order->total_amount,
                        ]);
                        // Create the accounting record that should have been created
                        $this->setPaymentAccounting($order);
                    }
                }
                
                \Log::warning('Attempted to process already paid order via payment callback', [
                    'order_id' => $order->id,
                    'current_status' => $order->status,
                    'payment_method' => $order->payment_method,
                ]);
                session()->put($this->order_session_key, $order->id);
                return redirect('/payments/status');
            }

            if ($order->status == Order::$paying) {
                \Log::info('Processing paying order, calling setPaymentAccounting', [
                    'order_id' => $order->id,
                    'is_charge_account' => $order->is_charge_account,
                ]);
                
                try {
                    $this->setPaymentAccounting($order);
                    \Log::info('setPaymentAccounting completed successfully', ['order_id' => $order->id]);
                } catch (\Exception $e) {
                    \Log::error('setPaymentAccounting failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }

                // Use where clause to prevent race condition - only update if still in "paying" status
                $updated = Order::where('id', $order->id)
                    ->where('status', Order::$paying)
                    ->update(['status' => Order::$paid]);
                
                if ($updated) {
                    \Log::info('Order status updated to paid', ['order_id' => $order->id]);
                } else {
                    \Log::warning('Order status update failed - order may have been processed by another request', [
                        'order_id' => $order->id,
                        'current_status' => $order->status,
                    ]);
                    // Refresh to get latest status
                    $order->refresh();
                }
            } else {
                \Log::warning('Order status is not paying, skipping accounting', [
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);
                
                if ($order->type === Order::$meeting) {
                    $orderItem = OrderItem::where('order_id', $order->id)->first();

                    if ($orderItem && $orderItem->reserve_meeting_id) {
                        $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();

                        if ($reserveMeeting) {
                            $reserveMeeting->update(['locked_at' => null]);
                        }
                    }
                }
            }

            session()->put($this->order_session_key, $order->id);

            return redirect('/payments/status');
        } else {
            \Log::warning('paymentOrderAfterVerify called with null order');
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];

            return redirect('cart')->with($toastData);
        }
    }

    public function setPaymentAccounting($order, $type = null)
    {
        \Log::info('setPaymentAccounting called', [
            'order_id' => $order->id,
            'is_charge_account' => $order->is_charge_account,
            'total_amount' => $order->total_amount,
            'user_id' => $order->user_id,
            'type' => $type,
        ]);
        
        $cashbackAccounting = new CashbackAccounting();

        if ($order->is_charge_account) {
            \Log::info('Processing charge account order', [
                'order_id' => $order->id,
                'amount' => $order->total_amount,
            ]);
            
            Accounting::charge($order);
            \Log::info('Accounting::charge completed', ['order_id' => $order->id]);

            $cashbackAccounting->rechargeWallet($order);
            \Log::info('Cashback rechargeWallet completed', ['order_id' => $order->id]);
        } else {
            foreach ($order->orderItems as $orderItem) {
                // Debug: Log the total_amount before creating the sale
                \Log::info('OrderItem total_amount before Sale::createSales', ['order_item_id' => $orderItem->id, 'total_amount' => $orderItem->total_amount]);
                $sale = Sale::createSales($orderItem, $order->payment_method);

                if (!empty($orderItem->reserve_meeting_id)) {
                    $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
                    $reserveMeeting->update([
                        'sale_id' => $sale->id,
                        'reserved_at' => time()
                    ]);

                    $reserver = $reserveMeeting->user;

                    if ($reserver) {
                        $this->handleMeetingReserveReward($reserver);
                    }
                }

                if (!empty($orderItem->gift_id)) {
                    $gift = $orderItem->gift;

                    $gift->update([
                        'status' => 'active'
                    ]);

                    $gift->sendNotificationsWhenActivated($orderItem->total_amount);
                }

                if (!empty($orderItem->subscribe_id)) {
                    // Handle subscription renewal - extend expiration date instead of creating duplicate
                    if (!empty($sale) && $sale->type == Sale::$subscribe) {
                        // Check if this is a renewal (user had this subscription before)
                        $previousSales = Sale::where('buyer_id', $orderItem->user_id)
                            ->where('type', Sale::$subscribe)
                            ->where('subscribe_id', $orderItem->subscribe_id)
                            ->whereNull('refund_at')
                            ->where('id', '!=', $sale->id)
                            ->orderBy('created_at', 'desc')
                            ->get();
                        
                        if ($previousSales->count() > 0) {
                            // Find the most recent previous sale
                            $mostRecentSale = $previousSales->first();
                            $subscribe = \App\Models\Subscribe::find($orderItem->subscribe_id);
                            
                            if ($subscribe) {
                                // Calculate current expiration date
                                $currentExpiration = null;
                                if (!empty($mostRecentSale->custom_expiration_date)) {
                                    $currentExpiration = $mostRecentSale->custom_expiration_date;
                                } else {
                                    $currentExpiration = $mostRecentSale->created_at + ($subscribe->days * 86400);
                                }
                                
                                // Only extend if the previous subscription is expired or expiring within 2 days
                                $twoDaysFromNow = time() + (2 * 86400);
                                if ($currentExpiration <= $twoDaysFromNow) {
                                    // Extend the existing sale's expiration date instead of creating a new one
                                    $newExpiration = max($currentExpiration, time()) + ($subscribe->days * 86400);
                                    $mostRecentSale->custom_expiration_date = $newExpiration;
                                    $mostRecentSale->save();
                                    
                                    // Mark the new sale as refunded since we're extending the old one
                                    // This prevents duplicate subscriptions from showing
                                    $sale->refund_at = time();
                                    $sale->save();
                                    
                                    \Log::info('Extended existing subscription expiration on renewal', [
                                        'user_id' => $orderItem->user_id,
                                        'subscribe_id' => $orderItem->subscribe_id,
                                        'extended_sale_id' => $mostRecentSale->id,
                                        'old_expiration' => date('Y-m-d H:i:s', $currentExpiration),
                                        'new_expiration' => date('Y-m-d H:i:s', $newExpiration),
                                        'new_sale_id_marked_refunded' => $sale->id
                                    ]);
                                    
                                    // Clear all cache for this subscription (including all sales)
                                    \App\Models\Subscribe::clearSubscriptionCache($orderItem->user_id, $orderItem->subscribe_id);
                                    
                                    // Process payment accounting for the renewal
                                    Accounting::createAccountingForSubscribe($orderItem, $type);
                                    // Skip the rest of the loop since we've handled the renewal
                                    continue;
                                } else {
                                    // Previous subscription is still active, expire all previous uses
                                    foreach ($previousSales as $previousSale) {
                                        \App\Models\Subscribe::expireUsesForSale(
                                            $orderItem->user_id, 
                                            $orderItem->subscribe_id, 
                                            $previousSale->id
                                        );
                                    }
                                    
                                    // Also expire any active uses that might still be linked to old sales
                                    \App\Models\SubscribeUse::where('user_id', $orderItem->user_id)
                                        ->where('subscribe_id', $orderItem->subscribe_id)
                                        ->where('active', true)
                                        ->whereNotIn('sale_id', [$sale->id])
                                        ->get()
                                        ->each(function($use) {
                                            $use->expire();
                                        });
                                    
                                    \Log::info('Expired previous subscription uses on renewal (still active)', [
                                        'user_id' => $orderItem->user_id,
                                        'subscribe_id' => $orderItem->subscribe_id,
                                        'new_sale_id' => $sale->id,
                                        'previous_sales_count' => $previousSales->count()
                                    ]);
                                    
                                    // Clear all cache for this subscription (including all sales)
                                    \App\Models\Subscribe::clearSubscriptionCache($orderItem->user_id, $orderItem->subscribe_id);
                                }
                            }
                        }
                    }
                    
                    Accounting::createAccountingForSubscribe($orderItem, $type);
                } elseif (!empty($orderItem->promotion_id)) {
                    Accounting::createAccountingForPromotion($orderItem, $type);
                } elseif (!empty($orderItem->registration_package_id)) {
                    Accounting::createAccountingForRegistrationPackage($orderItem, $type);

                    if (!empty($orderItem->become_instructor_id)) {
                        BecomeInstructor::where('id', $orderItem->become_instructor_id)
                            ->update([
                                'package_id' => $orderItem->registration_package_id
                            ]);
                    }
                } elseif (!empty($orderItem->installment_payment_id)) {
                    Accounting::createAccountingForInstallmentPayment($orderItem, $type);

                    $this->updateInstallmentOrder($orderItem, $sale);
                } else {
                    // webinar and meeting and product and bundle

                    Accounting::createAccounting($orderItem, $type);
                    TicketUser::useTicket($orderItem);

                    if (!empty($orderItem->product_id)) {
                        $this->updateProductOrder($sale, $orderItem);
                    }
                }
            }

            // Set Cashback Accounting For All Order Items
            $cashbackAccounting->setAccountingForOrderItems($order->orderItems);
        }

        Cart::emptyCart($order->user_id);
    }

    public function payStatus(Request $request)
    {
        $orderId = $request->get('order_id', null);

        if (!empty(session()->get($this->order_session_key, null))) {
            $orderId = session()->get($this->order_session_key, null);
            session()->forget($this->order_session_key);
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', auth()->id())
            ->first();

        if (!empty($order)) {
            $data = [
                'pageTitle' => trans('public.cart_page_title'),
                'order' => $order,
            ];

            return view('web.default.cart.status_pay', $data);
        }

        return redirect('/panel');
    }

    private function handleMeetingReserveReward($user)
    {
        if ($user->isUser()) {
            $type = Reward::STUDENT_MEETING_RESERVE;
        } else {
            $type = Reward::INSTRUCTOR_MEETING_RESERVE;
        }

        $meetingReserveReward = RewardAccounting::calculateScore($type);

        RewardAccounting::makeRewardAccounting($user->id, $meetingReserveReward, $type);
    }

    private function updateProductOrder($sale, $orderItem)
    {
        $product = $orderItem->product;

        $status = ProductOrder::$waitingDelivery;

        if ($product and $product->isVirtual()) {
            $status = ProductOrder::$success;
        }

        ProductOrder::where('product_id', $orderItem->product_id)
            ->where(function ($query) use ($orderItem) {
                $query->where(function ($query) use ($orderItem) {
                    $query->whereNotNull('buyer_id');
                    $query->where('buyer_id', $orderItem->user_id);
                });

                $query->orWhere(function ($query) use ($orderItem) {
                    $query->whereNotNull('gift_id');
                    $query->where('gift_id', $orderItem->gift_id);
                });
            })
            ->update([
                'sale_id' => $sale->id,
                'status' => $status,
            ]);

        if ($product and $product->getAvailability() < 1) {
            $notifyOptions = [
                '[p.title]' => $product->title,
            ];
            sendNotification('product_out_of_stock', $notifyOptions, $product->creator_id);
        }
    }

    private function updateInstallmentOrder($orderItem, $sale)
    {
        $installmentPayment = $orderItem->installmentPayment;

        if (!empty($installmentPayment)) {
            $installmentOrder = $installmentPayment->installmentOrder;

            $installmentPayment->update([
                'sale_id' => $sale->id,
                'status' => 'paid',
            ]);

            /* Notification Options */
            $notifyOptions = [
                '[u.name]' => $installmentOrder->user->full_name,
                '[installment_title]' => $installmentOrder->installment->main_title,
                '[time.date]' => dateTimeFormat(time(), 'j M Y - H:i'),
                '[amount]' => handlePrice($installmentPayment->amount),
            ];

            if ($installmentOrder and $installmentOrder->status == 'paying' and $installmentPayment->type == 'upfront') {
                $installment = $installmentOrder->installment;

                if ($installment) {
                    if ($installment->needToVerify()) {
                        $status = 'pending_verification';

                        sendNotification("installment_verification_request_sent", $notifyOptions, $installmentOrder->user_id);
                        sendNotification("admin_installment_verification_request_sent", $notifyOptions, 1); // Admin
                    } else {
                        $status = 'open';

                        sendNotification("paid_installment_upfront", $notifyOptions, $installmentOrder->user_id);
                    }

                    $installmentOrder->update([
                        'status' => $status
                    ]);

                    if ($status == 'open' and !empty($installmentOrder->product_id) and !empty($installmentOrder->product_order_id)) {
                        $productOrder = ProductOrder::query()->where('installment_order_id', $installmentOrder->id)
                            ->where('id', $installmentOrder->product_order_id)
                            ->first();

                        $product = Product::query()->where('id', $installmentOrder->product_id)->first();

                        if (!empty($product) and !empty($productOrder)) {
                            $productOrderStatus = ProductOrder::$waitingDelivery;

                            if ($product->isVirtual()) {
                                $productOrderStatus = ProductOrder::$success;
                            }

                            $productOrder->update([
                                'status' => $productOrderStatus
                            ]);
                        }
                    }
                }
            }


            if ($installmentPayment->type == 'step') {
                sendNotification("paid_installment_step", $notifyOptions, $installmentOrder->user_id);
                sendNotification("paid_installment_step_for_admin", $notifyOptions, 1); // For Admin
            }

        }
    }

}
