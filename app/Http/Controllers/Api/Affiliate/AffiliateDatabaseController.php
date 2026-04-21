<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCode;
use App\User;
use Illuminate\Http\Request;

class AffiliateDatabaseController extends Controller
{
    /**
     * Single entry point. Query: type=referrals|users|codes (+ existing filters per type).
     */
    public function index(Request $request)
    {
        $type = $request->query('type', 'referrals');

        return match ($type) {
            'referrals' => $this->referralsResponse($request),
            'users' => $this->affiliateUsersResponse($request),
            'codes' => $this->codesResponse($request),
            default => apiResponse2(0, 'validation_error', 'Invalid type. Use: referrals, users, or codes.'),
        };
    }

    private function referralsResponse(Request $request)
    {
        $from = $request->get('from');
        $to = $request->get('to');
        $search = $request->get('search', '');
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $affiliatesQuery = Affiliate::query();

        if ($search !== '') {
            $affiliatesQuery->where(function ($query) use ($search) {
                $query->whereHas('affiliateUser', function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
                $query->orWhereHas('referredUser', function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        $affiliatesQuery = fromAndToDateFilter($from, $to, $affiliatesQuery, 'created_at');

        $paginator = $affiliatesQuery
            ->with([
                'affiliateUser' => function ($query) {
                    $query->select('id', 'full_name', 'email', 'role_id', 'role_name');
                },
                'referredUser' => function ($query) {
                    $query->select('id', 'full_name', 'email', 'role_id', 'role_name');
                },
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = [
            'type' => 'referrals',
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        return apiResponse2(1, 'passed', 'ok', $data);
    }

    private function affiliateUsersResponse(Request $request)
    {
        $search = $request->get('search', '');
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = User::where('affiliate', true)
            ->with([
                'affiliateCode' => function ($q) {
                    $q->select('id', 'user_id', 'code', 'created_at');
                },
                'userGroup',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                $q->orWhereHas('affiliateCode', function ($codeQuery) use ($search) {
                    $codeQuery->where('code', 'like', "%{$search}%");
                });
            });
        }

        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        $data = [
            'type' => 'users',
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        return apiResponse2(1, 'passed', 'ok', $data);
    }

    private function codesResponse(Request $request)
    {
        $search = $request->get('search', '');
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = AffiliateCode::query()->with(['user' => function ($q) {
            $q->select('id', 'full_name', 'email', 'affiliate');
        }]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        $data = [
            'type' => 'codes',
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        return apiResponse2(1, 'passed', 'ok', $data);
    }
}
