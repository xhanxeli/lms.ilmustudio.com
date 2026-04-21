<?php

namespace App\Http\Controllers\Admin;

use App\Exports\salesExport;
use App\Http\Controllers\Controller;
use App\Models\Accounting;
use App\Models\Order;
use App\Models\ReserveMeeting;
use App\Models\Sale;
use App\Models\SaleLog;
use App\Models\Webinar;
use App\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('admin_sales_list');

        $query = Sale::whereNull('product_order_id');

        $totalSales = [
            'count' => deepClone($query)
                ->where('type', Sale::$subscribe)
                ->whereNull('webinar_id') // Exclude Classes Sales
                ->whereNull('meeting_id') // Exclude Appointments Sales
                ->count(),
            'amount' => deepClone($query)
                ->where('type', Sale::$subscribe)
                ->whereNull('webinar_id') // Exclude Classes Sales
                ->whereNull('meeting_id') // Exclude Appointments Sales
                ->sum('total_amount'),
        ];

        $classesSales = [
            'count' => deepClone($query)->whereNotNull('webinar_id')->count(),
            'amount' => deepClone($query)->whereNotNull('webinar_id')->sum('total_amount'),
        ];
        $appointmentSales = [
            'count' => deepClone($query)->whereNotNull('meeting_id')->count(),
            'amount' => deepClone($query)->whereNotNull('meeting_id')->sum('total_amount'),
        ];
        $failedSales = Order::where('status', Order::$fail)->count();

        $salesQuery = self::applySalesFilters($query, $request);

        $sales = $salesQuery->orderBy('created_at', 'desc')
            ->with([
                'buyer' => function ($query) {
                    $query->with([
                        'userGroup' => function ($query) {
                            $query->with('group');
                        }
                    ]);
                },
                'webinar',
                'meeting',
                'subscribe',
                'promotion'
            ])
            ->paginate(10);

        foreach ($sales as $sale) {
            $sale = self::makeTitleForAdmin($sale);

            // Load affiliate and referral code data for subscribe sales
            if ($sale->type == Sale::$subscribe && !empty($sale->buyer_id)) {
                $affiliate = \App\Models\Affiliate::where('referred_user_id', $sale->buyer_id)->first();
                if ($affiliate && !empty($affiliate->affiliate_user_id)) {
                    $affiliateCode = \App\Models\AffiliateCode::where('user_id', $affiliate->affiliate_user_id)->first();
                    $sale->affiliate_user = $affiliate->affiliateUser;
                    $sale->affiliate_code = $affiliateCode ? $affiliateCode->code : null;
                }
            }

            if (empty($sale->saleLog)) {
                SaleLog::create([
                    'sale_id' => $sale->id,
                    'viewed_at' => time()
                ]);
            }
        }

        $data = [
            'pageTitle' => trans('admin/pages/financial.sales_page_title'),
            'sales' => $sales,
            'totalSales' => $totalSales,
            'classesSales' => $classesSales,
            'appointmentSales' => $appointmentSales,
            'failedSales' => $failedSales,
        ];

        $teacher_ids = $request->get('teacher_ids');
        $student_ids = $request->get('student_ids');
        $webinar_ids = $request->get('webinar_ids');

        if (!empty($teacher_ids)) {
            $data['teachers'] = User::select('id', 'full_name')
                ->whereIn('id', $teacher_ids)->get();
        }

        if (!empty($student_ids)) {
            $data['students'] = User::select('id', 'full_name')
                ->whereIn('id', $student_ids)->get();
        }

        if (!empty($webinar_ids)) {
            $data['webinars'] = Webinar::select('id')
                ->whereIn('id', $webinar_ids)->get();
        }

        return view('admin.financial.sales.lists', $data);
    }

    public static function makeTitleForAdmin($sale)
    {
        if (!empty($sale->webinar_id) or !empty($sale->bundle_id)) {
            $item = !empty($sale->webinar_id) ? $sale->webinar : $sale->bundle;

            $sale->item_title = $item ? $item->title : trans('update.deleted_item');
            $sale->item_id = $item ? $item->id : '';
            $sale->item_seller = ($item and $item->creator) ? $item->creator->full_name : trans('update.deleted_item');
            $sale->seller_id = ($item and $item->creator) ? $item->creator->id : '';
            $sale->sale_type = ($item and $item->creator) ? $item->creator->id : '';
        } elseif (!empty($sale->meeting_id)) {
            $sale->item_title = trans('panel.meeting');
            $sale->item_id = $sale->meeting_id;
            $sale->item_seller = ($sale->meeting and $sale->meeting->creator) ? $sale->meeting->creator->full_name : trans('update.deleted_item');
            $sale->seller_id = ($sale->meeting and $sale->meeting->creator) ? $sale->meeting->creator->id : '';
        } elseif (!empty($sale->subscribe_id)) {
            $sale->item_title = !empty($sale->subscribe) ? $sale->subscribe->title : trans('update.deleted_subscribe');
            $sale->item_id = $sale->subscribe_id;
            $sale->item_seller = 'Admin';
            $sale->seller_id = '';
        } elseif (!empty($sale->promotion_id)) {
            $sale->item_title = !empty($sale->promotion) ? $sale->promotion->title : trans('update.deleted_promotion');
            $sale->item_id = $sale->promotion_id;
            $sale->item_seller = 'Admin';
            $sale->seller_id = '';
        } elseif (!empty($sale->registration_package_id)) {
            $sale->item_title = !empty($sale->registrationPackage) ? $sale->registrationPackage->title : 'Deleted registration Package';
            $sale->item_id = $sale->registration_package_id;
            $sale->item_seller = 'Admin';
            $sale->seller_id = '';
        } elseif (!empty($sale->gift_id) and !empty($sale->gift)) {
            $gift = $sale->gift;
            $item = !empty($gift->webinar_id) ? $gift->webinar : (!empty($gift->bundle_id) ? $gift->bundle : $gift->product);

            $sale->item_title = $gift->getItemTitle();
            $sale->item_id = $item->id;
            $sale->item_seller = $item->creator->full_name;
            $sale->seller_id = $item->creator_id;
        } elseif (!empty($sale->installment_payment_id) and !empty($sale->installmentOrderPayment)) {
            $installmentOrderPayment = $sale->installmentOrderPayment;
            $installmentOrder = $installmentOrderPayment->installmentOrder;
            $installmentItem = $installmentOrder->getItem();

            $sale->item_title = !empty($installmentItem) ? $installmentItem->title : '--';
            $sale->item_id = !empty($installmentItem) ? $installmentItem->id : '--';
            $sale->item_seller = !empty($installmentItem) ? $installmentItem->creator->full_name : '--';
            $sale->seller_id = !empty($installmentItem) ? $installmentItem->creator->id : '--';
        } else {
            $sale->item_title = '---';
            $sale->item_id = '---';
            $sale->item_seller = '---';
            $sale->seller_id = '';
        }

        return $sale;
    }

    public static function applySalesFilters($query, Request $request)
    {
        $item_title = $request->get('item_title');
        $from = $request->get('from');
        $to = $request->get('to');
        $status = $request->get('status');
        $webinar_ids = $request->get('webinar_ids', []);
        $teacher_ids = $request->get('teacher_ids', []);
        $student_ids = $request->get('student_ids', []);
        $userIds = array_merge($teacher_ids, $student_ids);

        if (!empty($item_title)) {
            // Check if the search term is an email or contains @ symbol (email pattern)
            $isEmail = filter_var($item_title, FILTER_VALIDATE_EMAIL) || strpos($item_title, '@') !== false;
            
            if ($isEmail) {
                // Search by email - find users with matching email
                $emailUserIds = User::where('email', 'like', "%$item_title%")
                    ->pluck('id')
                    ->toArray();
                
                if (!empty($emailUserIds)) {
                    // Add email-matched user IDs to the filter
                    $userIds = array_merge($userIds, $emailUserIds);
                } else {
                    // If no users found with this email, return empty result
                    $query->whereRaw('1 = 0');
                    return $query;
                }
            } else {
                $saleId = (ctype_digit(trim($item_title))) ? (int) trim($item_title) : null;

                // Search by item title (webinar title)
                $ids = Webinar::whereTranslationLike('title', "%$item_title%")->pluck('id')->toArray();
                $webinar_ids = array_merge($webinar_ids, $ids);
                
                // Search by affiliate name - find affiliate users whose name matches
                $affiliateUserIds = User::where('full_name', 'like', "%$item_title%")
                    ->whereHas('affiliateCode')
                    ->pluck('id')
                    ->toArray();
                
                // Search by referral code - find affiliate codes that match
                $affiliateCodeUserIds = \App\Models\AffiliateCode::where('code', 'like', "%$item_title%")
                    ->pluck('user_id')
                    ->toArray();
                
                // Combine affiliate user IDs
                $allAffiliateUserIds = array_unique(array_merge($affiliateUserIds, $affiliateCodeUserIds));
                
                // Find buyers who were referred by these affiliates (for subscribe sales only)
                $affiliateReferredBuyerIds = [];
                if (!empty($allAffiliateUserIds)) {
                    $affiliateReferredBuyerIds = \App\Models\Affiliate::whereIn('affiliate_user_id', $allAffiliateUserIds)
                        ->pluck('referred_user_id')
                        ->toArray();
                }
                
                // Build search conditions: item title OR affiliate/referral code
                $hasItemMatches = !empty($ids);
                $hasAffiliateMatches = !empty($affiliateReferredBuyerIds);
                $hasSaleIdMatch = !empty($saleId);
                
                if ($hasItemMatches || $hasAffiliateMatches || $hasSaleIdMatch) {
                    $query->where(function($q) use ($ids, $affiliateReferredBuyerIds, $hasItemMatches, $hasAffiliateMatches, $hasSaleIdMatch, $saleId) {
                        $started = false;

                        // Search by item title (webinar)
                        if ($hasItemMatches) {
                            $q->whereIn('webinar_id', $ids);
                            $started = true;
                        }
                        
                        // Search by affiliate/referral code (subscribe sales only)
                        if ($hasAffiliateMatches) {
                            $method = $started ? 'orWhere' : 'where';
                            $q->{$method}(function($subQ) use ($affiliateReferredBuyerIds) {
                                $subQ->where('type', Sale::$subscribe)
                                    ->whereIn('buyer_id', $affiliateReferredBuyerIds);
                            });
                            $started = true;
                        }

                        // Search directly by sale id
                        if ($hasSaleIdMatch) {
                            $method = $started ? 'orWhere' : 'where';
                            $q->{$method}('id', $saleId);
                        }
                    });
                } else {
                    // No matches found for any search type
                    $query->whereRaw('1 = 0');
                    return $query;
                }
            }
        }

        $query = fromAndToDateFilter($from, $to, $query, 'created_at');

        if (!empty($status)) {
            if ($status == 'success') {
                $query->where(function ($q) {
                    // Regular successful sales (not refunded and not access-blocked)
                    $q->where(function ($q2) {
                        $q2->whereNull('refund_at')
                            ->where('access_to_purchased_item', true);
                    });

                    // Special case: subscription renewal flow may mark the *new* sale as refunded
                    // but the UI shows it as "success" because it extended the previous sale.
                    $q->orWhere(function ($q2) {
                        $q2->where('type', Sale::$subscribe)
                            ->whereNotNull('refund_at')
                            ->whereExists(function ($sub) {
                                $sub->selectRaw('1')
                                    ->from('sales as s2')
                                    ->whereColumn('s2.buyer_id', 'sales.buyer_id')
                                    ->whereColumn('s2.subscribe_id', 'sales.subscribe_id')
                                    ->where('s2.type', Sale::$subscribe)
                                    ->whereNull('s2.refund_at')
                                    ->whereColumn('s2.id', '!=', 'sales.id')
                                    ->whereColumn('s2.created_at', '<', 'sales.created_at')
                                    ->whereNotNull('s2.custom_expiration_date')
                                    ->whereColumn('s2.custom_expiration_date', '>', 'sales.created_at');
                            });
                    });
                });
            } elseif ($status == 'refund') {
                $query->whereNotNull('refund_at');

                // Exclude subscription renewal-extension rows that the UI treats as "success"
                $query->where(function ($q) {
                    $q->where('type', '!=', Sale::$subscribe)
                        ->orWhereNotExists(function ($sub) {
                            $sub->selectRaw('1')
                                ->from('sales as s2')
                                ->whereColumn('s2.buyer_id', 'sales.buyer_id')
                                ->whereColumn('s2.subscribe_id', 'sales.subscribe_id')
                                ->where('s2.type', Sale::$subscribe)
                                ->whereNull('s2.refund_at')
                                ->whereColumn('s2.id', '!=', 'sales.id')
                                ->whereColumn('s2.created_at', '<', 'sales.created_at')
                                ->whereNotNull('s2.custom_expiration_date')
                                ->whereColumn('s2.custom_expiration_date', '>', 'sales.created_at');
                        });
                });
            } elseif ($status == 'blocked') {
                $query->whereNull('refund_at')
                    ->where('access_to_purchased_item', false);
            }
        }

        if (!empty($webinar_ids) and count($webinar_ids)) {
            // Only apply webinar_ids filter if we didn't already filter by webinar_id in the search
            // (to avoid double filtering when searching by item title)
            if (empty($item_title) || empty($ids)) {
                $query->whereIn('webinar_id', $webinar_ids);
            }
        }

        if (!empty($userIds) and count($userIds)) {
            $query->where(function ($query) use ($userIds) {
                $query->whereIn('buyer_id', $userIds);
                $query->orWhereIn('seller_id', $userIds);
            });
        }

        return $query;
    }

    public function refund($id)
    {
        $this->authorize('admin_sales_refund');

        $sale = Sale::findOrFail($id);

        if ($sale->type == Sale::$subscribe) {
            $salesWithSubscribe = Sale::whereNotNull('webinar_id')
                ->where('buyer_id', $sale->buyer_id)
                ->where('subscribe_id', $sale->subscribe_id)
                ->whereNull('refund_at')
                ->with('webinar', 'subscribe')
                ->get();

            foreach ($salesWithSubscribe as $saleWithSubscribe) {
                $saleWithSubscribe->update([
                    'refund_at' => time(),
                    'access_to_purchased_item' => false
                ]);

                if (!empty($saleWithSubscribe->webinar) and !empty($saleWithSubscribe->subscribe)) {
                    Accounting::refundAccountingForSaleWithSubscribe($saleWithSubscribe->webinar, $saleWithSubscribe->subscribe);
                }

                // Note: Do NOT refund affiliate commissions for individual subject sales here
                // Individual subject sales don't have affiliate commissions - only the main subscription plan purchase does
                // The affiliate commission refund will be handled by refundAccounting($sale) below for the main subscription sale
            }
            
            // When refunding a subscription, we need to handle the case where there are older sales
            // that were extended via custom_expiration_date (from renewals). Clear those extensions
            // so the older sales expire based on their original expiration date.
            $olderSubscriptionSales = Sale::where('buyer_id', $sale->buyer_id)
                ->where('subscribe_id', $sale->subscribe_id)
                ->where('type', Sale::$subscribe)
                ->whereNull('refund_at')
                ->where('id', '!=', $sale->id)
                ->where('created_at', '<', $sale->created_at)
                ->whereNotNull('custom_expiration_date')
                ->get();
            
            foreach ($olderSubscriptionSales as $olderSale) {
                // Clear the custom_expiration_date so the sale expires based on its original expiration
                $olderSale->update([
                    'custom_expiration_date' => null
                ]);
                
                \Log::info('Cleared custom_expiration_date from older subscription sale after refund', [
                    'refunded_sale_id' => $sale->id,
                    'older_sale_id' => $olderSale->id,
                    'buyer_id' => $sale->buyer_id,
                    'subscribe_id' => $sale->subscribe_id,
                ]);
            }
            
            // Expire all SubscribeUse records for this subscription when refunded
            // This ensures all subjects are expired when admin refunds the subscription plan
            // Expire ALL uses for this subscription, not just for this specific sale_id
            // This handles cases where uses are linked to older sales that were extended
            $subscribeUses = \App\Models\SubscribeUse::where('user_id', $sale->buyer_id)
                ->where('subscribe_id', $sale->subscribe_id)
                ->where('active', true)
                ->get();
            
            foreach ($subscribeUses as $subscribeUse) {
                $subscribeUse->expire();
            }
            
            \Log::info('Expired SubscribeUse records after subscription refund', [
                'sale_id' => $sale->id,
                'buyer_id' => $sale->buyer_id,
                'subscribe_id' => $sale->subscribe_id,
                'expired_count' => $subscribeUses->count(),
                'cleared_custom_expiration_count' => $olderSubscriptionSales->count(),
            ]);
        }

        if (!empty($sale->total_amount)) {
            Accounting::refundAccounting($sale);
        }

        if (!empty($sale->meeting_id) and $sale->type == Sale::$meeting) {
            $appointment = ReserveMeeting::where('meeting_id', $sale->meeting_id)
                ->where('sale_id', $sale->id)
                ->first();

            if (!empty($appointment)) {
                $appointment->update([
                    'status' => ReserveMeeting::$canceled
                ]);
            }
        }

        $sale->update([
            'refund_at' => time(),
            'access_to_purchased_item' => false
        ]);

        return back();
    }

    public function cancel($id)
    {
        $this->authorize('admin_sales_refund'); // Use same permission as refund

        $sale = Sale::findOrFail($id);

        // Only allow canceling courses (webinars)
        if (empty($sale->webinar_id)) {
            return back()->with('msg', trans('admin/main.cancel_only_for_courses'));
        }

        // Check if already refunded or canceled
        if (!empty($sale->refund_at) || !$sale->access_to_purchased_item) {
            return back()->with('msg', trans('admin/main.sale_already_canceled'));
        }

        // Find and expire SubscribeUse records if this was a subscription purchase
        $subscribeUses = \App\Models\SubscribeUse::where('user_id', $sale->buyer_id)
            ->where('webinar_id', $sale->webinar_id)
            ->where('active', true)
            ->where(function($query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
            })
            ->get();

        foreach ($subscribeUses as $subscribeUse) {
            // Only expire if the sale_id matches or if it's linked to a non-refunded subscription sale
            $shouldExpire = false;
            
            if ($subscribeUse->sale_id == $sale->id) {
                // Direct match - this SubscribeUse was created for this sale
                $shouldExpire = true;
            } else {
                // Check if the SubscribeUse is linked to a subscription sale that's still active
                $subscribeSale = Sale::where('id', $subscribeUse->sale_id)
                    ->where('type', Sale::$subscribe)
                    ->whereNull('refund_at')
                    ->first();
                
                if ($subscribeSale) {
                    // This SubscribeUse is from a subscription, expire it to free up the slot
                    $shouldExpire = true;
                }
            }
            
            if ($shouldExpire) {
                $subscribeUse->expire();
                
                // Clear cache for subscription use counts
                $cacheKeys = [
                    "subscribe_use_count_{$sale->buyer_id}_{$subscribeUse->subscribe_id}_{$subscribeUse->sale_id}",
                    "subscribe_use_count_installment_{$sale->buyer_id}_{$subscribeUse->subscribe_id}_{$subscribeUse->installment_order_id}",
                ];
                foreach ($cacheKeys as $key) {
                    \Cache::forget($key);
                }
            }
        }

        // Remove access to the course
        $sale->update([
            'access_to_purchased_item' => false
        ]);

        // Clear cache for purchased courses to update dashboard and sidebar counts
        $cacheKeys = [
            "purchased_courses_ids_{$sale->buyer_id}",
            "user_purchased_courses_with_active_subscriptions_{$sale->buyer_id}",
            "active_subscribes_installments_{$sale->buyer_id}",
            "active_subscribes_sales_{$sale->buyer_id}",
        ];
        foreach ($cacheKeys as $key) {
            \Cache::forget($key);
        }

        // Also clear any subscription use count caches that might be affected
        foreach ($subscribeUses as $subscribeUse) {
            $subscribeCacheKeys = [
                "subscribe_use_count_{$sale->buyer_id}_{$subscribeUse->subscribe_id}_{$subscribeUse->sale_id}",
                "subscribe_use_count_installment_{$sale->buyer_id}_{$subscribeUse->subscribe_id}_{$subscribeUse->installment_order_id}",
            ];
            foreach ($subscribeCacheKeys as $key) {
                \Cache::forget($key);
            }
        }

        // Refund affiliate commission if this was a direct purchase (not subscription-based)
        // Only refund if the sale doesn't have a subscribe_id, meaning it was a direct purchase
        // If it was purchased with a subscription, we don't refund the subscription's affiliate commission
        // just because one course is canceled
        if (empty($sale->subscribe_id)) {
            Accounting::refundAffiliateCommission($sale);
        }

        \Log::info('Course canceled by admin', [
            'sale_id' => $sale->id,
            'buyer_id' => $sale->buyer_id,
            'webinar_id' => $sale->webinar_id,
            'expired_subscribe_uses_count' => $subscribeUses->count(),
        ]);

        return back()->with('msg', trans('admin/main.course_canceled_successfully'));
    }

    public function invoice($id)
    {
        $this->authorize('admin_sales_invoice');

        $sale = Sale::where('id', $id)
            ->with([
                'order',

                'buyer' => function ($query) {
                    $query->select('id', 'full_name');
                },
                'webinar' => function ($query) {
                    $query->with([
                        'teacher' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                        'creator' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                        'webinarPartnerTeacher' => function ($query) {
                            $query->with([
                                'teacher' => function ($query) {
                                    $query->select('id', 'full_name');
                                },
                            ]);
                        }
                    ]);
                },
                'bundle'
            ])
            ->first();

        if (!empty($sale)) {
            $webinar = $sale->webinar;

            if (empty($webinar) and !empty($sale->bundle)) {
                $webinar = $sale->bundle;
            }

            if (!empty($webinar)) {
                $data = [
                    'pageTitle' => trans('webinars.invoice_page_title'),
                    'sale' => $sale,
                    'webinar' => $webinar
                ];

                return view('admin.financial.sales.invoice', $data);
            }
        }

        abort(404);
    }

    public function exportExcel(Request $request)
    {
        $this->authorize('admin_sales_export');

        // Start with the same base query as the index method (exclude product orders)
        $query = Sale::whereNull('product_order_id');

        $salesQuery = self::applySalesFilters($query, $request);

        $sales = $salesQuery->orderBy('created_at', 'desc')
            ->with([
                'buyer',
                'webinar',
                'meeting',
                'subscribe',
                'promotion'
            ])
            ->get();

        foreach ($sales as $sale) {
            $sale = self::makeTitleForAdmin($sale);

            // Load affiliate and referral code data for subscribe sales
            if ($sale->type == Sale::$subscribe && !empty($sale->buyer_id)) {
                $affiliate = \App\Models\Affiliate::where('referred_user_id', $sale->buyer_id)->first();
                if ($affiliate && !empty($affiliate->affiliate_user_id)) {
                    $affiliateCode = \App\Models\AffiliateCode::where('user_id', $affiliate->affiliate_user_id)->first();
                    $sale->affiliate_user = $affiliate->affiliateUser;
                    $sale->affiliate_code = $affiliateCode ? $affiliateCode->code : null;
                }
            }
        }

        $export = new salesExport($sales);

        return Excel::download($export, 'sales.xlsx');
    }
}
