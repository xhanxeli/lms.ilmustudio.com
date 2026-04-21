<?php

namespace App\Models;

use App\Mixins\Cashback\CashbackRules;
use App\Models\Observers\AccountingNumberObserver;
use App\User;
use Illuminate\Database\Eloquent\Model;

class Accounting extends Model
{
    protected $table = "accounting";

    public static $addiction = 'addiction';
    public static $deduction = 'deduction';

    public static $asset = 'asset';
    public static $income = 'income';
    public static $subscribe = 'subscribe';
    public static $promotion = 'promotion';
    public static $storeManual = 'manual';
    public static $storeAutomatic = 'automatic';
    public static $registrationPackage = 'registration_package';
    public static $installmentPayment = 'installment_payment';

    public $timestamps = false;

    protected $guarded = ['id'];


    protected static function boot()
    {
        parent::boot();

        Accounting::observe(AccountingNumberObserver::class);
        
        // Prevent creating incorrect affiliate commission refund entries
        static::creating(function ($accounting) {
            // CRITICAL: Prevent creating addiction-type refund entries for affiliate commissions
            // This was the bug that caused commissions to increase instead of decrease
            if ($accounting->is_affiliate_commission && 
                $accounting->type_account === self::$income &&
                stripos($accounting->description, trans('public.refund_affiliate_commission')) !== false) {
                
                if ($accounting->type === self::$addiction) {
                    \Log::error('BLOCKED: Attempted to create incorrect addiction-type affiliate commission refund', [
                        'user_id' => $accounting->user_id,
                        'order_item_id' => $accounting->order_item_id,
                        'amount' => $accounting->amount,
                        'type' => $accounting->type,
                    ]);
                    throw new \Exception('CRITICAL: Cannot create addiction-type refund entry for affiliate commission. Refund entries must be deduction type.');
                }
                
                // Ensure refund entries are always deduction type
                if ($accounting->type !== self::$deduction) {
                    \Log::warning('Correcting refund entry type to deduction', [
                        'user_id' => $accounting->user_id,
                        'order_item_id' => $accounting->order_item_id,
                        'original_type' => $accounting->type,
                    ]);
                    $accounting->type = self::$deduction;
                }
            }
        });
    }


    public function webinar()
    {
        return $this->belongsTo('App\Models\Webinar', 'webinar_id', 'id');
    }

    public function bundle()
    {
        return $this->belongsTo('App\Models\Bundle', 'bundle_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo('App\User', 'user_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo('App\User', 'creator_id', 'id');
    }

    public function promotion()
    {
        return $this->belongsTo('App\Models\Promotion', 'promotion_id', 'id');
    }

    public function registrationPackage()
    {
        return $this->belongsTo('App\Models\RegistrationPackage', 'registration_package_id', 'id');
    }

    public function subscribe()
    {
        return $this->belongsTo('App\Models\Subscribe', 'subscribe_id', 'id');
    }

    public function meetingTime()
    {
        return $this->belongsTo('App\Models\MeetingTime', 'meeting_time_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product', 'product_id', 'id');
    }

    public function orderItem()
    {
        return $this->belongsTo('App\Models\OrderItem', 'order_item_id', 'id');
    }

    public function installmentOrderPayment()
    {
        return $this->belongsTo('App\Models\InstallmentOrderPayment', 'installment_payment_id', 'id');
    }

    public function gift()
    {
        return $this->belongsTo(Gift::class, 'gift_id', 'id');
    }

    public static function createAccounting($orderItem, $type = null)
    {
        self::createAccountingBuyer($orderItem, $type);

        if ($orderItem->tax_price and $orderItem->tax_price > 0) {
            self::createAccountingTax($orderItem);
        }

        self::createAccountingSeller($orderItem);

        if ($orderItem->commission_price) {
            self::createAccountingCommission($orderItem);
        }
    }

    public static function createAccountingBuyer($orderItem, $type = null)
    {
        if ($type !== 'credit') {
            Accounting::create([
                'user_id' => $orderItem->user_id,
                'order_item_id' => $orderItem->id,
                'amount' => $orderItem->total_amount,
                'webinar_id' => !empty($orderItem->webinar_id) ? $orderItem->webinar_id : null,
                'bundle_id' => !empty($orderItem->bundle_id) ? $orderItem->bundle_id : null,
                'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
                'subscribe_id' => $orderItem->subscribe_id ?? null,
                'promotion_id' => $orderItem->promotion_id ?? null,
                'registration_package_id' => $orderItem->registration_package_id ?? null,
                'installment_payment_id' => $orderItem->installment_payment_id ?? null,
                'product_id' => $orderItem->product_id ?? null,
                'gift_id' => $orderItem->gift_id ?? null,
                'type' => Accounting::$addiction,
                'type_account' => Accounting::$asset,
                'description' => trans('public.paid_for_sale'),
                'created_at' => time()
            ]);
        }

        $deductionDescription = trans('public.paid_form_online_payment');
        if (!empty($orderItem->reserveMeeting)) {
            $time = $orderItem->reserveMeeting->meetingTime->time;
            $explodeTime = explode('-', $time);
            $minute = (strtotime($explodeTime[1]) - strtotime($explodeTime[0])) / 60;

            $deductionDescription = trans('meeting.paid_for_x_hour', ['hours' => convertMinutesToHourAndMinute($minute)]);
        } elseif ($type == 'credit') {
            $deductionDescription = trans('public.paid_form_credit');
        }

        $accountingType = Accounting::$deduction;

        Accounting::create([
            'user_id' => $orderItem->user_id,
            'order_item_id' => $orderItem->id,
            'amount' => $orderItem->total_amount,
            'webinar_id' => !empty($orderItem->webinar_id) ? $orderItem->webinar_id : null,
            'bundle_id' => !empty($orderItem->bundle_id) ? $orderItem->bundle_id : null,
            'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
            'subscribe_id' => $orderItem->subscribe_id ?? null,
            'promotion_id' => $orderItem->promotion_id ?? null,
            'registration_package_id' => $orderItem->registration_package_id ?? null,
            'installment_payment_id' => $orderItem->installment_payment_id ?? null,
            'product_id' => $orderItem->product_id ?? null,
            'gift_id' => $orderItem->gift_id ?? null,
            'type' => $accountingType,
            'type_account' => Accounting::$asset,
            'description' => $deductionDescription,
            'created_at' => time()
        ]);

        $notifyOptions = [
            '[f.d.type]' => trans("update.{$accountingType}"),
            '[amount]' => handlePrice($orderItem->total_amount, true, true, false, $orderItem->user),
        ];

        if (!empty($orderItem->webinar_id)) {
            $notifyOptions['[c.title]'] = $orderItem->webinar->title;
        } elseif (!empty($orderItem->bundle_id)) {
            $notifyOptions['[c.title]'] = $orderItem->bundle->title;
        } elseif (!empty($orderItem->reserve_meeting_id)) {
            $notifyOptions['[c.title]'] = trans('meeting.reservation_appointment');
        } elseif (!empty($orderItem->product_id)) {
            $notifyOptions['[c.title]'] = $orderItem->product->title;
        } elseif (!empty($orderItem->installment_payment_id)) {
            $notifyOptions['[c.title]'] = ($orderItem->installmentPayment->type == 'upfront') ? trans('update.installment_upfront') : trans('update.installment');
        } else if (!empty($orderItem->subscribe_id)) {
            $notifyOptions['[c.title]'] = $orderItem->subscribe->title . ' ' . trans('financial.subscribe');
        } else if (!empty($orderItem->promotion_id)) {
            $notifyOptions['[c.title]'] = $orderItem->promotion->title . ' ' . trans('panel.promotion');
        } else if (!empty($orderItem->registration_package_id)) {
            $notifyOptions['[c.title]'] = $orderItem->registrationPackage->title . ' ' . trans('update.registration_package');
        }

        if (!empty($orderItem->gift_id) and !empty($orderItem->gift)) {
            $notifyOptions['[c.title]'] .= ' (' . trans('update.a_gift_for_name_on_date_without_bold', ['name' => $orderItem->gift->name, 'date' => dateTimeFormat($orderItem->gift->date, 'j M Y H:i')]) . ')';
        }

        sendNotification('new_financial_document', $notifyOptions, $orderItem->user_id);
    }

    public static function createAccountingTax($orderItem)
    {
        Accounting::create([
            'user_id' => $orderItem->user_id,
            'order_item_id' => $orderItem->id,
            'tax' => true,
            'amount' => $orderItem->tax_price,
            'webinar_id' => $orderItem->webinar_id,
            'bundle_id' => $orderItem->bundle_id,
            'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
            'subscribe_id' => $orderItem->subscribe_id ?? null,
            'promotion_id' => $orderItem->promotion_id ?? null,
            'registration_package_id' => $orderItem->registration_package_id ?? null,
            'installment_payment_id' => $orderItem->installment_payment_id ?? null,
            'product_id' => $orderItem->product_id ?? null,
            'gift_id' => $orderItem->gift_id ?? null,
            'type_account' => Accounting::$asset,
            'type' => Accounting::$addiction,
            'description' => trans('public.tax_get_form_buyer'),
            'created_at' => time()
        ]);

        return true;
    }

    public static function createAccountingSeller($orderItem)
    {
        if (!empty($orderItem->bundle_id)) {
            self::createAccountingForBundle($orderItem);
        } else {
            $sellerId = OrderItem::getSeller($orderItem);

            Accounting::create([
                'user_id' => $sellerId,
                'order_item_id' => $orderItem->id,
                'installment_order_id' => $orderItem->installment_order_id ?? null,
                'amount' => $orderItem->total_amount - $orderItem->tax_price - $orderItem->commission_price,
                'webinar_id' => $orderItem->webinar_id,
                'bundle_id' => $orderItem->bundle_id,
                'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
                'subscribe_id' => $orderItem->subscribe_id ?? null,
                'promotion_id' => $orderItem->promotion_id ?? null,
                'product_id' => $orderItem->product_id ?? null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$addiction,
                'description' => trans('public.income_sale'),
                'created_at' => time()
            ]);
        }

        return true;
    }

    private static function createAccountingForBundle($orderItem)
    {
        $bundle = $orderItem->bundle;
        $bundleWebinars = $bundle->bundleWebinars;

        $orderAmount = $orderItem->total_amount - $orderItem->tax_price - $orderItem->commission_price;
        $sellersWithPrices = [];

        foreach ($bundleWebinars as $bundleWebinar) {
            $webinar = $bundleWebinar->webinar;

            if (empty($sellersWithPrices[$webinar->creator_id])) {
                $sellersWithPrices[$webinar->creator_id] = 0;
            }

            $sellersWithPrices[$webinar->creator_id] += $webinar->price;
        }

        if (count($sellersWithPrices)) {
            $sumPrices = array_sum($sellersWithPrices);
            $insert = [];

            foreach ($sellersWithPrices as $sellerId => $price) {
                if ($price > 0) {
                    $percent = ($price / $sumPrices) * 100;
                    $incomePrice = ($orderAmount * $percent) / 100;

                    $insert[] = [
                        'user_id' => $sellerId,
                        'order_item_id' => $orderItem->id,
                        'installment_order_id' => $orderItem->installment_order_id ?? null,
                        'amount' => $incomePrice,
                        'webinar_id' => $orderItem->webinar_id,
                        'bundle_id' => $orderItem->bundle_id,
                        'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
                        'subscribe_id' => $orderItem->subscribe_id ?? null,
                        'promotion_id' => $orderItem->promotion_id ?? null,
                        'product_id' => $orderItem->product_id ?? null,
                        'type_account' => Accounting::$income,
                        'type' => Accounting::$addiction,
                        'description' => trans('public.income_sale'),
                        'created_at' => time()
                    ];
                }
            }

            if (count($insert)) {
                Accounting::insert($insert);
            }
        }
    }

    public static function createAccountingSystemForSubscribe($orderItem)
    {
        $sellerId = OrderItem::getSeller($orderItem);

        Accounting::create([
            'user_id' => $sellerId,
            'order_item_id' => $orderItem->id,
            'system' => true,
            'amount' => $orderItem->total_amount - $orderItem->tax_price,
            'subscribe_id' => $orderItem->subscribe_id,
            'type_account' => Accounting::$subscribe,
            'type' => Accounting::$addiction,
            'description' => trans('public.income_for_subscribe'),
            'created_at' => time()
        ]);

        return true;
    }

    public static function createAccountingCommission($orderItem)
    {
        $authId = $orderItem->user_id;
        $sellerId = OrderItem::getSeller($orderItem);

        $commission = $orderItem->commission;
        $commissionPrice = $orderItem->commission_price;
        $affiliateCommissionPrice = 0;


        $referralSettings = getReferralSettings();
        $affiliateStatus = (!empty($referralSettings) and !empty($referralSettings['status']));
        $affiliateUser = null;

        if ($affiliateStatus) {
            $affiliate = Affiliate::where('referred_user_id', $authId)->first();

            if (!empty($affiliate)) {
                $affiliateUser = $affiliate->affiliateUser;

                if (!empty($affiliateUser) and $affiliateUser->affiliate) {

                    if (!empty($affiliate)) {
                        if (!empty($orderItem->product_id) and !empty($referralSettings['store_affiliate_user_commission']) and $referralSettings['store_affiliate_user_commission'] > 0) {
                            $affiliateCommission = $referralSettings['store_affiliate_user_commission'];

                            if ($commission > 0) {
                                $affiliateCommissionPrice = ($affiliateCommission * $commissionPrice) / $commission;
                                $commissionPrice = $commissionPrice - $affiliateCommissionPrice;
                            }
                        } elseif (empty($orderItem->product_id) and !empty($referralSettings['affiliate_user_commission']) and $referralSettings['affiliate_user_commission'] > 0) {
                            $affiliateCommission = $referralSettings['affiliate_user_commission'];

                            if ($commission > 0) {
                                $affiliateCommissionPrice = ($affiliateCommission * $commissionPrice) / $commission;
                                $commissionPrice = $commissionPrice - $affiliateCommissionPrice;
                            }
                        }
                    }
                }
            }
        }

        Accounting::create([
            'user_id' => !empty($sellerId) ? $sellerId : 1,
            'order_item_id' => $orderItem->id,
            'system' => true,
            'amount' => $commissionPrice,
            'webinar_id' => $orderItem->webinar_id ?? null,
            'bundle_id' => $orderItem->bundle_id ?? null,
            'product_id' => $orderItem->product_id ?? null,
            'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
            'subscribe_id' => $orderItem->subscribe_id ?? null,
            'promotion_id' => $orderItem->promotion_id ?? null,
            'type_account' => Accounting::$income,
            'type' => Accounting::$addiction,
            'description' => trans('public.get_commission_from_seller'),
            'created_at' => time()
        ]);

        if (!empty($affiliateUser) and $affiliateCommissionPrice > 0) {
            Accounting::create([
                'user_id' => $affiliateUser->id,
                'order_item_id' => $orderItem->id,
                'system' => false,
                'referred_user_id' => $authId,
                'is_affiliate_commission' => true,
                'amount' => $affiliateCommissionPrice,
                'webinar_id' => $orderItem->webinar_id ?? null,
                'bundle_id' => $orderItem->bundle_id ?? null,
                'product_id' => $orderItem->product_id ?? null,
                'meeting_time_id' => $orderItem->reserveMeeting ? $orderItem->reserveMeeting->meeting_time_id : null,
                'subscribe_id' => $orderItem->subscribe_id ?? null, // FIXED: Use actual subscribe_id instead of null
                'promotion_id' => $orderItem->promotion_id ?? null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$addiction,
                'description' => trans('public.get_commission_from_referral'),
                'created_at' => time()
            ]);
        }

        return true;
    }

    /**
     * Create affiliate commission for subscription plan purchase
     * @param OrderItem $orderItem
     * @return bool
     */
    public static function createAffiliateCommissionForSubscribe($orderItem)
    {
        $authId = $orderItem->user_id;
        $referralSettings = getReferralSettings();
        $affiliateStatus = (!empty($referralSettings) and !empty($referralSettings['status']));
        $affiliateUser = null;
        $affiliateCommissionPrice = 0;

        if ($affiliateStatus) {
            $affiliate = Affiliate::where('referred_user_id', $authId)->first();

            if (!empty($affiliate)) {
                $affiliateUser = $affiliate->affiliateUser;

                if (!empty($affiliateUser) and $affiliateUser->affiliate) {
                    // For subscription plans, use affiliate_user_commission (not store_affiliate_user_commission)
                    if (!empty($referralSettings['affiliate_user_commission']) and $referralSettings['affiliate_user_commission'] > 0) {
                        $affiliateCommission = $referralSettings['affiliate_user_commission'];
                        
                        // Calculate commission from the subscription purchase amount (after discount, before tax)
                        $purchaseAmount = $orderItem->amount; // Amount before tax
                        $affiliateCommissionPrice = ($affiliateCommission * $purchaseAmount) / 100;
                    }
                }
            }
        }

        if (!empty($affiliateUser) and $affiliateCommissionPrice > 0) {
            Accounting::create([
                'user_id' => $affiliateUser->id,
                'order_item_id' => $orderItem->id,
                'system' => false,
                'referred_user_id' => $authId,
                'is_affiliate_commission' => true,
                'amount' => $affiliateCommissionPrice,
                'subscribe_id' => $orderItem->subscribe_id,
                'webinar_id' => null,
                'bundle_id' => null,
                'product_id' => null,
                'meeting_time_id' => null,
                'promotion_id' => null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$addiction,
                'description' => trans('public.get_commission_from_referral'),
                'created_at' => $orderItem->created_at ?? time()
            ]);
        }

        return true;
    }

    public static function createAffiliateUserAmountAccounting($userId, $referredUserId, $amount)
    {
        if ($amount) {
            Accounting::create([
                'user_id' => $userId,
                'referred_user_id' => $referredUserId,
                'is_affiliate_amount' => true,
                'system' => false,
                'amount' => $amount,
                'webinar_id' => null,
                'bundle_id' => null,
                'meeting_time_id' => null,
                'subscribe_id' => null,
                'promotion_id' => null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$addiction,
                'description' => trans('public.get_amount_from_referral'),
                'created_at' => time()
            ]);

            Accounting::create([
                'user_id' => $userId,
                'referred_user_id' => $referredUserId,
                'is_affiliate_amount' => true,
                'system' => true,
                'amount' => $amount,
                'webinar_id' => null,
                'bundle_id' => null,
                'meeting_time_id' => null,
                'subscribe_id' => null,
                'promotion_id' => null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$deduction,
                'description' => trans('public.get_amount_from_referral'),
                'created_at' => time()
            ]);
        }
    }


    public static function refundAccounting($sale, $productOrderId = null)
    {
        self::refundAccountingBuyer($sale);

        if ($sale->tax) {
            self::refundAccountingTax($sale);
        }

        self::refundAccountingSeller($sale);

        if ($sale->commission) {
            self::refundAccountingCommission($sale);
        }

        // Refund affiliate commissions if any
        self::refundAffiliateCommission($sale);
    }

    public static function refundAccountingBuyer($sale)
    {
        Accounting::create([
            'user_id' => $sale->buyer_id,
            'amount' => $sale->total_amount,
            'webinar_id' => $sale->webinar_id,
            'bundle_id' => $sale->bundle_id,
            'meeting_time_id' => $sale->meeting_time_id,
            'subscribe_id' => $sale->subscribe_id ?? null,
            'promotion_id' => $sale->promotion_id ?? null,
            'product_id' => !empty($sale->productOrder) ? $sale->productOrder->product_id : null,
            'type' => Accounting::$addiction,
            'type_account' => Accounting::$asset,
            'description' => trans('public.refund_money_to_buyer'),
            'created_at' => time()
        ]);

        return true;
    }

    public static function refundAccountingTax($sale)
    {
        if (!empty($sale->tax) and $sale->tax > 0) {
            Accounting::create([
                'tax' => true,
                'amount' => $sale->tax,
                'webinar_id' => $sale->webinar_id,
                'bundle_id' => $sale->bundle_id,
                'meeting_time_id' => $sale->meeting_time_id,
                'subscribe_id' => $sale->subscribe_id ?? null,
                'promotion_id' => $sale->promotion_id ?? null,
                'product_id' => !empty($sale->productOrder) ? $sale->productOrder->product_id : null,
                'type_account' => Accounting::$asset,
                'type' => Accounting::$deduction,
                'description' => trans('public.refund_tax'),
                'created_at' => time()
            ]);
        }

        return true;
    }

    public static function refundAccountingCommission($sale)
    {
        if (!empty($sale->commission) and $sale->commission > 0) {
            Accounting::create([
                'system' => true,
                'user_id' => $sale->seller_id,
                'amount' => $sale->commission,
                'webinar_id' => $sale->webinar_id,
                'bundle_id' => $sale->bundle_id,
                'meeting_time_id' => $sale->meeting_time_id,
                'subscribe_id' => $sale->subscribe_id ?? null,
                'promotion_id' => $sale->promotion_id ?? null,
                'product_id' => !empty($sale->productOrder) ? $sale->productOrder->product_id : null,
                'type_account' => Accounting::$income,
                'type' => Accounting::$deduction,
                'description' => trans('public.refund_commission'),
                'created_at' => time()
            ]);
        }

        return true;
    }

    public static function refundAccountingSeller($sale)
    {
        $amount = $sale->total_amount;

        if (!empty($sale->tax) and $sale->tax > 0) {
            $amount = $amount - $sale->tax;
        }

        if (!empty($sale->commission) and $sale->commission > 0) {
            $amount = $amount - $sale->commission;
        }

        Accounting::create([
            'user_id' => $sale->seller_id,
            'amount' => $amount,
            'webinar_id' => $sale->webinar_id,
            'bundle_id' => $sale->bundle_id,
            'meeting_time_id' => $sale->meeting_time_id,
            'subscribe_id' => $sale->subscribe_id ?? null,
            'promotion_id' => $sale->promotion_id ?? null,
            'product_id' => !empty($sale->productOrder) ? $sale->productOrder->product_id : null,
            'type_account' => Accounting::$income,
            'type' => Accounting::$deduction,
            'description' => trans('public.refund_income'),
            'created_at' => time()
        ]);

        return true;
    }

    /**
     * Refund affiliate commission when a sale is refunded
     * @param Sale $sale
     * @return bool
     */
    public static function refundAffiliateCommission($sale)
    {
        \Log::info('Refunding affiliate commission for sale', [
            'sale_id' => $sale->id,
            'order_id' => $sale->order_id,
            'webinar_id' => $sale->webinar_id,
            'bundle_id' => $sale->bundle_id,
            'subscribe_id' => $sale->subscribe_id,
            'product_order_id' => $sale->product_order_id,
        ]);

        $orderItemIds = [];
        
        // Method 1: Find order items that match this sale
        if (!empty($sale->order_id)) {
            $query = \App\Models\OrderItem::where('order_id', $sale->order_id);

            // Match based on sale type - a sale can only have one type of item
            if (!empty($sale->webinar_id)) {
                $query->where('webinar_id', $sale->webinar_id);
            } elseif (!empty($sale->bundle_id)) {
                $query->where('bundle_id', $sale->bundle_id);
            } elseif (!empty($sale->subscribe_id)) {
                $query->where('subscribe_id', $sale->subscribe_id);
            } elseif (!empty($sale->promotion_id)) {
                $query->where('promotion_id', $sale->promotion_id);
            } elseif (!empty($sale->registration_package_id)) {
                $query->where('registration_package_id', $sale->registration_package_id);
            } elseif (!empty($sale->product_order_id)) {
                $query->where('product_order_id', $sale->product_order_id);
            } elseif (!empty($sale->meeting_time_id)) {
                $query->whereHas('reserveMeeting', function($q) use ($sale) {
                    $q->where('meeting_time_id', $sale->meeting_time_id);
                });
            }

            $orderItems = $query->get();
            $orderItemIds = $orderItems->pluck('id')->toArray();
            
            \Log::info('Found order items via order_id', [
                'sale_id' => $sale->id,
                'order_item_count' => count($orderItemIds),
                'order_item_ids' => $orderItemIds,
            ]);
        }

        // Method 2: Fallback - Find affiliate commissions directly by sale attributes if order items not found
        $affiliateCommissions = collect();
        
        if (!empty($orderItemIds)) {
            // Find all affiliate commission accounting entries for these order items
            $affiliateCommissions = Accounting::whereIn('order_item_id', $orderItemIds)
                ->where('is_affiliate_commission', true)
                ->where('type', Accounting::$addiction)
                ->where('type_account', Accounting::$income)
                ->get();
        }
        
        // Fallback: If no commissions found via order items, try to find by sale attributes directly
        if ($affiliateCommissions->isEmpty()) {
            \Log::warning('No affiliate commissions found via order items, trying fallback method', [
                'sale_id' => $sale->id,
                'order_id' => $sale->order_id,
            ]);
            
            $fallbackQuery = Accounting::where('is_affiliate_commission', true)
                ->where('type', Accounting::$addiction)
                ->where('type_account', Accounting::$income);
            
            // Try to match by sale attributes
            if (!empty($sale->webinar_id)) {
                $fallbackQuery->where('webinar_id', $sale->webinar_id);
            } elseif (!empty($sale->bundle_id)) {
                $fallbackQuery->where('bundle_id', $sale->bundle_id);
            } elseif (!empty($sale->subscribe_id)) {
                $fallbackQuery->where('subscribe_id', $sale->subscribe_id);
            } elseif (!empty($sale->promotion_id)) {
                $fallbackQuery->where('promotion_id', $sale->promotion_id);
            } elseif (!empty($sale->product_order_id)) {
                // For products, we need to find via product_id from the sale
                if (!empty($sale->productOrder)) {
                    $fallbackQuery->where('product_id', $sale->productOrder->product_id);
                }
            }
            
            // Also match by order_id if available
            if (!empty($sale->order_id) && !empty($orderItemIds)) {
                $fallbackQuery->whereIn('order_item_id', $orderItemIds);
            }
            
            $affiliateCommissions = $fallbackQuery->get();
            
            \Log::info('Fallback query results', [
                'sale_id' => $sale->id,
                'commissions_found' => $affiliateCommissions->count(),
            ]);
        }

        if ($affiliateCommissions->isEmpty()) {
            \Log::info('No affiliate commissions found to refund', [
                'sale_id' => $sale->id,
                'order_id' => $sale->order_id,
            ]);
            return true;
        }

        \Log::info('Found affiliate commissions to refund', [
            'sale_id' => $sale->id,
            'commission_count' => $affiliateCommissions->count(),
            'commission_ids' => $affiliateCommissions->pluck('id')->toArray(),
        ]);

        // Reverse each affiliate commission
        // Use database transaction to prevent race conditions and ensure atomicity
        foreach ($affiliateCommissions as $affiliateCommission) {
            try {
                // Use database transaction with locking to prevent concurrent refunds
                \DB::transaction(function () use ($sale, $affiliateCommission) {
                    // Check if this commission has already been refunded
                    // Check for both deduction (correct) and addiction (old bug) entries to prevent duplicates
                    // Use lockForUpdate to prevent race conditions
                    $existingRefund = Accounting::where('order_item_id', $affiliateCommission->order_item_id)
                        ->where('is_affiliate_commission', true)
                        ->where('type_account', Accounting::$income)
                        ->where('description', 'like', '%' . trans('public.refund_affiliate_commission') . '%')
                        ->where('user_id', $affiliateCommission->user_id)
                        ->where('amount', $affiliateCommission->amount)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($existingRefund) {
                        // Check if it's an incorrect addiction entry (old bug) - remove it
                        if ($existingRefund->type == Accounting::$addiction) {
                            \Log::warning('Found incorrect addiction refund entry, removing it', [
                                'sale_id' => $sale->id,
                                'entry_id' => $existingRefund->id,
                                'commission_id' => $affiliateCommission->id,
                                'user_id' => $affiliateCommission->user_id,
                                'amount' => $affiliateCommission->amount,
                            ]);
                            $existingRefund->delete();
                            // Continue to create correct deduction entry below
                        } else {
                            // Correct deduction entry already exists
                            \Log::warning('Affiliate commission already refunded (correct entry exists), skipping', [
                                'sale_id' => $sale->id,
                                'entry_id' => $existingRefund->id,
                                'commission_id' => $affiliateCommission->id,
                                'user_id' => $affiliateCommission->user_id,
                                'amount' => $affiliateCommission->amount,
                            ]);
                            return; // Skip creating duplicate
                        }
                    }
                    
                    \Log::info('Refunding affiliate commission', [
                        'sale_id' => $sale->id,
                        'commission_id' => $affiliateCommission->id,
                        'affiliate_user_id' => $affiliateCommission->user_id,
                        'amount' => $affiliateCommission->amount,
                        'order_item_id' => $affiliateCommission->order_item_id,
                    ]);
                    
                    // CRITICAL: Only create DEDUCTION entry, never addiction
                    // The old bug created addiction entries which added money back instead of deducting
                    $refundEntry = Accounting::create([
                        'user_id' => $affiliateCommission->user_id,
                        'order_item_id' => $affiliateCommission->order_item_id,
                        'system' => false,
                        'referred_user_id' => $affiliateCommission->referred_user_id,
                        'is_affiliate_commission' => true,
                        'amount' => $affiliateCommission->amount,
                        'webinar_id' => $affiliateCommission->webinar_id,
                        'bundle_id' => $affiliateCommission->bundle_id,
                        'product_id' => $affiliateCommission->product_id,
                        'meeting_time_id' => $affiliateCommission->meeting_time_id,
                        'subscribe_id' => $affiliateCommission->subscribe_id,
                        'promotion_id' => $affiliateCommission->promotion_id,
                        'type_account' => Accounting::$income,
                        'type' => Accounting::$deduction, // MUST be deduction, never addiction
                        'description' => trans('public.refund_affiliate_commission'),
                        'created_at' => time()
                    ]);
                    
                    // Validate the entry was created correctly
                    if ($refundEntry->type !== Accounting::$deduction) {
                        throw new \Exception('CRITICAL ERROR: Refund entry created with wrong type! Expected deduction, got: ' . $refundEntry->type);
                    }
                    
                    \Log::info('Affiliate commission refunded successfully', [
                        'sale_id' => $sale->id,
                        'refund_entry_id' => $refundEntry->id,
                        'commission_id' => $affiliateCommission->id,
                        'affiliate_user_id' => $affiliateCommission->user_id,
                        'amount' => $affiliateCommission->amount,
                    ]);
                });
            } catch (\Exception $e) {
                \Log::error('Error refunding affiliate commission', [
                    'sale_id' => $sale->id,
                    'commission_id' => $affiliateCommission->id,
                    'user_id' => $affiliateCommission->user_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue processing other commissions even if one fails
                continue;
            }
        }

        return true;
    }

    public static function charge($order)
    {
        \Log::info('Accounting::charge called', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'order_total_amount' => $order->total_amount,
            'amount_to_credit' => $order->total_amount,
        ]);
        
        // Check if accounting record already exists for this order to prevent duplicates
        // Look for records created within the last 5 minutes with the same amount (to handle race conditions and duplicate callbacks)
        $currentTime = time();
        $existingAccounting = Accounting::where('user_id', $order->user_id)
            ->where('type', Order::$addiction)
            ->where('type_account', Order::$asset)
            ->where('description', 'like', '%' . trans('public.charge_account') . '%')
            ->where('amount', $order->total_amount)
            ->whereBetween('created_at', [$currentTime - 300, $currentTime + 10])
            ->first();
        
        if ($existingAccounting) {
            \Log::warning('Accounting record already exists for this order, skipping duplicate creation', [
                'order_id' => $order->id,
                'existing_accounting_id' => $existingAccounting->id,
                'existing_created_at' => date('Y-m-d H:i:s', $existingAccounting->created_at),
                'current_time' => date('Y-m-d H:i:s', $currentTime),
                'time_diff_seconds' => $currentTime - $existingAccounting->created_at,
            ]);
            return true; // Return true to indicate "already processed"
        }
        
        $accounting = Accounting::create([
            'user_id' => $order->user_id,
            'amount' => $order->total_amount,
            'type_account' => Order::$asset,
            'type' => Order::$addiction,
            'description' => trans('public.charge_account'),
            'created_at' => time()
        ]);
        
        \Log::info('Accounting record created for charge account', [
            'accounting_id' => $accounting->id,
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'credited_amount' => $accounting->amount,
            'order_total_amount' => $order->total_amount,
        ]);

        $accountChargeReward = RewardAccounting::calculateScore(Reward::ACCOUNT_CHARGE, $order->total_amount);
        RewardAccounting::makeRewardAccounting($order->user_id, $accountChargeReward, Reward::ACCOUNT_CHARGE);

        $chargeWalletReward = RewardAccounting::calculateScore(Reward::CHARGE_WALLET, $order->total_amount);
        RewardAccounting::makeRewardAccounting($order->user_id, $chargeWalletReward, Reward::CHARGE_WALLET);

        $notifyOptions = [
            '[u.name]' => $order->user->full_name,
            '[amount]' => handlePrice($order->total_amount),
        ];
        sendNotification('user_wallet_charge', $notifyOptions, 1);

        return true;
    }


    public static function createAccountingForSubscribe($orderItem, $type = null)
    {
        self::createAccountingBuyer($orderItem, $type);
        if ($orderItem->tax_price and $orderItem->tax_price > 0) {
            self::createAccountingTax($orderItem);
        }

        self::createAccountingSystemForSubscribe($orderItem);

        // Create affiliate commission for subscription plan purchase
        self::createAffiliateCommissionForSubscribe($orderItem);

        $notifyOptions = [
            '[u.name]' => $orderItem->user->full_name,
            '[s.p.name]' => $orderItem->subscribe->title,
        ];

        sendNotification('new_subscribe_plan', $notifyOptions, $orderItem->user_id);
    }

    public static function createAccountingForPromotion($orderItem, $type = null)
    {
        self::createAccountingBuyer($orderItem, $type);

        if ($orderItem->tax_price and $orderItem->tax_price > 0) {
            self::createAccountingTax($orderItem);
        }

        self::createAccountingSystemForPromotion($orderItem);

        $notifyOptions = [
            '[c.title]' => $orderItem->webinar->title,
            '[p.p.name]' => $orderItem->promotion->title,
        ];

        sendNotification('promotion_plan', $notifyOptions, $orderItem->user_id);
    }


    public static function createAccountingSystemForPromotion($orderItem)
    {
        Accounting::create([
            'user_id' => $orderItem->webinar_id ? $orderItem->webinar->creator_id : (!empty($orderItem->reserve_meeting_id) ? $orderItem->reserveMeeting->meeting->creator_id : 1),
            'order_item_id' => $orderItem->id,
            'system' => true,
            'amount' => $orderItem->total_amount - $orderItem->tax_price,
            'promotion_id' => $orderItem->promotion_id,
            'type_account' => Accounting::$promotion,
            'type' => Accounting::$addiction,
            'description' => trans('public.income_for_promotion'),
            'created_at' => time()
        ]);
    }

    public static function createAccountingForRegistrationPackage($orderItem, $type = null)
    {
        self::createAccountingBuyer($orderItem, $type);

        if ($orderItem->tax_price and $orderItem->tax_price > 0) {
            self::createAccountingTax($orderItem);
        }

        self::createAccountingSystemForRegistrationPackage($orderItem);

        $registrationPackage = $orderItem->registrationPackage;
        $registrationPackageExpire = time() + ($registrationPackage->days * 24 * 60 * 60);

        $notifyOptions = [
            '[u.name]' => $orderItem->user->full_name,
            '[item_title]' => $registrationPackage->title,
            '[amount]' => handlePrice($orderItem->total_amount),
            '[time.date]' => dateTimeFormat($registrationPackageExpire, 'j M Y')
        ];
        sendNotification("registration_package_activated", $notifyOptions, $orderItem->user_id);
        sendNotification("registration_package_activated_for_admin", $notifyOptions, 1);
    }

    public static function createAccountingSystemForRegistrationPackage($orderItem)
    {
        Accounting::create([
            'user_id' => 1,
            'order_item_id' => $orderItem->id,
            'system' => true,
            'amount' => $orderItem->total_amount - $orderItem->tax_price,
            'registration_package_id' => $orderItem->registration_package_id,
            'type_account' => Accounting::$registrationPackage,
            'type' => Accounting::$addiction,
            'description' => trans('update.paid_for_registration_package'),
            'created_at' => time()
        ]);
    }

    public static function createAccountingForSaleWithSubscribe($item, $subscribe, $itemName)
    {
        $admin = User::getMainAdmin();

        $commission = $item->creator->getCommission();

        // Prevent division by zero when usable_count is zero or null
        $pricePerSubscribe = ($subscribe->usable_count && $subscribe->usable_count > 0)
            ? ($subscribe->price / $subscribe->usable_count)
            : 0;
        $commissionPrice = $commission ? ($pricePerSubscribe * $commission / 100) : 0;

        $totalAmount = $pricePerSubscribe - $commissionPrice;

        Accounting::create([
            'user_id' => $item->creator_id,
            'amount' => $totalAmount,
            $itemName => $item->id,
            'type' => Accounting::$addiction,
            'type_account' => Accounting::$income,
            'description' => trans('public.paid_form_subscribe'),
            'created_at' => time()
        ]);

        Accounting::create([
            'system' => true,
            'user_id' => $admin->id,
            'amount' => $totalAmount,
            $itemName => $item->id,
            'type' => Accounting::$deduction,
            'type_account' => Accounting::$asset,
            'description' => trans('public.paid_form_subscribe'),
            'created_at' => time()
        ]);
    }

    public static function refundAccountingForSaleWithSubscribe($webinar, $subscribe)
    {
        $admin = User::getMainAdmin();

        $financialSettings = getFinancialSettings();
        $commission = $financialSettings['commission'] ?? 0;

        // Prevent division by zero when usable_count is zero or null
        $pricePerSubscribe = ($subscribe->usable_count && $subscribe->usable_count > 0)
            ? ($subscribe->price / $subscribe->usable_count)
            : 0;
        $commissionPrice = $commission ? $pricePerSubscribe * $commission / 100 : 0;

        $totalAmount = $pricePerSubscribe - $commissionPrice;

        Accounting::create([
            'user_id' => $webinar->creator_id,
            'amount' => $totalAmount,
            'webinar_id' => $webinar->id,
            'type' => Accounting::$deduction,
            'type_account' => Accounting::$income,
            'description' => trans('public.paid_form_subscribe'),
            'created_at' => time()
        ]);

        Accounting::create([
            'system' => true,
            'user_id' => $admin->id,
            'amount' => $totalAmount,
            'webinar_id' => $webinar->id,
            'type' => Accounting::$addiction,
            'type_account' => Accounting::$asset,
            'description' => trans('public.paid_form_subscribe'),
            'created_at' => time()
        ]);
    }


    public static function createAccountingForInstallmentPayment($orderItem, $type = null)
    {
        self::createAccountingBuyer($orderItem, $type);

        if ($orderItem->tax_price and $orderItem->tax_price > 0) {
            self::createAccountingTax($orderItem);
        }

        self::createAccountingSystemForInstallmentPayment($orderItem);
    }

    public static function createAccountingSystemForInstallmentPayment($orderItem)
    {
        Accounting::create([
            'user_id' => 1,
            'order_item_id' => $orderItem->id,
            'system' => true,
            'amount' => $orderItem->total_amount - $orderItem->tax_price,
            'installment_payment_id' => $orderItem->installment_payment_id,
            'type_account' => Accounting::$installmentPayment,
            'type' => Accounting::$addiction,
            'description' => ($orderItem->installmentPayment->type == 'upfront') ? trans('update.installment_upfront') : trans('update.installment'),
            'created_at' => time()
        ]);
    }


    public static function createRegistrationBonusUserAmountAccounting($userId, $amount, $typeAccount)
    {
        $check = Accounting::query()->where('user_id', $userId)
            ->where('is_registration_bonus', true)
            ->first();

        if (!empty($amount) and empty($check)) { //
            Accounting::updateOrCreate([
                'user_id' => $userId,
                'is_registration_bonus' => true,
                'system' => false,
                'type_account' => $typeAccount,
                'type' => Accounting::$addiction,
            ], [
                'amount' => $amount,
                'description' => trans('update.get_amount_from_registration_bonus'),
                'created_at' => time()
            ]);

            Accounting::updateOrCreate([
                'user_id' => $userId,
                'is_registration_bonus' => true,
                'system' => true,
                'type_account' => Accounting::$income,
                'type' => Accounting::$deduction,
            ], [
                'amount' => $amount,
                'description' => trans('update.get_amount_from_registration_bonus'),
                'created_at' => time()
            ]);

            $notifyOptions = [
                '[amount]' => handlePrice($amount),
            ];
            sendNotification("registration_bonus_achieved", $notifyOptions, $userId);
        }
    }
}
