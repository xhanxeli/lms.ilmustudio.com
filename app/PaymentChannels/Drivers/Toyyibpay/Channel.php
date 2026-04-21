<?php

namespace App\PaymentChannels\Drivers\Toyyibpay;

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
    protected $toyyibpay_key;
    protected $toyyibpay_category;

    protected array $credentialItems = [
        'toyyibpay_key',
        'toyyibpay_category',
    ];

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'toyyibpay.payments.order_id';
        $this->setCredentialItems($paymentChannel);
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $data = [
            'userSecretKey' => $this->toyyibpay_key,
            'categoryCode' => $this->toyyibpay_category,
            'billName' => 'Ecommerce Cart Payment',
            'billDescription' => 'Payment Using ToyyibPay',
            'billPriceSetting' => 1,
            'billPayorInfo' => 1,
            'billAmount' => $this->makeAmountByCurrency($order->total_amount, $this->currency),
            'billReturnUrl' => $this->makeCallbackUrl('return'),
            'billCallbackUrl' => $this->makeCallbackUrl('return'),
            'billExternalReferenceNo' => $order->id,
            'billTo' => $user->full_name,
            'billEmail' => $user->email,
            'billPhone' => $user->mobile,
            'billSplitPayment' => 0,
            'billSplitPaymentArgs' => '',
            'billPaymentChannel' => 2,
            'billContentEmail' => 'Thank you for purchasing our product!',
            'billChargeToCustomer' => 2
        ];

        session()->put($this->order_session_key, $order->id);

        $site_url = $this->test_mode ? 'https://dev.toyyibpay.com/' : 'https://toyyibpay.com/';

        $url = $site_url . 'index.php/api/createBill';

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            $result = curl_exec($curl);
            curl_close($curl);
            $obj = json_decode($result, true);

            if (isset($obj[0]['BillCode'])) {
                $billcode = $obj[0]['BillCode'];
                return $site_url . $billcode;
            }

            throw new \Exception('Failed to retrieve BillCode');

        } catch (\Exception $e) {
            \Log::error('ToyyibPay Payment Request Error: ' . $e->getMessage());
        }

        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => '',
            'status' => 'error'
        ];

        return redirect()->back()->with(['toast' => $toastData])->withInput();
    }

    public function makeAmountByCurrency($amount, $currency)
    {
        if (strtoupper($currency) === 'MYR') {
            return $amount * 100; // Convert RM to cents
        }

        return parent::makeAmountByCurrency($amount, $currency);
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Toyyibpay?status=$status");
    }

    public function verify(Request $request)
    {
        try {
            $user = auth()->user();
            $orderId = $request->get('order_id');

            $order = Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!empty($order)) {

                $requestData = [
                    'billCode' => $request->get('billcode'),
                    'billpaymentStatus' => '1'
                ];

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_URL, 'https://toyyibpay.com/index.php/api/getBillTransactions');
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $requestData);

                $result = curl_exec($curl);
                curl_close($curl);
                $obj = json_decode($result, true);

                $orderStatus = Order::$fail;

                if (!empty($obj) && $obj['billStatus'] == "1") {
                    $orderStatus = Order::$paying;
                }

                $order->update([
                    'status' => $orderStatus,
                ]);

                return $order;
            }
        } catch (\Exception $e) {
            \Log::error('ToyyibPay Verification Error: ' . $e->getMessage());
        }

        return null;
    }
}
