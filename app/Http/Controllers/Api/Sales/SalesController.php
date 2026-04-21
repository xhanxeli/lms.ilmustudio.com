<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Admin\SaleController as AdminSaleController;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCode;
use App\Models\Sale;
use App\User;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);
        $perPage = max(1, min(200, $perPage));

        $query = Sale::whereNull('product_order_id');
        $query = AdminSaleController::applySalesFilters($query, $request);

        $sales = $query
            ->orderBy('created_at', 'desc')
            ->with(['buyer', 'webinar', 'meeting', 'subscribe', 'promotion'])
            ->paginate($perPage);

        // Batch-load affiliate/referral info for subscribe sales (same as admin list/export).
        $subscribeBuyerIds = [];
        foreach ($sales->items() as $sale) {
            if ($sale->type === Sale::$subscribe && !empty($sale->buyer_id)) {
                $subscribeBuyerIds[] = (int) $sale->buyer_id;
            }
        }
        $subscribeBuyerIds = array_values(array_unique($subscribeBuyerIds));

        $affiliateUsersByReferredBuyerId = [];
        $affiliateCodesByAffiliateUserId = [];

        if (!empty($subscribeBuyerIds)) {
            $affiliates = Affiliate::whereIn('referred_user_id', $subscribeBuyerIds)->get();
            $affiliateUserIds = $affiliates->pluck('affiliate_user_id')->filter()->unique()->values()->all();

            $affiliateUsers = [];
            if (!empty($affiliateUserIds)) {
                $affiliateUsers = User::whereIn('id', $affiliateUserIds)->get()->keyBy('id');
                $affiliateCodesByAffiliateUserId = AffiliateCode::whereIn('user_id', $affiliateUserIds)
                    ->get()
                    ->keyBy('user_id');
            }

            foreach ($affiliates as $affiliate) {
                if (!empty($affiliate->referred_user_id) && !empty($affiliate->affiliate_user_id) && isset($affiliateUsers[$affiliate->affiliate_user_id])) {
                    $affiliateUsersByReferredBuyerId[(int) $affiliate->referred_user_id] = $affiliateUsers[$affiliate->affiliate_user_id];
                }
            }
        }

        $items = [];
        foreach ($sales->items() as $sale) {
            // Mirror admin list title enrichment.
            $sale = AdminSaleController::makeTitleForAdmin($sale);

            $affiliateUser = null;
            $affiliateCode = null;
            if ($sale->type === Sale::$subscribe && !empty($sale->buyer_id) && isset($affiliateUsersByReferredBuyerId[(int) $sale->buyer_id])) {
                $affiliateUser = $affiliateUsersByReferredBuyerId[(int) $sale->buyer_id];
                $affiliateCode = !empty($affiliateUser?->id) ? ($affiliateCodesByAffiliateUserId[$affiliateUser->id] ?? null) : null;
            }

            $items[] = [
                'id' => $sale->id,
                'buyer_id' => $sale->buyer_id,
                'buyer_name' => $sale->buyer?->full_name,
                'buyer_email' => $sale->buyer?->email,
                'buyer_mobile' => $sale->buyer?->mobile,
                'affiliate_id' => $affiliateUser?->id,
                'affiliate_name' => $affiliateUser?->full_name,
                'referral_code' => $affiliateCode?->code,
                'type' => $sale->type,
                'payment_method' => $sale->payment_method,
                'amount' => (float) ($sale->amount ?? 0),
                'total_amount' => (float) ($sale->total_amount ?? 0),
                'created_at' => (int) $sale->created_at,
                'item_title' => $sale->item_title ?? null,
                'item_id' => $sale->item_id ?? null,
                'item_seller' => $sale->item_seller ?? null,
                'seller_id' => $sale->seller_id ?? null,
                'status_text' => $sale->getAdminFinancialSalesListStatusText(),
                'refund_at' => $sale->refund_at ? (int) $sale->refund_at : null,
                'access_to_purchased_item' => (bool) $sale->access_to_purchased_item,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $sales->currentPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'last_page' => $sales->lastPage(),
            ],
        ]);
    }
}

