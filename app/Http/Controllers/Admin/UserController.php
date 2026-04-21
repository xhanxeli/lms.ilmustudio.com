<?php

namespace App\Http\Controllers\Admin;

use App\Bitwise\UserLevelOfTraining;
use App\Exports\InstructorsExport;
use App\Exports\OrganizationsExport;
use App\Exports\StudentsExport;
use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\traits\UserFormFieldsTrait;
use App\Models\Badge;
use App\Models\BecomeInstructor;
use App\Models\Category;
use App\Models\ForumTopic;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Meeting;
use App\Models\Region;
use App\Models\ReserveMeeting;
use App\Models\Role;
use App\Models\Sale;
use App\Models\UserBadge;
use App\Models\UserBank;
use App\Models\UserCommission;
use App\Models\UserLoginHistory;
use App\Models\UserManualPurchase;
use App\Models\UserMeta;
use App\Models\UserOccupation;
use App\Models\UserRegistrationPackage;
use App\Models\UserSelectedBank;
use App\Models\UserSelectedBankSpecification;
use App\Models\Webinar;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AffiliateCode;
use App\Models\Affiliate;
use App\Models\SubscribeUse;

class UserController extends Controller
{
    use UserFormFieldsTrait;

    public function staffs(Request $request)
    {
        $this->authorize('admin_staffs_list');

        $staffsRoles = Role::where('is_admin', true)->get();
        $staffsRoleIds = $staffsRoles->pluck('id')->toArray();


        $query = User::whereIn('role_id', $staffsRoleIds);
        $query = $this->filters($query, $request);

        $users = $query->orderBy('created_at', 'desc')
            ->paginate(10);

        $data = [
            'pageTitle' => trans('admin/main.staff_list_title'),
            'users' => $users,
            'staffsRoles' => $staffsRoles,
        ];

        return view('admin.users.staffs', $data);
    }

    public function organizations(Request $request, $is_export_excel = false)
    {
        $this->authorize('admin_organizations_list');

        $query = User::where('role_name', Role::$organization);

        $totalOrganizations = deepClone($query)->count();
        $verifiedOrganizations = deepClone($query)->where('verified', true)
            ->count();
        $totalOrganizationsTeachers = User::where('role_name', Role::$teacher)
            ->whereNotNull('organ_id')
            ->count();
        $totalOrganizationsStudents = User::where('role_name', Role::$user)
            ->whereNotNull('organ_id')
            ->count();
        $userGroups = Group::where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();


        $query = $this->filters($query, $request);

        if ($is_export_excel) {
            $users = $query->orderBy('created_at', 'desc')->get();
        } else {
            $users = $query->orderBy('created_at', 'desc')
                ->paginate(10);
        }


        $users = $this->addUsersExtraInfo($users);

        if ($is_export_excel) {
            return $users;
        }

        $data = [
            'pageTitle' => trans('admin/main.organizations'),
            'users' => $users,
            'totalOrganizations' => $totalOrganizations,
            'verifiedOrganizations' => $verifiedOrganizations,
            'totalOrganizationsTeachers' => $totalOrganizationsTeachers,
            'totalOrganizationsStudents' => $totalOrganizationsStudents,
            'userGroups' => $userGroups,
        ];

        return view('admin.users.organizations', $data);
    }

    public function students(Request $request, $is_export_excel = false)
    {
        $this->authorize('admin_users_list');

        $query = User::where('role_name', Role::$user);

        $totalStudents = deepClone($query)->count();
        $inactiveStudents = deepClone($query)->where('status', 'inactive')
            ->count();
        $banStudents = deepClone($query)->where('ban', true)
            ->whereNotNull('ban_end_at')
            ->where('ban_end_at', '>', time())
            ->count();

        $totalOrganizationsStudents = User::where('role_name', Role::$user)
            ->whereNotNull('organ_id')
            ->count();
        $userGroups = Group::where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();

        $organizations = User::select('id', 'full_name', 'created_at')
            ->where('role_name', Role::$organization)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalClassesPurchased = Sale::whereNull('refund_at')
            ->where('type', 'webinar')
            ->count();
        $totalClassesPurchasedSum = Sale::whereNull('refund_at')
            ->whereNotNull('webinar_id')
            ->where('amount', '>', 0)
            ->sum('amount');

        $query = $this->filters($query, $request);

        if ($is_export_excel) {
            $users = $query->orderBy('created_at', 'desc')->get();
        } else {
            $users = $query->orderBy('created_at', 'desc')
                ->paginate(10);
        }

        $users = $this->addUsersExtraInfo($users);

        if ($is_export_excel) {
            return $users;
        }

        $data = [
            'pageTitle' => trans('public.students'),
            'users' => $users,
            'totalStudents' => $totalStudents,
            'inactiveStudents' => $inactiveStudents,
            'banStudents' => $banStudents,
            'totalOrganizationsStudents' => $totalOrganizationsStudents,
            'userGroups' => $userGroups,
            'organizations' => $organizations,
            'totalClassesPurchased' => $totalClassesPurchased,
            'totalClassesPurchasedSum' => $totalClassesPurchasedSum,
        ];

        return view('admin.users.students', $data);
    }

    public function instructors(Request $request, $is_export_excel = false)
    {
        $this->authorize('admin_instructors_list');

        $query = User::where('role_name', Role::$teacher);

        $totalInstructors = deepClone($query)->count();
        $inactiveInstructors = deepClone($query)->where('status', 'inactive')
            ->count();
        $banInstructors = deepClone($query)->where('ban', true)
            ->whereNotNull('ban_end_at')
            ->where('ban_end_at', '>', time())
            ->count();

        $totalOrganizationsInstructors = User::where('role_name', Role::$teacher)
            ->whereNotNull('organ_id')
            ->count();
        $userGroups = Group::where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();

        $organizations = User::select('id', 'full_name', 'created_at')
            ->where('role_name', Role::$organization)
            ->orderBy('created_at', 'desc')
            ->get();


        $query = $this->filters($query, $request);

        if ($is_export_excel) {
            $users = $query->orderBy('created_at', 'desc')->get();
        } else {
            $users = $query->orderBy('created_at', 'desc')
                ->paginate(10);
        }

        $users = $this->addUsersExtraInfo($users);

        if ($is_export_excel) {
            return $users;
        }

        $data = [
            'pageTitle' => trans('admin/main.instructors'),
            'users' => $users,
            'totalInstructors' => $totalInstructors,
            'inactiveInstructors' => $inactiveInstructors,
            'banInstructors' => $banInstructors,
            'totalOrganizationsInstructors' => $totalOrganizationsInstructors,
            'userGroups' => $userGroups,
            'organizations' => $organizations,
        ];

        return view('admin.users.instructors', $data);
    }

    private function addUsersExtraInfo($users)
    {
        // Optimize: Batch load all data to avoid N+1 queries
        $userIds = $users->pluck('id')->toArray();
        
        if (empty($userIds)) {
            return $users;
        }
        
        // Batch load all sales data for sellers
        $allSales = Sale::whereIn('seller_id', $userIds)
            ->whereNull('refund_at')
            ->select('seller_id', 'webinar_id', 'meeting_id', 'promotion_id', 'subscribe_id', 'total_amount')
            ->get()
            ->groupBy('seller_id');
        
        // Batch load all purchases
        $allPurchases = Sale::whereIn('buyer_id', $userIds)
            ->whereNull('refund_at')
            ->where('access_to_purchased_item', true)
            ->select('buyer_id', 'webinar_id', 'meeting_id', 'promotion_id', 'subscribe_id', 'total_amount', 'type', 'payment_method')
            ->get()
            ->groupBy('buyer_id');
        
        // Batch load all meetings
        $allMeetings = Meeting::whereIn('creator_id', $userIds)
            ->select('id', 'creator_id')
            ->get()
            ->groupBy('creator_id');
        
        $meetingIds = $allMeetings->flatten()->pluck('id')->toArray();
        
        // Batch load reserve meetings with sales
        $allReserveMeetings = collect();
        if (!empty($meetingIds)) {
            $reserveMeetingsData = ReserveMeeting::whereIn('meeting_id', $meetingIds)
                ->with(['sale' => function($q) {
                    $q->select('id', 'refund_at');
                }])
                ->select('id', 'meeting_id', 'status', 'paid_amount')
                ->get();
            
            // Group by creator_id
            foreach ($reserveMeetingsData as $rm) {
                foreach ($allMeetings as $creatorId => $meetings) {
                    if ($meetings->contains('id', $rm->meeting_id)) {
                        if (!isset($allReserveMeetings[$creatorId])) {
                            $allReserveMeetings[$creatorId] = collect();
                        }
                        $allReserveMeetings[$creatorId]->push($rm);
                        break;
                    }
                }
            }
        }
        
        // Batch load all active SubscribeUses
        $allActiveSubscribeUses = SubscribeUse::whereIn('user_id', $userIds)
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '>', time());
            })
            ->select('id', 'user_id', 'webinar_id', 'subscribe_id', 'sale_id', 'installment_order_id')
            ->get()
            ->groupBy('user_id');
        
        // Batch load all subscription sales with subscribe relationship
        $allSubscribeSales = Sale::whereIn('buyer_id', $userIds)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->with(['subscribe' => function($q) {
                $q->select('id', 'days');
            }])
            ->select('id', 'buyer_id', 'subscribe_id', 'created_at', 'custom_expiration_date')
            ->get()
            ->groupBy('buyer_id');
        
        // Process each user using pre-loaded data
        foreach ($users as $user) {
            // Classes sales
            $userSales = $allSales->get($user->id, collect());
            $classesSales = $userSales->filter(function($sale) {
                return !empty($sale->webinar_id) && 
                       empty($sale->meeting_id) && 
                       empty($sale->promotion_id) && 
                       empty($sale->subscribe_id);
            });
            
            $user->classesSalesCount = $classesSales->count();
            $user->classesSalesSum = $classesSales->sum('total_amount');
            
            // Meetings sales
            $reserveMeetings = $allReserveMeetings->get($user->id, collect())
                ->filter(function($rm) {
                    return ($rm->sale && empty($rm->sale->refund_at)) || 
                           ($rm->status == 'canceled' && $rm->sale);
                });
            
            $user->meetingsSalesCount = $reserveMeetings->count();
            $user->meetingsSalesSum = $reserveMeetings->sum('paid_amount');
            
            // Purchases
            $userPurchases = $allPurchases->get($user->id, collect());
            $classesPurchases = $userPurchases->filter(function($sale) {
                return $sale->type == 'webinar';
            });
            
            // Non-subscription purchases
            $nonSubscriptionPurchases = $classesPurchases->filter(function($sale) {
                return $sale->payment_method != Sale::$subscribe;
            });
            
            $nonSubscriptionCount = $nonSubscriptionPurchases->count();
            $nonSubscriptionSum = $nonSubscriptionPurchases->sum('total_amount');
            
            // Active subscription purchases
            $userSubscribeUses = $allActiveSubscribeUses->get($user->id, collect());
            $userSubscribeSales = $allSubscribeSales->get($user->id, collect());
            
            // Group subscribe sales by subscribe_id for quick lookup (get latest active sale for each subscription)
            $subscribeSalesBySubscribeId = $userSubscribeSales->groupBy('subscribe_id')
                ->map(function($sales) {
                    return $sales->sortByDesc('created_at')->first();
                });
            
            // Track unique active webinars (matching user panel logic)
            $activeSubscribeUseWebinarIds = [];
            
            foreach ($userSubscribeUses as $use) {
                // Check if SubscribeUse is linked to an active subscription sale
                $subscribeSale = null;
                
                // First, try to find the sale by the SubscribeUse's sale_id
                if ($use->sale_id) {
                    $subscribeSale = $userSubscribeSales->first(function($sale) use ($use) {
                        return $sale->id == $use->sale_id;
                    });
                }
                
                // If not found, get the latest active sale for this subscribe_id
                if (!$subscribeSale) {
                    $subscribeSale = $subscribeSalesBySubscribeId->get($use->subscribe_id);
                }
                
                if ($subscribeSale && $subscribeSale->subscribe) {
                    // Use the same expiration logic as getActiveSubscribes()
                    // Honor custom_expiration_date if set (could be from renewal extension)
                    $subscribe = $subscribeSale->subscribe;
                    $saleCreatedAt = $subscribeSale->created_at;
                    $daysSincePurchase = (int)diffTimestampDay(time(), $saleCreatedAt);
                    $calculatedExpiration = $saleCreatedAt + ($subscribe->days * 86400);
                    $maxReasonableExpiration = $saleCreatedAt + (($subscribe->days * 3) + 7) * 86400;
                    
                    $isExpired = false;
                    if (!empty($subscribeSale->custom_expiration_date)) {
                        if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                            $effectiveExpiration = $calculatedExpiration;
                        } else {
                            $effectiveExpiration = $subscribeSale->custom_expiration_date;
                        }
                        $isExpired = $effectiveExpiration <= time();
                    } else {
                        $isExpired = $subscribe->days > 0 && $subscribe->days <= $daysSincePurchase;
                    }
                    
                    // Check if subscription is still active (not expired)
                    if (!$isExpired) {
                        // Only count unique webinars
                        if ($use->webinar_id && !in_array($use->webinar_id, $activeSubscribeUseWebinarIds)) {
                            $activeSubscribeUseWebinarIds[] = $use->webinar_id;
                        }
                    }
                }
            }
            
            // Count unique active subscription webinars
            $activeSubscriptionCount = count($activeSubscribeUseWebinarIds);
            
            $user->classesPurchasedsCount = $nonSubscriptionCount + $activeSubscriptionCount;
            
            // Calculate subscription sum
            $activeSubscriptionSum = $classesPurchases
                ->filter(function($sale) use ($activeSubscribeUseWebinarIds) {
                    return $sale->payment_method == Sale::$subscribe && 
                           in_array($sale->webinar_id, $activeSubscribeUseWebinarIds);
                })
                ->sum('total_amount');
            
            $user->classesPurchasedsSum = $nonSubscriptionSum + $activeSubscriptionSum;
            
            // Meetings purchased
            $meetingsPurchased = $userPurchases->filter(function($sale) {
                return !empty($sale->meeting_id) && 
                       empty($sale->webinar_id) && 
                       empty($sale->promotion_id) && 
                       empty($sale->subscribe_id);
            });
            
            $user->meetingsPurchasedsCount = $meetingsPurchased->count();
            $user->meetingsPurchasedsSum = $meetingsPurchased->sum('total_amount');
        }

        return $users;
    }

    private function filters($query, $request)
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $full_name = $request->get('full_name');
        $sort = $request->get('sort');
        $group_id = $request->get('group_id');
        $status = $request->get('status');
        $role_id = $request->get('role_id');
        $organization_id = $request->get('organization_id');

        $query = fromAndToDateFilter($from, $to, $query, 'created_at');

        if (!empty($full_name)) {
            $query->where(function($query) use ($full_name) {
                $query->where('full_name', 'like', "%$full_name%")
                      ->orWhere('email', 'like', "%$full_name%")
                      ->orWhere('mobile', 'like', "%$full_name%");
            });
        }

        if (!empty($sort)) {
            switch ($sort) {
                case 'sales_classes_asc':
                    $query->join('sales', 'users.id', '=', 'sales.seller_id')
                        ->select('users.*', 'sales.seller_id', 'sales.webinar_id', 'sales.refund_at', DB::raw('count(sales.seller_id) as sales_count'))
                        ->whereNotNull('sales.webinar_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.seller_id')
                        ->orderBy('sales_count', 'asc');
                    break;
                case 'sales_classes_desc':
                    $query->join('sales', 'users.id', '=', 'sales.seller_id')
                        ->select('users.*', 'sales.seller_id', 'sales.webinar_id', 'sales.refund_at', DB::raw('count(sales.seller_id) as sales_count'))
                        ->whereNotNull('sales.webinar_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.seller_id')
                        ->orderBy('sales_count', 'desc');
                    break;
                case 'purchased_classes_asc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.refund_at', DB::raw('count(sales.buyer_id) as purchased_count'))
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.buyer_id')
                        ->orderBy('purchased_count', 'asc');
                    break;
                case 'purchased_classes_desc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.refund_at', DB::raw('count(sales.buyer_id) as purchased_count'))
                        ->groupBy('sales.buyer_id')
                        ->whereNull('sales.refund_at')
                        ->orderBy('purchased_count', 'desc');
                    break;
                case 'purchased_classes_amount_asc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.amount', 'sales.refund_at', DB::raw('sum(sales.amount) as purchased_amount'))
                        ->groupBy('sales.buyer_id')
                        ->whereNull('sales.refund_at')
                        ->orderBy('purchased_amount', 'asc');
                    break;
                case 'purchased_classes_amount_desc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.amount', 'sales.refund_at', DB::raw('sum(sales.amount) as purchased_amount'))
                        ->groupBy('sales.buyer_id')
                        ->whereNull('sales.refund_at')
                        ->orderBy('purchased_amount', 'desc');
                    break;
                case 'sales_appointments_asc':
                    $query->join('sales', 'users.id', '=', 'sales.seller_id')
                        ->select('users.*', 'sales.seller_id', 'sales.meeting_id', 'sales.refund_at', DB::raw('count(sales.seller_id) as sales_count'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.seller_id')
                        ->orderBy('sales_count', 'asc');
                    break;
                case 'sales_appointments_desc':
                    $query->join('sales', 'users.id', '=', 'sales.seller_id')
                        ->select('users.*', 'sales.seller_id', 'sales.meeting_id', 'sales.refund_at', DB::raw('count(sales.seller_id) as sales_count'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.seller_id')
                        ->orderBy('sales_count', 'desc');
                    break;
                    break;
                case 'purchased_appointments_asc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.meeting_id', 'sales.refund_at', DB::raw('count(sales.buyer_id) as purchased_count'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.buyer_id')
                        ->orderBy('purchased_count', 'asc');
                    break;
                case 'purchased_appointments_desc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.meeting_id', 'sales.refund_at', DB::raw('count(sales.buyer_id) as purchased_count'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.buyer_id')
                        ->orderBy('purchased_count', 'desc');
                    break;
                case 'purchased_appointments_amount_asc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.amount', 'sales.meeting_id', 'sales.refund_at', DB::raw('sum(sales.amount) as purchased_amount'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.buyer_id')
                        ->orderBy('purchased_amount', 'asc');
                    break;
                case 'purchased_appointments_amount_desc':
                    $query->join('sales', 'users.id', '=', 'sales.buyer_id')
                        ->select('users.*', 'sales.buyer_id', 'sales.amount', 'sales.meeting_id', 'sales.refund_at', DB::raw('sum(sales.amount) as purchased_amount'))
                        ->whereNotNull('sales.meeting_id')
                        ->whereNull('sales.refund_at')
                        ->groupBy('sales.buyer_id')
                        ->orderBy('purchased_amount', 'desc');
                    break;
                case 'register_asc':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'register_desc':
                    $query->orderBy('created_at', 'desc');
                    break;
            }
        }

        if (!empty($group_id)) {
            $userIds = GroupUser::where('group_id', $group_id)->pluck('user_id')->toArray();

            $query->whereIn('id', $userIds);
        }

        if (!empty($status)) {
            switch ($status) {
                case 'active_verified':
                    $query->where('status', 'active')
                        ->where('verified', true);
                    break;
                case 'active_notVerified':
                    $query->where('status', 'active')
                        ->where('verified', false);
                    break;
                case 'inactive':
                    $query->where('status', 'inactive');
                    break;
                case 'ban':
                    $query->where('ban', true)
                        ->whereNotNull('ban_end_at')
                        ->where('ban_end_at', '>', time());
                    break;
            }
        }

        if (!empty($role_id)) {
            $query->where('role_id', $role_id);
        }

        if (!empty($organization_id)) {
            $query->where('organ_id', $organization_id);
        }

        //dd($query->get());
        return $query;
    }

    public function create()
    {
        $this->authorize('admin_users_create');

        $roles = Role::orderBy('created_at', 'desc')->get();
        $userGroups = Group::orderBy('created_at', 'desc')->where('status', 'active')->get();

        $data = [
            'pageTitle' => trans('admin/main.user_new_page_title'),
            'roles' => $roles,
            'userGroups' => $userGroups,
        ];


        return view('admin.users.create', $data);
    }

    private function username($data)
    {
        $email_regex = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/i";

        $username = 'mobile';
        if (preg_match($email_regex, request('username', null))) {
            $username = 'email';
        }

        return $username;
    }

    public function store(Request $request)
    {
        $this->authorize('admin_users_create');
        $data = $request->all();

        $username = $this->username($data);
        $data[$username] = $data['username'];
        $request->merge([$username => $data['username']]);
        unset($data['username']);

        $this->validate($request, [
            $username => ($username == 'mobile') ? 'required|numeric|unique:users' : 'required|string|email|max:255|unique:users',
            'full_name' => 'required|min:3|max:128',
            'role_id' => 'required|exists:roles,id',
            'password' => 'required|string|min:6',
            'status' => 'required',
        ]);

        if (!empty($data['role_id'])) {
            $role = Role::find($data['role_id']);

            if (!empty($role)) {
                $referralSettings = getReferralSettings();
                $usersAffiliateStatus = (!empty($referralSettings) and !empty($referralSettings['users_affiliate_status']));


                $user = User::create([
                    'full_name' => $data['full_name'],
                    'role_name' => $role->name,
                    'role_id' => $data['role_id'],
                    $username => $data[$username],
                    'password' => User::generatePassword($data['password']),
                    'status' => $data['status'],
                    'affiliate' => $usersAffiliateStatus,
                    'verified' => true,
                    'created_at' => time(),
                ]);

                // Create affiliate code if user is made an affiliate
                if ($user->affiliate) {
                    $code = mt_rand(100000, 999999);
                    
                    // Ensure unique code
                    while (AffiliateCode::where('code', $code)->exists()) {
                        $code = mt_rand(100000, 999999);
                    }
                    
                    AffiliateCode::create([
                        'user_id' => $user->id,
                        'code' => $code,
                        'created_at' => time()
                    ]);
                }

                if (!empty($data['group_id'])) {
                    $group = Group::find($data['group_id']);

                    if (!empty($group)) {
                        GroupUser::create([
                            'group_id' => $group->id,
                            'user_id' => $user->id,
                            'created_at' => time(),
                        ]);

                        // Email notifications disabled when admin adds user to group
                        // $notifyOptions = [
                        //     '[u.g.title]' => $group->name,
                        // ];
                        // sendNotification("add_to_user_group", $notifyOptions, $user->id);
                    }
                }

                return redirect(getAdminPanelUrl() . '/users/' . $user->id . '/edit');
            }
        }

        $toastData = [
            'title' => '',
            'msg' => 'Role not find!',
            'status' => 'error'
        ];
        return back()->with(['toast' => $toastData]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::where('id', $id)
            ->with([
                'customBadges' => function ($query) {
                    $query->with('badge');
                },
                'occupations' => function ($query) {
                    $query->with('category');
                },
                'organization' => function ($query) {
                    $query->select('id', 'full_name');
                },
                'userRegistrationPackage'
            ])
            ->first();

        if (empty($user)) {
            abort(404);
        }

        if (!empty($user->location)) {
            $user->location = \Geo::getST_AsTextFromBinary($user->location);

            $user->location = \Geo::get_geo_array($user->location);
        }

        $userMetas = $user->userMetas;

        if (!empty($userMetas)) {
            foreach ($userMetas as $meta) {
                $user->{$meta->name} = $meta->value;
            }
        }

        $becomeInstructor = null;
        $becomeInstructorFormFieldValues = null;

        if (!empty($request->get('type')) and $request->get('type') == 'check_instructor_request') {
            $becomeInstructor = BecomeInstructor::where('user_id', $user->id)
                ->first();

            if (!empty($becomeInstructor)) {
                $becomeInstructorFormFieldValues = $this->getBecomeInstructorFormFieldValues($becomeInstructor);
            }
        }

        $categories = Category::where('parent_id', null)
            ->with('subCategories')
            ->get();

        $occupations = $user->occupations->pluck('category_id')->toArray();

        $userBadges = $user->getBadges(false);

        $roles = Role::all();
        $badges = Badge::all();

        $userLanguages = getGeneralSettings('user_languages');
        if (!empty($userLanguages) and is_array($userLanguages)) {
            $userLanguages = getLanguages($userLanguages);
        } else {
            $userLanguages = [];
        }


        $provinces = null;
        $cities = null;
        $districts = null;

        $countries = Region::select(DB::raw('*, ST_AsText(geo_center) as geo_center'))
            ->where('type', Region::$country)
            ->get();

        if (!empty($user->country_id)) {
            $provinces = Region::select(DB::raw('*, ST_AsText(geo_center) as geo_center'))
                ->where('type', Region::$province)
                ->where('country_id', $user->country_id)
                ->get();
        }

        if (!empty($user->province_id)) {
            $cities = Region::select(DB::raw('*, ST_AsText(geo_center) as geo_center'))
                ->where('type', Region::$city)
                ->where('province_id', $user->province_id)
                ->get();
        }

        if (!empty($user->city_id)) {
            $districts = Region::select(DB::raw('*, ST_AsText(geo_center) as geo_center'))
                ->where('type', Region::$district)
                ->where('city_id', $user->city_id)
                ->get();
        }

        $userBanks = UserBank::query()
            ->with([
                'specifications'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $userType = "organization";
        if ($user->isTeacher()) {
            $userType = "teacher";
        } elseif ($user->isUser()) {
            $userType = "user";
        }

        $formFieldsHtml = $this->getFormFieldsByUserType($request, $userType, true, $user);

        $userLoginHistories = null;

        if ($request->get('tab') == "loginHistory") {
            $userLoginHistories = UserLoginHistory::query()->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);
        }

        $data = [
            'pageTitle' => trans('admin/pages/users.edit_page_title'),
            'user' => $user,
            'userBadges' => $userBadges,
            'roles' => $roles,
            'badges' => $badges,
            'categories' => $categories,
            'occupations' => $occupations,
            'becomeInstructor' => $becomeInstructor,
            'userLanguages' => $userLanguages,
            'userRegistrationPackage' => $user->userRegistrationPackage,
            'countries' => $countries,
            'provinces' => $provinces,
            'cities' => $cities,
            'districts' => $districts,
            'userBanks' => $userBanks,
            'formFieldsHtml' => $formFieldsHtml,
            'becomeInstructorFormFieldValues' => $becomeInstructorFormFieldValues,
            'userLoginHistories' => $userLoginHistories,
        ];

        // Purchased Classes Data
        $data = array_merge($data, $this->getPurchasedClassesData($user));

        // Purchased Bundles Data
        $data = array_merge($data, $this->getPurchasedBundlesData($user));

        // Purchased Product Data
        $data = array_merge($data, $this->getPurchasedProductsData($user));

        // Subscription Plans Data
        $data = array_merge($data, $this->getSubscriptionPlansData($user));

        if (auth()->user()->can('admin_forum_topics_lists')) {
            $data['topics'] = ForumTopic::where('creator_id', $user->id)
                ->with([
                    'posts' => function ($query) {
                        $query->orderBy('created_at', 'desc');
                    },
                    'forum'
                ])
                ->withCount('posts')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('admin.users.edit', $data);
    }

    private function getBecomeInstructorFormFieldValues($becomeInstructor)
    {
        $values = [];
        $becomeInstructorFields = \App\Models\UserFormField::query()->where('become_instructor_id', $becomeInstructor->id)->get();

        foreach ($becomeInstructorFields as $becomeInstructorField) {
            $field = $becomeInstructorField->field;

            $values[$field->title] = $becomeInstructorField->value;
        }

        return $values;
    }

    private function getPurchasedClassesData($user)
    {
        $manualAddedClasses = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('webinar_id')
            ->where('sales.manual_added', true)
            ->where('sales.access_to_purchased_item', true)
            ->whereHas('webinar')
            ->with([
                'webinar'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $manualDisabledClasses = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('webinar_id')
            ->where('sales.access_to_purchased_item', false)
            ->whereHas('webinar')
            ->with([
                'webinar'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $purchasedClasses = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('webinar_id')
            ->where('sales.access_to_purchased_item', true)
            ->where('sales.manual_added', false)
            ->whereHas('webinar')
            ->with([
                'webinar'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'manualAddedClasses' => $manualAddedClasses,
            'purchasedClasses' => $purchasedClasses,
            'manualDisabledClasses' => $manualDisabledClasses,
        ];
    }

    private function getPurchasedBundlesData($user)
    {
        $manualAddedBundles = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('bundle_id')
            ->where('sales.manual_added', true)
            ->where('sales.access_to_purchased_item', true)
            ->whereHas('bundle')
            ->with([
                'bundle'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $manualDisabledBundles = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('bundle_id')
            ->where('sales.access_to_purchased_item', false)
            ->whereHas('bundle')
            ->with([
                'bundle'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $purchasedBundles = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('bundle_id')
            ->where('sales.access_to_purchased_item', true)
            ->where('sales.manual_added', false)
            ->whereHas('bundle')
            ->with([
                'bundle'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'manualAddedBundles' => $manualAddedBundles,
            'purchasedBundles' => $purchasedBundles,
            'manualDisabledBundles' => $manualDisabledBundles,
        ];
    }

    private function getPurchasedProductsData($user)
    {
        $manualAddedProducts = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('product_order_id')
            ->where('sales.manual_added', true)
            ->where('sales.access_to_purchased_item', true)
            ->whereHas('productOrder')
            ->with([
                'productOrder' => function ($query) {
                    $query->with([
                        'product'
                    ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $manualDisabledProducts = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('product_order_id')
            ->where('sales.access_to_purchased_item', false)
            ->whereHas('productOrder')
            ->with([
                'productOrder' => function ($query) {
                    $query->with([
                        'product'
                    ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $purchasedProducts = Sale::whereNull('refund_at')
            ->where('buyer_id', $user->id)
            ->whereNotNull('product_order_id')
            ->where('sales.access_to_purchased_item', true)
            ->where('sales.manual_added', false)
            ->whereHas('productOrder')
            ->with([
                'productOrder' => function ($query) {
                    $query->with([
                        'product'
                    ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'manualAddedProducts' => $manualAddedProducts,
            'purchasedProducts' => $purchasedProducts,
            'manualDisabledProducts' => $manualDisabledProducts,
        ];
    }

    private function getSubscriptionPlansData($user)
    {
        // Get all subscription sales for the user (both active and expired)
        $subscriptionSales = Sale::where('buyer_id', $user->id)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->with([
                'subscribe' => function ($query) {
                    // Don't select 'title' as it's a translated attribute, not a direct column
                    $query->select('id', 'days', 'usable_count', 'infinite_use', 'price', 'icon');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate active status and usage for each subscription
        $subscriptionPlans = [];
        foreach ($subscriptionSales as $sale) {
            if (!$sale->subscribe) {
                continue;
            }

            $subscribe = $sale->subscribe;
            $saleCreatedAt = $sale->created_at;
            $daysSincePurchase = (int)diffTimestampDay(time(), $saleCreatedAt);
            
            // Check if subscription is still active - use same logic as getActiveSubscribes()
            // Honor custom_expiration_date if it's set (could be from renewal extension)
            // But cap it at a reasonable maximum (purchase_date + subscription_days * 3) to prevent bugs
            $isActive = false;
            $calculatedExpiration = $saleCreatedAt + ($subscribe->days * 86400);
            // Allow up to 3x subscription period + small buffer (prevents false-expiring valid renewals due to drift/timezone/manual ops)
            $maxReasonableExpiration = $saleCreatedAt + (($subscribe->days * 3) + 7) * 86400;
            
            if (!empty($sale->custom_expiration_date)) {
                // Check if custom_expiration_date is within reasonable bounds
                // If it's beyond maxReasonableExpiration, it's likely a bug - use calculated expiration
                if ($sale->custom_expiration_date > $maxReasonableExpiration) {
                    // Custom expiration is unreasonably far in the future - likely a bug
                    // Use calculated expiration instead
                    $effectiveExpiration = $calculatedExpiration;
                } else {
                    // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                    $effectiveExpiration = $sale->custom_expiration_date;
                }
                $isActive = $effectiveExpiration > time();
            } else {
                // Use original logic: check if days > daysSincePurchase
                $isActive = $subscribe->days <= 0 || $subscribe->days > $daysSincePurchase;
            }
            
            // Count unique active uses for this subscription sale
            $uniqueWebinarIds = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where('subscribe_id', $subscribe->id)
                ->where('sale_id', $sale->id)
                ->where('active', true)
                ->where(function($query) {
                    $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                })
                ->whereNotNull('webinar_id')
                ->distinct()
                ->pluck('webinar_id')
                ->count();
            
            $uniqueBundleIds = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where('subscribe_id', $subscribe->id)
                ->where('sale_id', $sale->id)
                ->where('active', true)
                ->where(function($query) {
                    $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                })
                ->whereNotNull('bundle_id')
                ->distinct()
                ->pluck('bundle_id')
                ->count();
            
            $useCount = $uniqueWebinarIds + $uniqueBundleIds;
            
            $remaining = $subscribe->infinite_use ? 'Unlimited' : ($subscribe->usable_count - $useCount);
            
            // Calculate days remaining and expiration date - use custom expiration date if set, otherwise calculate from purchase date + days
            $expirationDate = null;
            $daysRemaining = 'Unlimited';
            
            if (!empty($sale->custom_expiration_date)) {
                $expirationDate = $sale->custom_expiration_date;
                $now = time();
                // Calculate days remaining: (expiration_timestamp - current_timestamp) / seconds_per_day
                // Use floor to round down (e.g., 1.9 days = 1 day remaining)
                $secondsRemaining = $expirationDate - $now;
                $daysRemaining = max(0, (int)floor($secondsRemaining / (24 * 60 * 60)));
            } elseif ($subscribe->days > 0) {
                $expirationDate = $saleCreatedAt + ($subscribe->days * 24 * 60 * 60); // Add days in seconds
                $daysRemaining = max(0, $subscribe->days - $daysSincePurchase);
            }
            
            $subscriptionPlans[] = [
                'sale' => $sale,
                'subscribe' => $subscribe,
                'isActive' => $isActive,
                'usedCount' => $useCount,
                'remaining' => $remaining,
                'daysRemaining' => $daysRemaining,
                'daysSincePurchase' => $daysSincePurchase,
                'expirationDate' => $expirationDate,
            ];
        }

        return [
            'subscriptionPlans' => $subscriptionPlans,
        ];
    }

    public function update(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);

        $this->validate($request, [
            'full_name' => 'required|min:3|max:128',
            'email' => (!empty($user->email)) ? 'required|email|unique:users,email,' . $user->id . ',id,deleted_at,NULL' : 'nullable|email|unique:users',
            'mobile' => (!empty($user->mobile)) ? 'required|numeric|unique:users,mobile,' . $user->id . ',id,deleted_at,NULL' : 'nullable|numeric|unique:users',
            'password' => 'nullable|string',
            'bio' => 'nullable|string|min:3|max:48',
            'about' => 'nullable|string|min:3',
            'certificate_additional' => 'nullable|string|max:255',
            'status' => 'required|' . Rule::in(User::$statuses),
            'ban_start_at' => 'required_if:ban,on',
            'ban_end_at' => 'required_if:ban,on',
            'referral_code' => 'nullable|exists:affiliates_codes,code'
        ]);

        $data = $request->all();

        $userOldRoleId = $user->role_id;
        $userRoleName = $user->role_name;
        $userRoleId = $user->role_id;
        $userRoleCaption = null;

        if (auth()->user()->can('admin_update_user_role_in_edit_page') and !empty($data['role_id'])) {
            $role = Role::where('id', $data['role_id'])->first();

            if (empty($role)) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => 'Selected role not exist',
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }

            $userRoleName = $role->name;
            $userRoleId = $role->id;
            $userRoleCaption = $role->caption;

            if ($user->role_id != $role->id and $role->name == Role::$teacher) {
                $becomeInstructor = BecomeInstructor::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->first();

                if (!empty($becomeInstructor)) {
                    $becomeInstructor->update([
                        'status' => 'accept'
                    ]);

                    // Send Notification
                    $becomeInstructor->sendNotificationToUser('accept');
                }
            }
        }


        $user->full_name = !empty($data['full_name']) ? $data['full_name'] : null;
        $user->role_name = $userRoleName;
        $user->role_id = $userRoleId;
        $user->timezone = $data['timezone'] ?? null;
        $user->currency = $data['currency'] ?? null;
        $user->organ_id = !empty($data['organ_id']) ? $data['organ_id'] : null;
        $user->email = !empty($data['email']) ? $data['email'] : null;
        $user->mobile = !empty($data['mobile']) ? $data['mobile'] : null;
        $user->bio = !empty($data['bio']) ? $data['bio'] : null;
        $user->about = !empty($data['about']) ? $data['about'] : null;
        $previousStatus = $user->status;
        $user->status = !empty($data['status']) ? $data['status'] : null;
        $user->language = !empty($data['language']) ? $data['language'] : null;


        if (!empty($data['password'])) {
            $user->password = User::generatePassword($data['password']);
        }

        if (!empty($data['ban']) and $data['ban'] == '1') {
            $ban_start_at = strtotime($data['ban_start_at']);
            $ban_end_at = strtotime($data['ban_end_at']);

            $user->ban = true;
            $user->ban_start_at = $ban_start_at;
            $user->ban_end_at = $ban_end_at;
        } else {
            $user->ban = false;
            $user->ban_start_at = null;
            $user->ban_end_at = null;
        }

        $user->verified = (!empty($data['verified']) and $data['verified'] == '1');

        $user->affiliate = (!empty($data['affiliate']) and $data['affiliate'] == '1');

        // Create affiliate code if user is made an affiliate
        if ($user->affiliate) {
            $existingCode = AffiliateCode::where('user_id', $user->id)->first();
            if (!$existingCode) {
                $code = mt_rand(100000, 999999);
                
                // Ensure unique code
                while (AffiliateCode::where('code', $code)->exists()) {
                    $code = mt_rand(100000, 999999);
                }
                
                AffiliateCode::create([
                    'user_id' => $user->id,
                    'code' => $code,
                    'created_at' => time()
                ]);
            }
        }

        $user->can_create_store = (!empty($data['can_create_store']) and $data['can_create_store'] == '1');

        $user->access_content = (!empty($data['access_content']) and $data['access_content'] == '1');

        $user->enable_ai_content = (!empty($data['enable_ai_content']) and $data['enable_ai_content'] == '1');

        $user->save();

        // Enforce referral capture on admin activation
        if ($previousStatus !== User::$active && $user->status === User::$active) {
            $code = $data['referral_code'] ?? null;
            if (empty($code)) {
                // fallback to stored referral_code in users_metas
                $meta = \App\Models\UserMeta::where('user_id', $user->id)->where('name', 'referral_code')->first();
                if ($meta) {
                    $code = $meta->value;
                }
            }
            if (!empty($code)) {
                $alreadyLinked = \App\Models\Affiliate::where('referred_user_id', $user->id)->exists();
                if (!$alreadyLinked) {
                    Affiliate::storeReferral($user, $code);
                }
            }
        }

        // save certificate_additional in user metas table
        $this->handleUserCertificateAdditional($user->id, $data['certificate_additional']);

        if ($userOldRoleId != $userRoleId) {
            $notifyOptions = [
                '[u.role]' => $userRoleCaption,
            ];
            sendNotification("user_role_change", $notifyOptions, $user->id);
        }

        return redirect()->back();
    }

    private function handleUserCertificateAdditional($userId, $value)
    {
        $name = 'certificate_additional';

        if (empty($value)) {
            $checkMeta = UserMeta::where('user_id', $userId)
                ->where('name', $name)
                ->first();

            if (!empty($checkMeta)) {
                $checkMeta->delete();
            }
        } else {
            UserMeta::updateOrCreate([
                'user_id' => $userId,
                'name' => $name
            ], [
                'value' => $value
            ]);
        }
    }

    public function updateImage(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);

        $user->avatar = $request->get('avatar', null);

        if (!empty($request->get('cover_img', null))) {
            $user->cover_img = $request->get('cover_img', null);
        }

        $user->save();

        return redirect()->back();
    }

    public function updateFormFields(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);

        $userType = "organization";
        if ($user->isTeacher()) {
            $userType = "teacher";
        } elseif ($user->isUser()) {
            $userType = "user";
        }

        $form = $this->getFormFieldsByType($userType);

        if (!empty($form)) {
            $errors = $this->checkFormRequiredFields($request, $form);

            if (count($errors)) {
                return redirect()->back()->withErrors($errors);
            }

            $this->storeFormFields($request->all(), $user);
        }

        return redirect()->back();
    }

    public function financialUpdate(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);
        $data = $request->all();

        $user->update([
            'identity_scan' => $data['identity_scan'],
            'address' => $data['address'],
            'financial_approval' => (!empty($data['financial_approval']) and $data['financial_approval'] == 'on'),
            'installment_approval' => (!empty($data['installment_approval']) and $data['installment_approval'] == 'on'),
            'enable_installments' => (!empty($data['enable_installments']) and $data['enable_installments'] == 'on'),
            'disable_cashback' => (!empty($data['disable_cashback']) and $data['disable_cashback'] == 'on'),
            'enable_registration_bonus' => (!empty($data['enable_registration_bonus']) and $data['enable_registration_bonus'] == 'on'),
            'registration_bonus_amount' => !empty($data['registration_bonus_amount']) ? $data['registration_bonus_amount'] : null,
        ]);

        $this->storeUserCommissions($user, $data);

        if (!empty($data['bank_id'])) {
            UserSelectedBank::query()->where('user_id', $user->id)->delete();

            $userSelectedBank = UserSelectedBank::query()->create([
                'user_id' => $user->id,
                'user_bank_id' => $data['bank_id']
            ]);

            if (!empty($data['bank_specifications'])) {
                $specificationInsert = [];

                foreach ($data['bank_specifications'] as $specificationId => $specificationValue) {
                    if (!empty($specificationValue)) {
                        $specificationInsert[] = [
                            'user_selected_bank_id' => $userSelectedBank->id,
                            'user_bank_specification_id' => $specificationId,
                            'value' => $specificationValue
                        ];
                    }
                }

                UserSelectedBankSpecification::query()->insert($specificationInsert);
            }
        }

        return redirect()->back();
    }

    private function storeUserCommissions($user, $data)
    {
        $user->commissions()->delete();

        if (!empty($data['commissions'])) {
            $insert = [];

            foreach ($data['commissions'] as $source => $commission) {
                if (!empty($commission['type']) and !empty($commission['value'])) {
                    $value = $commission['value'];

                    if ($commission['type'] == "fixed_amount") {
                        $value = convertPriceToDefaultCurrency($value);
                    }

                    $insert[] = [
                        'user_id' => $user->id,
                        'user_group_id' => null,
                        'source' => $source,
                        'type' => $commission['type'],
                        'value' => $value,
                    ];
                }
            }

            if (!empty($insert)) {
                UserCommission::query()->insert($insert);
            }
        }
    }

    public function occupationsUpdate(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);
        $data = $request->all();

        UserOccupation::where('user_id', $user->id)->delete();
        if (!empty($data['occupations'])) {

            foreach ($data['occupations'] as $category_id) {
                UserOccupation::create([
                    'user_id' => $user->id,
                    'category_id' => $category_id
                ]);
            }
        }

        return redirect()->back();
    }

    public function badgesUpdate(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $this->validate($request, [
            'badge_id' => 'required'
        ]);

        $data = $request->all();
        $user = User::findOrFail($id);
        $badge = Badge::findOrFail($data['badge_id']);

        UserBadge::create([
            'user_id' => $user->id,
            'badge_id' => $badge->id,
            'created_at' => time()
        ]);

        sendNotification('new_badge', ['[u.b.title]' => $badge->title], $user->id);

        return redirect()->back();
    }

    public function deleteBadge(Request $request, $id, $badge_id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);

        $badge = UserBadge::where('id', $badge_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($badge)) {
            $badge->delete();
        }

        return redirect()->back();
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('admin_users_delete');

        $user = User::find($id);

        if ($user) {
            $user->delete();
        }

        return redirect()->back();
    }

    public function acceptRequestToInstructor($id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);

        $becomeInstructors = BecomeInstructor::where('user_id', $user->id)->first();

        if (!empty($becomeInstructors)) {
            $role = Role::where('name', $becomeInstructors->role)->first();

            if (!empty($role)) {
                $user->update([
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                ]);

                $becomeInstructors->update([
                    'status' => 'accept'
                ]);

                // Send Notification
                $becomeInstructors->sendNotificationToUser('accept');
            }

            return redirect(getAdminPanelUrl() . '/users/' . $user->id . '/edit')->with(['msg' => trans('admin/pages/users.user_role_updated')]);
        }

        abort(404);
    }

    public function search(Request $request)
    {
        $term = $request->get('term');
        $option = $request->get('option');

        $users = User::select('id', 'full_name as name')
            //->where('role_name', Role::$user)
            ->where(function ($query) use ($term) {
                $query->where('full_name', 'like', '%' . $term . '%');
            });

        if ($option === "for_user_group") {
            // Avoid loading all group users into memory; use a subquery
            $users->whereNotIn('id', GroupUser::query()->select('user_id'));
        }

        if ($option === "just_teacher_role") {
            $users->where('role_name', Role::$teacher);
        }

        if ($option === "just_student_role") {
            $users->where('role_name', Role::$user);
        }

        if ($option === "just_organization_role") {
            $users->where('role_name', Role::$organization);
        }

        if ($option === "just_organization_and_teacher_role") {
            $users->whereIn('role_name', [Role::$organization, Role::$teacher]);
        }

        if ($option === "except_user") {
            $users->where('role_name', '!=', Role::$user);
        }

        if ($option === "consultants") {
            $users->whereHas('meeting', function ($query) {
                $query->where('disabled', false)
                    ->whereHas('meetingTimes');
            });
        }

        return response()->json($users->get(), 200);
    }

    public function impersonate($user_id)
    {
        $this->authorize('admin_users_impersonate');

        $user = User::findOrFail($user_id);

        if ($user->isAdmin()) {
            return redirect(getAdminPanelUrl() . '');
        }

        session()->put(['impersonated' => $user->id]);

        return redirect('/panel');
    }

    public function exportExcelOrganizations(Request $request)
    {
        $this->authorize('admin_users_export_excel');

        $users = $this->organizations($request, true);

        $usersExport = new OrganizationsExport($users);

        return Excel::download($usersExport, 'organizations.xlsx');
    }

    public function exportExcelInstructors(Request $request)
    {
        $this->authorize('admin_users_export_excel');

        $users = $this->instructors($request, true);

        $usersExport = new InstructorsExport($users);

        return Excel::download($usersExport, 'instructors.xlsx');
    }

    public function exportExcelStudents(Request $request)
    {
        $this->authorize('admin_users_export_excel');

        $users = $this->students($request, true);

        $usersExport = new StudentsExport($users);

        return Excel::download($usersExport, 'students.xlsx');
    }

    public function userRegistrationPackage(Request $request, $id)
    {
        $this->authorize('admin_update_user_registration_package');

        $this->validate($request, [
            'instructors_count' => 'nullable|numeric',
            'students_count' => 'nullable|numeric',
            'courses_capacity' => 'nullable|numeric',
            'courses_count' => 'nullable|numeric',
            'meeting_count' => 'nullable|numeric',
        ]);

        $user = User::findOrFail($id);

        if ($user->isOrganization() or $user->isTeacher()) {
            $data = $request->all();

            UserRegistrationPackage::updateOrCreate([
                'user_id' => $user->id,
            ], [
                'instructors_count' => $data['instructors_count'] ?? null,
                'students_count' => $data['students_count'] ?? null,
                'courses_capacity' => $data['courses_capacity'] ?? null,
                'courses_count' => $data['courses_count'] ?? null,
                'meeting_count' => $data['meeting_count'] ?? null,
                'status' => $data['status'],
                'created_at' => time(),
            ]);

            return redirect()->back();
        }

        abort(404);
    }

    public function meetingSettings(Request $request, $id)
    {
        $this->authorize('admin_update_user_meeting_settings');

        $user = User::findOrFail($id);

        if ($user->isOrganization() or $user->isTeacher()) {
            $data = $request->all();

            $user->update([
                "level_of_training" => !empty($data['level_of_training']) ? (new UserLevelOfTraining())->getValue($data['level_of_training']) : null,
                "meeting_type" => $data['meeting_type'] ?? null,
                "group_meeting" => (!empty($data['group_meeting']) and $data['group_meeting'] == 'on'),
                "country_id" => $data['country_id'] ?? null,
                "province_id" => $data['province_id'] ?? null,
                "city_id" => $data['city_id'] ?? null,
                "district_id" => $data['district_id'] ?? null,
                "location" => (!empty($data['latitude']) and !empty($data['longitude'])) ? DB::raw("POINT(" . $data['latitude'] . "," . $data['longitude'] . ")") : null,
            ]);

            $updateUserMeta = [
                "gender" => $data['gender'] ?? null,
                "age" => $data['age'] ?? null,
                "address" => $data['address'] ?? null,
            ];

            foreach ($updateUserMeta as $name => $value) {
                $checkMeta = UserMeta::where('user_id', $user->id)
                    ->where('name', $name)
                    ->first();

                if (!empty($checkMeta)) {
                    if (!empty($value)) {
                        $checkMeta->update([
                            'value' => $value
                        ]);
                    } else {
                        $checkMeta->delete();
                    }
                } else if (!empty($value)) {
                    UserMeta::create([
                        'user_id' => $user->id,
                        'name' => $name,
                        'value' => $value
                    ]);
                }
            }

            return redirect()->back();
        }

        abort(404);
    }

    public function updateSubscriptionExpiration(Request $request, $id)
    {
        $this->authorize('admin_users_edit');

        $user = User::findOrFail($id);
        
        $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'expiration_date' => 'nullable|date',
        ]);

        $sale = Sale::where('id', $request->sale_id)
            ->where('buyer_id', $user->id)
            ->where('type', Sale::$subscribe)
            ->whereNull('refund_at')
            ->firstOrFail();

        if ($request->has('expiration_date') && !empty($request->expiration_date)) {
            $expirationTimestamp = strtotime($request->expiration_date);
            $sale->custom_expiration_date = $expirationTimestamp;
        } else {
            $sale->custom_expiration_date = null;
        }

        $sale->save();

        $sale->load('subscribe');

        // Keep linked subjects in sync with the edited subscription expiration.
        // If the subscription is active after edit, reactivate linked uses.
        // If it is expired after edit, expire linked active uses.
        $effectiveExpiration = null;
        if (!empty($sale->custom_expiration_date)) {
            $effectiveExpiration = (int)$sale->custom_expiration_date;
        } elseif (!empty($sale->subscribe) && !empty($sale->subscribe->days) && $sale->subscribe->days > 0) {
            $effectiveExpiration = (int)$sale->created_at + ((int)$sale->subscribe->days * 86400);
        }

        if (!empty($sale->subscribe_id) && !empty($effectiveExpiration) && $effectiveExpiration > time()) {
            SubscribeUse::where('user_id', $user->id)
                ->where('subscribe_id', $sale->subscribe_id)
                ->where('sale_id', $sale->id)
                ->update([
                    'active' => true,
                    'expired_at' => null,
                ]);
        } elseif (!empty($sale->subscribe_id)) {
            $activeUses = SubscribeUse::where('user_id', $user->id)
                ->where('subscribe_id', $sale->subscribe_id)
                ->where('sale_id', $sale->id)
                ->where('active', true)
                ->get();

            foreach ($activeUses as $use) {
                $use->expire();
            }
        }

        if (!empty($sale->subscribe_id)) {
            \App\Models\Subscribe::clearSubscriptionCache($user->id, $sale->subscribe_id);
        }

        return response()->json([
            'success' => true,
            'message' => trans('admin/main.update_success'),
            'expiration_date' => $sale->custom_expiration_date ? dateTimeFormat($sale->custom_expiration_date, 'j M Y | H:i') : null,
        ]);
    }

    public function disableCashbackToggle($id)
    {
        $this->authorize('admin_users_edit');

        $user = User::query()->findOrFail($id);

        $user->update([
            'disable_cashback' => !$user->disable_cashback
        ]);

        $toastData = [
            'title' => trans('public.request_success'),
            'msg' => trans('update.cashback_was_disabled_for_the_user'),
            'status' => 'success'
        ];

        return back()->with(['toast' => $toastData]);
    }

    public function disableRegitrationBonusStatus($id)
    {
        $this->authorize('admin_users_edit');

        $user = User::query()->findOrFail($id);

        $user->update([
            'enable_registration_bonus' => false
        ]);

        $toastData = [
            'title' => trans('public.request_success'),
            'msg' => trans('update.registration_bonus_was_disabled_for_the_user'),
            'status' => 'success'
        ];

        return back()->with(['toast' => $toastData]);
    }

    public function disableInstallmentApproval($id)
    {
        $this->authorize('admin_users_edit');

        $user = User::query()->findOrFail($id);

        $user->update([
            'installment_approval' => false
        ]);

        $toastData = [
            'title' => trans('public.request_success'),
            'msg' => trans('update.installment_was_disabled_for_the_user'),
            'status' => 'success'
        ];

        return back()->with(['toast' => $toastData]);
    }
}
