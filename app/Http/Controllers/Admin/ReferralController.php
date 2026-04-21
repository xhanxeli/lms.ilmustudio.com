<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ReferralHistoryExport;
use App\Exports\ReferralUsersExport;
use App\Http\Controllers\Controller;
use App\Models\Accounting;
use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReferralController extends Controller
{
    public function history(Request $request, $export = false)
    {
        $this->authorize('admin_referrals_history');

        $from = $request->get('from');
        $to = $request->get('to');
        $search = $request->get('search', '');

        $affiliatesQuery = Affiliate::query();

        // Apply search filter
        if (!empty($search)) {
            $affiliatesQuery->where(function($query) use ($search) {
                $query->whereHas('affiliateUser', function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
                $query->orWhereHas('referredUser', function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // Apply date filter
        $affiliatesQuery = fromAndToDateFilter($from, $to, $affiliatesQuery, 'created_at');

        // Count distinct affiliate users from the filtered query
        $affiliateUsersCount = deepClone($affiliatesQuery)
            ->select('affiliate_user_id')
            ->distinct()
            ->get()
            ->count();

        $allAffiliateAmounts = Accounting::where('is_affiliate_amount', true)
            ->where('system', false)
            ->sum('amount');

        $allAffiliateCommissionAmounts = Accounting::where('is_affiliate_commission', true)
            ->where('system', false)
            ->sum('amount');

        if ($export) {
            $affiliates = $affiliatesQuery
                ->with([
                    'affiliateUser' => function ($query) {
                        $query->select('id', 'full_name', 'role_id', 'role_name');
                    },
                    'referredUser' => function ($query) {
                        $query->select('id', 'full_name', 'role_id', 'role_name');
                    }
                ])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return $affiliates;
        }

        $affiliates = $affiliatesQuery
            ->with([
                'affiliateUser' => function ($query) {
                    $query->select('id', 'full_name', 'role_id', 'role_name');
                },
                'referredUser' => function ($query) {
                    $query->select('id', 'full_name', 'role_id', 'role_name');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $data = [
            'pageTitle' => trans('admin/main.referrals_history'),
            'affiliatesCount' => $affiliates->count(),
            'affiliateUsersCount' => $affiliateUsersCount,
            'allAffiliateAmounts' => $allAffiliateAmounts,
            'allAffiliateCommissionAmounts' => $allAffiliateCommissionAmounts,
            'affiliates' => $affiliates,
            'from' => $from,
            'to' => $to,
            'search' => $search,
        ];

        return view('admin.referrals.history', $data);
    }

    public function users(Request $request, $export = false)
    {
        $this->authorize('admin_referrals_users');

        $search = $request->get('search', '');

        // Get all users who are marked as affiliates, regardless of whether they have made referrals
        $query = \App\User::where('affiliate', true)
            ->with([
                'affiliateCode',
                'userGroup'
            ]);

        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
                
                $q->orWhereHas('affiliateCode', function($codeQuery) use ($search) {
                    $codeQuery->where('code', 'like', "%{$search}%");
                });
            });
        }

        if ($export) {
            $affiliateUsers = $query->orderBy('created_at', 'desc')->get();
            
            // Transform the data to match the expected format for export
            $affiliates = $affiliateUsers->map(function ($user) {
                $affiliate = new \App\Models\Affiliate();
                $affiliate->affiliate_user_id = $user->id;
                $affiliate->affiliateUser = $user;
                
                return $affiliate;
            });
            
            return $affiliates;
        }

        $affiliateUsers = $query->orderBy('created_at', 'desc')->paginate(50);

        // Transform the data to match the expected format for the view
        $affiliates = $affiliateUsers->getCollection()->map(function ($user) {
            // Create a mock Affiliate object to maintain compatibility with the view
            $affiliate = new \App\Models\Affiliate();
            $affiliate->affiliate_user_id = $user->id;
            $affiliate->affiliateUser = $user;
            
            return $affiliate;
        });

        // Replace the collection with our transformed data
        $affiliateUsers->setCollection($affiliates);

        $data = [
            'pageTitle' => trans('admin/main.users'),
            'affiliates' => $affiliateUsers,
            'search' => $search
        ];

        return view('admin.referrals.users', $data);
    }

    public function exportExcel(Request $request)
    {
        $this->authorize('admin_referrals_export');

        $type = $request->get('type', 'history');

        if ($type == 'users') {
            $referrals = $this->users($request, true);

            $export = new ReferralUsersExport($referrals);
        } else {
            $referrals = $this->history($request, true);

            $export = new ReferralHistoryExport($referrals);
        }

        return Excel::download($export, 'referrals_' . $type . '.xlsx');
    }
}
