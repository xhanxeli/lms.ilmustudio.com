<?php

namespace App\PaymentChannels\Drivers\Billplz;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\BasePaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;

class Channel extends BasePaymentChannel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $test_mode;
    protected $billplz_key;
    protected $billplz_collection;

    protected array $credentialItems = [
        'billplz_key',
        'billplz_collection',
    ];

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'billplz.payments.order_id';
        $this->setCredentialItems($paymentChannel);
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $additionalCharges = 1.00; // RM1 additional charge
        $totalAmount = $order->total_amount + $additionalCharges;

        $data = [
            'collection_id' => $this->billplz_collection,
            'email' => $user->email,
            'name' => $user->full_name,
            'amount' => $this->makeAmountByCurrency($totalAmount, $this->currency) * 100, // Billplz requires the amount in cents
            'callback_url' => $this->makeCallbackUrl('return'),
            'redirect_url' => $this->makeCallbackUrl('return'),
            'description' => 'Payment for Order #' . $order->id,
            'reference_1' => $order->id, // Pass order_id for callback
        ];

        $url = $this->getBaseUrl() . '/v3/bills';

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($this->billplz_key . ':'),
            ]);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

            $result = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($result, true);

            if (!empty($response['url'])) {
                session()->put($this->order_session_key, $order->id);
                return $response['url'];
            }
        } catch (\Exception $e) {
            // Log or handle the exception
        }

        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => '',
            'status' => 'error'
        ];

        return redirect()->back()->with(['toast' => $toastData])->withInput();
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Billplz?status=$status");
    }

    private function getBaseUrl()
    {
        return $this->test_mode ? 'https://www.billplz-sandbox.com/api' : 'https://www.billplz.com/api';
    }

    public function verify(Request $request)
    {
        try {
            \Log::info('Billplz verify called', [
                'request_all' => $request->all(),
                'session_order_id' => session($this->order_session_key),
                'query_params' => $request->query(),
            ]);
            
            // Try multiple ways to get the order ID
            // Billplz may return reference_1 in query string or in nested billplz array
            $orderId = $request->get('order_id') 
                ?? $request->get('reference_1') 
                ?? $request->input('billplz.reference_1')
                ?? session($this->order_session_key);
            
            \Log::info('Billplz order lookup', [
                'order_id_from_request' => $orderId,
                'has_order_id_param' => $request->has('order_id'),
                'has_reference_1_param' => $request->has('reference_1'),
                'has_billplz_reference_1' => $request->has('billplz.reference_1'),
            ]);
            
            $order = Order::where('id', $orderId)->first();

            // If order not found and we have a bill ID, query Billplz API to get reference_1
            if (empty($order)) {
                $billId = $request->input('billplz.id');
                if (!empty($billId)) {
                    \Log::info('Order not found via session, querying Billplz API for bill details', [
                        'bill_id' => $billId,
                    ]);
                    
                    $billDetails = $this->getBillDetails($billId);
                    if (!empty($billDetails) && !empty($billDetails['reference_1'])) {
                        $orderId = $billDetails['reference_1'];
                        \Log::info('Found order ID from Billplz API', [
                            'bill_id' => $billId,
                            'order_id_from_reference_1' => $orderId,
                        ]);
                        $order = Order::where('id', $orderId)->first();
                    }
                }
            }

            if (!empty($order)) {
                $paid = $request->get('paid');
                if ($paid === null && $request->has('billplz')) {
                    $paid = $request->input('billplz.paid');
                }
                
                // Get the actual amount paid from Billplz (in cents, need to convert to currency)
                $paidAmount = null;
                if ($request->has('billplz.amount')) {
                    $paidAmountCents = $request->input('billplz.amount');
                    $paidAmount = $paidAmountCents / 100; // Convert from cents to currency
                }
                
                \Log::info('Billplz payment status check', [
                    'order_id' => $order->id,
                    'paid_value' => $paid,
                    'paid_amount_from_billplz' => $paidAmount,
                    'order_total_amount' => $order->total_amount,
                    'expected_payment_amount' => $order->total_amount + 1.00, // Order amount + RM1 Billplz fee
                    'is_charge_account' => $order->is_charge_account,
                ]);
                
                // Verify the paid amount matches expected (order amount + RM1 fee)
                if ($paidAmount !== null && $paid == 'true') {
                    $expectedAmount = $order->total_amount + 1.00; // Order amount + RM1 Billplz fee
                    $amountDifference = abs($paidAmount - $expectedAmount);
                    
                    if ($amountDifference > 0.01) { // Allow 1 cent tolerance for rounding
                        \Log::warning('Billplz payment amount mismatch', [
                            'order_id' => $order->id,
                            'paid_amount' => $paidAmount,
                            'expected_amount' => $expectedAmount,
                            'difference' => $amountDifference,
                            'order_total_amount' => $order->total_amount,
                        ]);
                    }
                }
                
                $status = ($paid == 'true' || $paid == '1' || $paid === 1 || $paid === true) ? Order::$paying : Order::$fail;
                $order->update(['status' => $status]);
                
                // Refresh to ensure we have the updated status
                $order->refresh();
                
                \Log::info('Billplz order updated', [
                    'order_id' => $orderId, 
                    'status' => $status,
                    'order_status_after_update' => $order->status,
                    'order_total_amount' => $order->total_amount,
                    'amount_to_credit' => $order->total_amount,
                ]);
                return $order;
            } else {
                \Log::warning('Billplz order not found', [
                    'order_id' => $orderId,
                    'tried_order_ids' => [
                        'order_id_param' => $request->get('order_id'),
                        'reference_1_param' => $request->get('reference_1'),
                        'billplz_reference_1' => $request->input('billplz.reference_1'),
                        'session_order_id' => session($this->order_session_key),
                    ],
                    'bill_id' => $request->input('billplz.id'),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Billplz verify exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }

    /**
     * Query Billplz API to get bill details by bill ID
     * This is used when order cannot be found via session (e.g., session expired)
     */
    private function getBillDetails($billId)
    {
        try {
            $url = $this->getBaseUrl() . '/v3/bills/' . $billId;
            
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($this->billplz_key . ':'),
            ]);
            
            $result = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode === 200) {
                $response = json_decode($result, true);
                \Log::info('Billplz API bill details retrieved', [
                    'bill_id' => $billId,
                    'reference_1' => $response['reference_1'] ?? null,
                ]);
                return $response;
            } else {
                \Log::warning('Billplz API bill details request failed', [
                    'bill_id' => $billId,
                    'http_code' => $httpCode,
                    'response' => $result,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Billplz API bill details exception', [
                'bill_id' => $billId,
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }
}
