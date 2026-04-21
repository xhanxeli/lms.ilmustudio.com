<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupRegistrationPackage;
use App\Models\GroupUser;
use App\Models\UserCommission;
use App\User;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('admin_group_list');

        $groups = Group::query();
        $filters = $request->input('filters');
        
        // Support both filters[group_name] and direct name parameter
        $groupName = $request->input('name') ?? $filters['group_name'] ?? '';

        if (!empty($groupName)) {
            $groups = $groups->where('name', 'like', '%' . $groupName . '%');
        }

        $data = [
            'pageTitle' => trans('admin/pages/groups.group_list_page_title'),
            'groups' => $groups->paginate(10),
            'group_name' => $groupName,
        ];

        return view('admin.users.groups.lists', $data);
    }

    public function create()
    {
        $this->authorize('admin_group_create');

        $data = [
            'pageTitle' => trans('admin/main.group_new_page_title'),
        ];

        return view('admin.users.groups.new', $data);
    }

    public function store(Request $request)
    {
        $this->authorize('admin_group_create');

        $this->validate($request, [
            'users' => 'required|array',
            'name' => 'required',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|in:active,inactive',
            'commissions' => 'nullable|array',
        ]);

        // Important: do NOT mass-assign arrays like users[]/commissions[...] into Group model
        $groupData = $request->only(['name', 'discount', 'status']);
        $groupData['created_at'] = time();
        $groupData['creator_id'] = auth()->user()->id;

        $group = Group::create($groupData);

        // Store commissions (if provided on create form)
        $this->storeUserCommissions($group, $request->all());

        $users = $request->input('users');

        if (!empty($users)) {
            foreach ($users as $userId) {
                if (GroupUser::where('user_id', $userId)->first()) {
                    continue;
                }

                GroupUser::create([
                    'group_id' => $group->id,
                    'user_id' => $userId,
                    'created_at' => time(),
                ]);

                // Email notifications disabled when admin creates group
                // $notifyOptions = [
                //     '[u.g.title]' => $group->name,
                // ];
                // sendNotification('change_user_group', $notifyOptions, $userId);
                // sendNotification('add_to_user_group', $notifyOptions, $userId);
            }
        }

        return redirect(getAdminPanelUrl() . '/users/groups');
    }

    public function edit($id)
    {
        $this->authorize('admin_group_edit');

        $group = Group::findOrFail($id);

        $userGroups = GroupUser::where('group_id', $id)
            ->with(['user' => function ($query) {
                $query->select('id', 'full_name');
            }])
            ->get();

        $data = [
            'pageTitle' => trans('admin/pages/groups.edit_page_title'),
            'group' => $group,
            'userGroups' => $userGroups,
            'groupRegistrationPackage' => $group->groupRegistrationPackage
        ];

        return view('admin.users.groups.new', $data);
    }

    public function update(Request $request, $id)
    {
        $this->authorize('admin_group_edit');

        $this->validate($request, [
            'users' => 'required|array',
            'name' => 'required',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|in:active,inactive',
            'commissions' => 'nullable|array',
        ]);

        $group = Group::findOrFail($id);
        $data = $request->all();

        // Store Commissions
        $this->storeUserCommissions($group, $data);

        // Important: do NOT mass-assign arrays like users[]/commissions[...] into Group model
        $groupData = $request->only(['name', 'discount', 'status']);
        $group->update($groupData);

        $users = $request->input('users');

        $group->groupUsers()->delete();

        if (!empty($users)) {
            foreach ($users as $userId) {
                GroupUser::create([
                    'group_id' => $group->id,
                    'user_id' => $userId,
                    'created_at' => time(),
                ]);

                // Email notifications disabled when admin updates group
                // $notifyOptions = [
                //     '[u.g.title]' => $group->name,
                // ];
                // sendNotification('change_user_group', $notifyOptions, $userId);
                // sendNotification('add_to_user_group', $notifyOptions, $userId);
            }
        }

        return redirect(getAdminPanelUrl() . '/users/groups');
    }

    private function storeUserCommissions($group, $data)
    {
        $group->commissions()->delete();

        if (!empty($data['commissions'])) {
            $insert = [];

            foreach ($data['commissions'] as $source => $commission) {
                if (!empty($commission['type']) and !empty($commission['value'])) {
                    $value = $commission['value'];

                    if ($commission['type'] == "fixed_amount") {
                        $value = convertPriceToDefaultCurrency($value);
                    }

                    $insert[] = [
                        'user_id' => null,
                        'user_group_id' => $group->id,
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

    public function destroy(Request $request, $id)
    {
        $this->authorize('admin_group_delete');

        Group::find($id)->delete();

        return redirect(getAdminPanelUrl() . '/users/groups');
    }

    public function groupRegistrationPackage(Request $request, $id)
    {
        $this->validate($request, [
            'instructors_count' => 'nullable|numeric',
            'students_count' => 'nullable|numeric',
            'courses_capacity' => 'nullable|numeric',
            'courses_count' => 'nullable|numeric',
            'meeting_count' => 'nullable|numeric',
        ]);

        $group = Group::findOrFail($id);

        $data = $request->all();

        GroupRegistrationPackage::updateOrCreate([
            'group_id' => $group->id,
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
}
