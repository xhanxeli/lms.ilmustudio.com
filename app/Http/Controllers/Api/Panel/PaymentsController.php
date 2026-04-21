<?php

namespace App\Http\Controllers\Api\Panel;

use App\Http\Controllers\Controller;
use App\Models\Accounting;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use App\Models\ReserveMeeting;
use App\Models\Sale;
use App\Models\TicketUser;
use App\PaymentChannels\ChannelManager;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\URL;


class PaymentsController extends Controller
{
    protected $order_session_key;

    public function __construct()
    {
        $this->order_session_key = 'payment.order_id';
    }

    public function paymentByCredit(Request $request)
    {
        validateParam($request->all(), [
            'order_id' => ['required',
                Rule::exists('orders', 'id')->where('status', Order::$pending),

            ],
        ]);

        $user = apiAuth();
        $orderId = $request->input('order_id');

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();


        if ($order->type === Order::$meeting) {
            $orderItem = OrderItem::where('order_id', $order->id)->first();
            $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
            $reserveMeeting->update(['locked_at' => time()]);
        }

        if ($user->getAccountingCharge() < $order->amount) {
            $order->update(['status' => Order::$fail]);

            return apiResponse2(0, 'not_enough_credit', trans('api.payment.not_enough_credit'));


        }

        $order->update([
            'payment_method' => Order::$credit
        ]);

        $this->setPaymentAccounting($order, 'credit');

        $order->update([
            'status' => Order::$paid
        ]);

        return apiResponse2(1, 'paid', trans('api.payment.paid'));

    }


    public function paymentRequest(Request $request)
    {
        $user = apiAuth();
        validateParam($request->all(), [
            'gateway_id' => ['required',
                Rule::exists('payment_channels', 'id')
            ],
            'order_id' => ['required',
                Rule::exists('orders', 'id')->where('status', Order::$pending)
                    ->where('user_id', $user->id),

            ],


        ]);


        $gateway = $request->input('gateway_id');
        $orderId = $request->input('order_id');

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();


        if ($order->type === Order::$meeting) {
            $orderItem = OrderItem::where('order_id', $order->id)->first();
            $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
            $reserveMeeting->update(['locked_at' => time()]);
        }


        $paymentChannel = PaymentChannel::where('id', $gateway)
            ->where('status', 'active')
            ->first();

        if (!$paymentChannel) {
            return apiResponse2(0, 'disabled_gateway', trans('api.payment.disabled_gateway'));
        }

        $order->payment_method = Order::$paymentChannel;
        $order->save();

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);
            $redirect_url = $channelManager->paymentRequest($order);


            if (in_array($paymentChannel->class_name, ['Paytm', 'Payu', 'Zarinpal', 'Stripe', 'Paysera', 'Cashu', 'Iyzipay', 'MercadoPago'])) {

                return $redirect_url;
            }

            return $redirect_url;
            //      dd($redirect_url) ;
            return Redirect::away($redirect_url);

        } catch (\Exception $exception) {

            if (!$paymentChannel) {
                return apiResponse2(0, 'gateway_error', trans('api.payment.gateway_error'));
            }

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

            \Log::info('API Payment verify completed', [
                'gateway' => $gateway,
                'order_id' => $order ? $order->id : null,
                'order_status' => $order ? $order->status : null,
                'is_charge_account' => $order ? $order->is_charge_account : null,
            ]);

            if (!empty($order)) {
                // Refresh the order to ensure we have the latest status from database
                $order->refresh();
                
                $orderItem = OrderItem::where('order_id', $order->id)->first();

                $reserveMeeting = null;
                if ($orderItem && $orderItem->reserve_meeting_id) {
                    $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
                }

                // Check if order is already paid to prevent duplicate processing
                if ($order->status == Order::$paid) {
                    // For charge account orders, verify that accounting record exists
                    if ($order->is_charge_account) {
                        $hasAccounting = Accounting::where('user_id', $order->user_id)
                            ->where('type', Accounting::$addiction)
                            ->where('type_account', Accounting::$asset)
                            ->where('description', 'like', '%Charge account%')
                            ->where('amount', $order->total_amount)
                            ->whereBetween('created_at', [$order->created_at - 3600, $order->created_at + 86400])
                            ->exists();
                        
                        if (!$hasAccounting) {
                            \Log::warning('API: Order is paid but accounting record missing, creating now', [
                                'order_id' => $order->id,
                                'user_id' => $order->user_id,
                                'amount' => $order->total_amount,
                            ]);
                            $this->setPaymentAccounting($order);
                        }
                    }
                    
                    \Log::warning('API: Attempted to process already paid order via payment callback', [
                        'order_id' => $order->id,
                        'current_status' => $order->status,
                    ]);
                    session()->put($this->order_session_key, $order->id);
                    return redirect('/payments/status');
                }

                if ($order->status == Order::$paying) {
                    \Log::info('API: Processing paying order, calling setPaymentAccounting', [
                        'order_id' => $order->id,
                        'is_charge_account' => $order->is_charge_account,
                    ]);
                    
                    try {
                        $this->setPaymentAccounting($order);
                        \Log::info('API: setPaymentAccounting completed successfully', ['order_id' => $order->id]);
                    } catch (\Exception $e) {
                        \Log::error('API: setPaymentAccounting failed', [
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
                        \Log::info('API: Order status updated to paid', ['order_id' => $order->id]);
                    } else {
                        \Log::warning('API: Order status update failed - order may have been processed by another request', [
                            'order_id' => $order->id,
                            'current_status' => $order->status,
                        ]);
                        // Refresh to get latest status
                        $order->refresh();
                    }
                } else {
                    \Log::warning('API: Order status is not paying, skipping accounting', [
                        'order_id' => $order->id,
                        'status' => $order->status,
                    ]);
                    
                    if ($order->type === Order::$meeting) {
                        if ($reserveMeeting) {
                            $reserveMeeting->update(['locked_at' => null]);
                        }
                    }
                }

                session()->put($this->order_session_key, $order->id);

                return redirect('/payments/status');
            } else {
                \Log::warning('API: paymentVerify called with null order', ['gateway' => $gateway]);
                $toastData = [
                    'title' => trans('cart.fail_purchase'),
                    'msg' => trans('cart.gateway_error'),
                    'status' => 'error'
                ];

                return redirect('cart')->with($toastData);
            }

        } catch (\Exception $exception) {
            \Log::error('API: Payment verify exception', [
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

    public function setPaymentAccounting($order, $type = null)
    {
        if ($order->is_charge_account) {
            Accounting::charge($order);
        } else {
            foreach ($order->orderItems as $orderItem) {
                $sale = Sale::createSales($orderItem, $order->payment_method);

                if (!empty($orderItem->reserve_meeting_id)) {
                    $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
                    $reserveMeeting->update([
                        'sale_id' => $sale->id,
                        'reserved_at' => time()
                    ]);
                }

                if (!empty($orderItem->subscribe_id)) {
                    // When a subscription is renewed, expire all previous uses
                    // This ensures users get a fresh start and must re-subscribe to subjects
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
                                    $sale->refund_at = time();
                                    $sale->save();
                                    
                                    \Log::info('Extended existing subscription expiration on renewal (API)', [
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
                                    
                                    \Log::info('Expired previous subscription uses on renewal (API, still active)', [
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
                } else {
                    // webinar and meeting

                    Accounting::createAccounting($orderItem, $type);
                    TicketUser::useTicket($orderItem);
                }
            }
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

        abort(404);
    }

    public function webChargeGenerator(Request $request)
    {
        return apiResponse2(1, 'generated', trans('api.link.generated'),
            [
                'link' => URL::signedRoute('my_api.web.charge', [apiAuth()->id])
            ]
        );

    }

    public function webChargeRender(User $user)
    {
        Auth::login($user);
        return redirect('/panel/financial/account');

    }


    public function charge(Request $request)
    {
        validateParam($request->all(), [
            'amount' => 'required|numeric',
            'gateway_id' => ['required',
                Rule::exists('payment_channels', 'id')->where('status', 'active')
            ]
            ,
        ]);


        $gateway_id = $request->input('gateway_id');
        $amount = $request->input('amount');


        $userAuth = apiAuth();

        $paymentChannel = PaymentChannel::find($gateway_id);

        $order = Order::create([
            'user_id' => $userAuth->id,
            'status' => Order::$pending,
            'payment_method' => Order::$paymentChannel,
            'is_charge_account' => true,
            'total_amount' => $amount,
            'amount' => $amount,
            'created_at' => time(),
            'type' => Order::$charge,
        ]);


        OrderItem::updateOrCreate([
            'user_id' => $userAuth->id,
            'order_id' => $order->id,
        ], [
            'amount' => $amount,
            'total_amount' => $amount,
            'tax' => 0,
            'tax_price' => 0,
            'commission' => 0,
            'commission_price' => 0,
            'created_at' => time(),
        ]);


        if ($paymentChannel->class_name == 'Razorpay') {
            return $this->echoRozerpayForm($order);
        } else {
            $paymentController = new PaymentsController();

            $paymentRequest = new Request();
            $paymentRequest->merge([
                'gateway_id' => $paymentChannel->id,
                'order_id' => $order->id
            ]);

            return $paymentController->paymentRequest($paymentRequest);
        }
    }

    private function echoRozerpayForm($order)
    {
        $generalSettings = getGeneralSettings();

        echo '<form action="/payments/verify/Razorpay" method="get">
            <input type="hidden" name="order_id" value="' . $order->id . '">

            <script src="/assets/default/js/app.js"></script>
            <script src="https://checkout.razorpay.com/v1/checkout.js"
                    data-key="' . env('RAZORPAY_API_KEY') . '"
                    data-amount="' . (int)($order->total_amount * 100) . '"
                    data-buttontext="product_price"
                    data-description="Rozerpay"
                    data-currency="' . currency() . '"
                    data-image="' . $generalSettings['logo'] . '"
                    data-prefill.name="' . $order->user->full_name . '"
                    data-prefill.email="' . $order->user->email . '"
                    data-theme.color="#43d477">
            </script>

            <style>
                .razorpay-payment-button {
                    opacity: 0;
                    visibility: hidden;
                }
            </style>

            <script>
                $(document).ready(function() {
                    $(".razorpay-payment-button").trigger("click");
                })
            </script>
        </form>';
        return '';
    }

}
