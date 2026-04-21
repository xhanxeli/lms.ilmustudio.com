<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateApiClient;
use Illuminate\Http\Request;

class AffiliateApiController extends Controller
{
    public function index()
    {
        $this->authorize('admin_referrals_api_keys');

        $clients = AffiliateApiClient::orderBy('id', 'desc')->paginate(20);

        $data = [
            'pageTitle' => trans('admin/main.affiliate_api_keys'),
            'clients' => $clients,
        ];

        return view('admin.referrals.api_keys', $data);
    }

    public function store(Request $request)
    {
        $this->authorize('admin_referrals_api_keys');

        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        [$client, $plainSecret] = AffiliateApiClient::createWithKeys($request->get('name'));

        return redirect()->back()->with([
            'toast_success' => trans('admin/main.affiliate_api_key_created'),
            'new_credentials' => [
                'access_key' => $client->access_key,
                'secret_key' => $plainSecret,
            ],
        ]);
    }

    public function toggle($id)
    {
        $this->authorize('admin_referrals_api_keys');

        $client = AffiliateApiClient::findOrFail($id);
        $client->is_active = !$client->is_active;
        $client->save();

        return redirect()->back()->with('toast_success', trans('admin/main.affiliate_api_updated'));
    }

    public function regenerate($id)
    {
        $this->authorize('admin_referrals_api_keys');

        $client = AffiliateApiClient::findOrFail($id);
        $plainSecret = $client->rotateSecret();

        return redirect()->back()->with([
            'toast_success' => trans('admin/main.affiliate_api_secret_regenerated'),
            'new_credentials' => [
                'access_key' => $client->access_key,
                'secret_key' => $plainSecret,
            ],
        ]);
    }

    public function destroy($id)
    {
        $this->authorize('admin_referrals_api_keys');

        AffiliateApiClient::findOrFail($id)->delete();

        return redirect()->back()->with('toast_success', trans('admin/main.affiliate_api_deleted'));
    }
}
