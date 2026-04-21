<?php

namespace App\Models;

use App\Mixins\RegistrationBonus\RegistrationBonusAccounting;
use App\Models\Observers\SaleNumberObserver;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    public static $webinar = 'webinar';
    public static $meeting = 'meeting';
    public static $subscribe = 'subscribe';
    public static $promotion = 'promotion';
    public static $registrationPackage = 'registration_package';
    public static $product = 'product';
    public static $bundle = 'bundle';
    public static $gift = 'gift';
    public static $installmentPayment = 'installment_payment';

    public static $credit = 'credit';
    public static $paymentChannel = 'payment_channel';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @var bool */
    private $adminRenewalExtensionPartnerSaleFetched = false;

    /** @var static|null */
    private $adminRenewalExtensionPartnerSale;

    protected static function boot()
    {
        parent::boot();

        Sale::observe(SaleNumberObserver::class);
    }

    public function webinar()
    {
        return $this->belongsTo('App\Models\Webinar', 'webinar_id', 'id');
    }

    public function bundle()
    {
        return $this->belongsTo('App\Models\Bundle', 'bundle_id', 'id');
    }

    public function buyer()
    {
        return $this->belongsTo('App\User', 'buyer_id', 'id');
    }

    public function seller()
    {
        return $this->belongsTo('App\User', 'seller_id', 'id');
    }

    public function meeting()
    {
        return $this->belongsTo('App\Models\Meeting', 'meeting_id', 'id');
    }

    public function subscribe()
    {
        return $this->belongsTo('App\Models\Subscribe', 'subscribe_id', 'id');
    }

    public function promotion()
    {
        return $this->belongsTo('App\Models\Promotion', 'promotion_id', 'id');
    }

    public function registrationPackage()
    {
        return $this->belongsTo('App\Models\RegistrationPackage', 'registration_package_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo('App\Models\Order', 'order_id', 'id');
    }

    public function ticket()
    {
        return $this->belongsTo('App\Models\Ticket', 'ticket_id', 'id');
    }

    public function saleLog()
    {
        return $this->hasOne('App\Models\SaleLog', 'sale_id', 'id');
    }

    public function productOrder()
    {
        return $this->belongsTo('App\Models\ProductOrder', 'product_order_id', 'id');
    }

    public function gift()
    {
        return $this->belongsTo('App\Models\Gift', 'gift_id', 'id');
    }

    public function installmentOrderPayment()
    {
        return $this->belongsTo('App\Models\InstallmentOrderPayment', 'installment_payment_id', 'id');
    }

    /**
     * Subscription plan–only rows (no class / appointment) that count as successful revenue on the admin dashboard.
     * Includes renewal-extension sales that have refund_at set but match the same “success” rule as the sales list.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeWhereAdminDashboardSuccessfulSubscribePlan($query)
    {
        return $query->where('type', self::$subscribe)
            ->whereNull('webinar_id')
            ->whereNull('meeting_id')
            ->where(function ($q) {
                $q->whereNull('refund_at')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('refund_at')
                            ->whereExists(function ($sub) {
                                $sub->selectRaw('1')
                                    ->from('sales as s2')
                                    ->whereColumn('s2.buyer_id', 'sales.buyer_id')
                                    ->whereColumn('s2.subscribe_id', 'sales.subscribe_id')
                                    ->where('s2.type', self::$subscribe)
                                    ->whereNull('s2.refund_at')
                                    ->whereColumn('s2.id', '!=', 'sales.id')
                                    ->whereColumn('s2.created_at', '<', 'sales.created_at')
                                    ->whereNotNull('s2.custom_expiration_date')
                                    ->whereColumn('s2.custom_expiration_date', '>', 'sales.created_at');
                            });
                    });
            });
    }

    public static function createSales($orderItem, $payment_method)
    {
        $orderType = Order::$webinar;
        if (!empty($orderItem->reserve_meeting_id)) {
            $orderType = Order::$meeting;
        } elseif (!empty($orderItem->subscribe_id)) {
            $orderType = Order::$subscribe;
        } elseif (!empty($orderItem->promotion_id)) {
            $orderType = Order::$promotion;
        } elseif (!empty($orderItem->registration_package_id)) {
            $orderType = Order::$registrationPackage;
        } elseif (!empty($orderItem->product_id)) {
            $orderType = Order::$product;
        } elseif (!empty($orderItem->bundle_id)) {
            $orderType = Order::$bundle;
        } elseif (!empty($orderItem->installment_payment_id)) {
            $orderType = Order::$installmentPayment;
        }

        if (!empty($orderItem->gift_id)) {
            $orderType = Order::$gift;
        }

        $seller_id = OrderItem::getSeller($orderItem);

        $sale = Sale::create([
            'buyer_id' => $orderItem->user_id,
            'seller_id' => $seller_id,
            'order_id' => $orderItem->order_id,
            'webinar_id' => (empty($orderItem->gift_id) and !empty($orderItem->webinar_id)) ? $orderItem->webinar_id : null,
            'bundle_id' => (empty($orderItem->gift_id) and !empty($orderItem->bundle_id)) ? $orderItem->bundle_id : null,
            'meeting_id' => !empty($orderItem->reserve_meeting_id) ? $orderItem->reserveMeeting->meeting_id : null,
            'meeting_time_id' => !empty($orderItem->reserveMeeting) ? $orderItem->reserveMeeting->meeting_time_id : null,
            'subscribe_id' => $orderItem->subscribe_id,
            'promotion_id' => $orderItem->promotion_id,
            'registration_package_id' => $orderItem->registration_package_id,
            'product_order_id' => (!empty($orderItem->product_order_id)) ? $orderItem->product_order_id : null,
            'installment_payment_id' => $orderItem->installment_payment_id ?? null,
            'gift_id' => $orderItem->gift_id ?? null,
            'type' => $orderType,
            'payment_method' => $payment_method,
            'amount' => $orderItem->amount,
            'tax' => $orderItem->tax_price,
            'commission' => $orderItem->commission_price,
            'discount' => $orderItem->discount,
            'total_amount' => $orderItem->total_amount,
            'product_delivery_fee' => $orderItem->product_delivery_fee,
            'created_at' => time(),
        ]);

        self::handleSaleNotifications($orderItem, $seller_id);

        if (!empty($orderItem->product_id)) {
            $buyStoreReward = RewardAccounting::calculateScore(Reward::BUY_STORE_PRODUCT, $orderItem->total_amount);
            RewardAccounting::makeRewardAccounting($orderItem->user_id, $buyStoreReward, Reward::BUY_STORE_PRODUCT, $orderItem->product_id);
        }

        $buyReward = RewardAccounting::calculateScore(Reward::BUY, $orderItem->total_amount);
        RewardAccounting::makeRewardAccounting($orderItem->user_id, $buyReward, Reward::BUY);

        /* Registration Bonus Accounting */
        $registrationBonusAccounting = new RegistrationBonusAccounting();
        $registrationBonusAccounting->checkBonusAfterSale($orderItem->user_id);

        return $sale;
    }

    private static function handleSaleNotifications($orderItem, $seller_id)
    {
        $title = '';
        if (!empty($orderItem->webinar_id)) {
            $title = $orderItem->webinar->title;
        } elseif (!empty($orderItem->bundle_id)) {
            $title = $orderItem->bundle->title;
        } else if (!empty($orderItem->meeting_id)) {
            $title = trans('meeting.reservation_appointment');
        } else if (!empty($orderItem->subscribe_id)) {
            $title = $orderItem->subscribe->title . ' ' . trans('financial.subscribe');
        } else if (!empty($orderItem->promotion_id)) {
            $title = $orderItem->promotion->title . ' ' . trans('panel.promotion');
        } else if (!empty($orderItem->registration_package_id)) {
            $title = $orderItem->registrationPackage->title . ' ' . trans('update.registration_package');
        } else if (!empty($orderItem->product_id)) {
            $title = $orderItem->product->title;
        } else if (!empty($orderItem->installment_payment_id)) {
            $title = ($orderItem->installmentPayment->type == 'upfront') ? trans('update.installment_upfront') : trans('update.installment');
        }

        if (!empty($orderItem->gift_id) and !empty($orderItem->gift)) {
            $title .= ' (' . trans('update.a_gift_for_name_on_date_without_bold', ['name' => $orderItem->gift->name, 'date' => dateTimeFormat($orderItem->gift->date, 'j M Y H:i')]) . ')';
        }

        if ($orderItem->reserve_meeting_id) {
            $reserveMeeting = $orderItem->reserveMeeting;

            $notifyOptions = [
                '[amount]' => handlePrice($orderItem->amount),
                '[u.name]' => $orderItem->user->full_name,
                '[time.date]' => $reserveMeeting->day . ' ' . $reserveMeeting->time,
            ];
            sendNotification('new_appointment', $notifyOptions, $orderItem->user_id);
            sendNotification('new_appointment', $notifyOptions, $reserveMeeting->meeting->creator_id);
        } elseif (!empty($orderItem->product_id)) {
            $notifyOptions = [
                '[p.title]' => $title,
                '[amount]' => handlePrice($orderItem->total_amount),
                '[u.name]' => $orderItem->user->full_name,
            ];

            sendNotification('product_new_sale', $notifyOptions, $seller_id);
            sendNotification('product_new_purchase', $notifyOptions, $orderItem->user_id);
            sendNotification('new_store_order', $notifyOptions, 1);
        } elseif (!empty($orderItem->installment_payment_id)) {
            // TODO:: installment notification
        } else {
            $notifyOptions = [
                '[c.title]' => $title,
            ];

            sendNotification('new_sales', $notifyOptions, $seller_id);
            sendNotification('new_purchase', $notifyOptions, $orderItem->user_id);
        }

        if (!empty($orderItem->webinar_id)) {
            $notifyOptions = [
                '[u.name]' => $orderItem->user->full_name,
                '[c.title]' => $title,
                '[amount]' => handlePrice($orderItem->total_amount),
                '[time.date]' => dateTimeFormat(time(), 'j M Y H:i'),
            ];
            sendNotification("new_course_enrollment", $notifyOptions, 1);
        }

        if (!empty($orderItem->subscribe_id)) {
            $notifyOptions = [
                '[u.name]' => $orderItem->user->full_name,
                '[item_title]' => $orderItem->subscribe->title,
                '[amount]' => handlePrice($orderItem->total_amount),
            ];
            sendNotification("subscription_plan_activated", $notifyOptions, 1);
        }
    }

    public function getIncomeItem()
    {
        if ($this->payment_method == self::$subscribe) {
            $used = SubscribeUse::where('webinar_id', $this->webinar_id)
                ->where('sale_id', $this->id)
                ->first();

            if (!empty($used)) {
                $subscribe = $used->subscribe;

                $financialSettings = getFinancialSettings();
                $commission = $financialSettings['commission'] ?? 0;

                // Prevent division by zero when usable_count is zero or null
                $pricePerSubscribe = ($subscribe->usable_count && $subscribe->usable_count > 0)
                    ? ($subscribe->price / $subscribe->usable_count)
                    : 0;
                $commissionPrice = $commission ? $pricePerSubscribe * $commission / 100 : 0;

                return round($pricePerSubscribe - $commissionPrice, 2);
            }
        }

        $income = $this->total_amount - $this->tax - $this->commission;
        return round($income, 2);
    }

    public function getUsedSubscribe($user_id, $itemId, $itemName = 'webinar_id')
    {
        $subscribe = null;
        // Look for SubscribeUse by item_id and user_id directly, not by sale_id
        // because SubscribeUse records use subscription sale_id, not course sale_id
        // Prioritize SubscribeUse records linked to the most recent active subscription sale
        $uses = SubscribeUse::where($itemName, $itemId)
            ->where('user_id', $user_id)
            ->where('active', true)
            ->where(function($query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
            })
            ->get();

        if ($uses->isNotEmpty()) {
            // If multiple active uses exist, find the one linked to the most recent active subscription
            $bestUse = null;
            $latestSaleDate = 0;
            
            foreach ($uses as $use) {
                // Find the subscription sale for this SubscribeUse
                $subscribeSale = self::where('buyer_id', $user_id)
                    ->where('type', self::$subscribe)
                    ->where('subscribe_id', $use->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($subscribeSale && $subscribeSale->created_at > $latestSaleDate) {
                    // Check if this subscription is still active
                    $subscribe = Subscribe::where('id', $use->subscribe_id)->first();
                    if ($subscribe) {
                        $usedDays = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                        if ($usedDays <= $subscribe->days) {
                            $bestUse = $use;
                            $latestSaleDate = $subscribeSale->created_at;
                        }
                    }
                }
            }
            
            // If no active subscription found, use the first use (for backward compatibility)
            if (!$bestUse && $uses->isNotEmpty()) {
                $bestUse = $uses->first();
            }
            
            if ($bestUse) {
                $subscribe = Subscribe::where('id', $bestUse->subscribe_id)->first();
                if (!empty($subscribe)) {
                    $subscribe->installment_order_id = $bestUse->installment_order_id;
                }
            }
        }

        return $subscribe;
    }

    public function checkExpiredPurchaseWithSubscribe($user_id, $itemId, $itemName = 'webinar_id')
    {
        $result = true;

        $subscribe = $this->getUsedSubscribe($user_id, $itemId, $itemName);

        if (!empty($subscribe)) {
            $subscribeSale = self::where('buyer_id', $user_id)
                ->where('type', self::$subscribe)
                ->where('subscribe_id', $subscribe->id)
                ->whereNull('refund_at')
                ->latest('created_at')
                ->first();

            if (!empty($subscribeSale)) {
                // Use the same expiration logic as getActiveSubscribes()
                // Honor custom_expiration_date if set (could be from renewal extension)
                // But cap it at a reasonable maximum (purchase_date + subscription_days * 3) to prevent bugs
                $usedDays = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                $calculatedExpiration = $subscribeSale->created_at + ($subscribe->days * 86400);
                $maxReasonableExpiration = $subscribeSale->created_at + (($subscribe->days * 3) + 7) * 86400;
                
                $isExpired = false;
                if (!empty($subscribeSale->custom_expiration_date)) {
                    // Check if custom_expiration_date is within reasonable bounds
                    if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                        // Custom expiration is unreasonably far in the future - likely a bug
                        // Use calculated expiration instead
                        $effectiveExpiration = $calculatedExpiration;
                    } else {
                        // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                        $effectiveExpiration = $subscribeSale->custom_expiration_date;
                    }
                    $isExpired = $effectiveExpiration <= time();
                } else {
                    // Use original logic: check if days > countDayOfSale
                    $isExpired = $subscribe->days > 0 && $usedDays > $subscribe->days;
                }
                
                if (!$isExpired) {
                    $result = false;
                }
            }
        } else {
            // If no active SubscribeUse found, check if user has any active subscription
            // that could cover this course. This handles cases where SubscribeUse was
            // incorrectly expired but subscription is still active.
            $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user_id);
            
            if ($activeSubscribes->isNotEmpty()) {
                // Get the course/bundle to check if it's subscribable
                $item = null;
                if ($itemName == 'webinar_id') {
                    $item = \App\Models\Webinar::find($itemId);
                } else {
                    $item = \App\Models\Bundle::find($itemId);
                }
                
                if (!empty($item)) {
                    // Check if any active subscription can be used for this course
                    foreach ($activeSubscribes as $activeSubscribe) {
                        // Check if subscription has infinite use or has available slots
                        $hasAvailableSlots = $activeSubscribe->infinite_use || 
                            ($activeSubscribe->usable_count > 0 && 
                             $activeSubscribe->used_count < $activeSubscribe->usable_count);
                        
                        if ($hasAvailableSlots) {
                            // Check if subscription categories match (if subscription has category restrictions)
                            $categoryMatches = true;
                            if ($activeSubscribe->categories->isNotEmpty()) {
                                $categoryMatches = $activeSubscribe->categories->contains('id', $item->category_id);
                            }
                            
                            if ($categoryMatches) {
                                // Check if the subscription sale is still within valid days
                                $subscribeSale = self::where('buyer_id', $user_id)
                                    ->where('type', self::$subscribe)
                                    ->where('subscribe_id', $activeSubscribe->id)
                                    ->whereNull('refund_at')
                                    ->latest('created_at')
                                    ->first();
                                
                                if (!empty($subscribeSale)) {
                                    $usedDays = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                                    if ($usedDays <= $activeSubscribe->days) {
                                        $result = false; // Subscription is active and can cover this course
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * For subscription renewal flows: the older non-refunded sale whose extension matches this
     * row (admin list shows this refunded row as "success" and links refund to the partner sale).
     *
     * @see resources/views/admin/financial/sales/lists.blade.php
     */
    public function getAdminRenewalExtensionPartnerSale(): ?self
    {
        if (!$this->adminRenewalExtensionPartnerSaleFetched) {
            $this->adminRenewalExtensionPartnerSaleFetched = true;
            if (empty($this->refund_at) || $this->type !== self::$subscribe) {
                $this->adminRenewalExtensionPartnerSale = null;
            } else {
                $this->adminRenewalExtensionPartnerSale = self::where('buyer_id', $this->buyer_id)
                    ->where('type', self::$subscribe)
                    ->where('subscribe_id', $this->subscribe_id)
                    ->whereNull('refund_at')
                    ->where('id', '!=', $this->id)
                    ->where('created_at', '<', $this->created_at)
                    ->whereNotNull('custom_expiration_date')
                    ->where('custom_expiration_date', '>', $this->created_at)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }
        }

        return $this->adminRenewalExtensionPartnerSale;
    }

    /**
     * Status label for admin financial sales list and Excel export (must match lists.blade.php).
     */
    public function getAdminFinancialSalesListStatusText(): string
    {
        if ($this->getAdminRenewalExtensionPartnerSale()) {
            return trans('admin/main.success');
        }
        if (!empty($this->refund_at)) {
            return trans('admin/main.refund');
        }
        if (!$this->access_to_purchased_item) {
            return trans('update.access_blocked');
        }

        return trans('admin/main.success');
    }

    public function getAdminFinancialSalesListStatusCssClass(): string
    {
        if ($this->getAdminRenewalExtensionPartnerSale()) {
            return 'text-success';
        }
        if (!empty($this->refund_at)) {
            return 'text-warning';
        }
        if (!$this->access_to_purchased_item) {
            return 'text-danger';
        }

        return 'text-success';
    }
}
