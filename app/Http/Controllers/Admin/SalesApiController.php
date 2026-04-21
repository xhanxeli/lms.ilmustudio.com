<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesApiClient;
use Illuminate\Http\Request;

class SalesApiController extends Controller
{
    public function index()
    {
        $this->authorize('admin_sales_list');

        $clients = SalesApiClient::orderBy('id', 'desc')->paginate(20);

        return view('admin.financial.sales.api_keys', [
            'pageTitle' => trans('admin/main.sales_api_keys'),
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('admin_sales_list');

        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        [$client, $plainSecret] = SalesApiClient::createWithKeys($request->get('name'));

        return redirect()->back()->with([
            'toast_success' => trans('admin/main.sales_api_key_created'),
            'new_credentials' => [
                'access_key' => $client->access_key,
                'secret_key' => $plainSecret,
            ],
        ]);
    }

    public function toggle($id)
    {
        $this->authorize('admin_sales_list');

        $client = SalesApiClient::findOrFail($id);
        $client->is_active = !$client->is_active;
        $client->save();

        return redirect()->back()->with('toast_success', trans('admin/main.sales_api_updated'));
    }

    public function regenerate($id)
    {
        $this->authorize('admin_sales_list');

        $client = SalesApiClient::findOrFail($id);
        $plainSecret = $client->rotateSecret();

        return redirect()->back()->with([
            'toast_success' => trans('admin/main.sales_api_secret_regenerated'),
            'new_credentials' => [
                'access_key' => $client->access_key,
                'secret_key' => $plainSecret,
            ],
        ]);
    }

    public function destroy($id)
    {
        $this->authorize('admin_sales_list');

        SalesApiClient::findOrFail($id)->delete();

        return redirect()->back()->with('toast_success', trans('admin/main.sales_api_deleted'));
    }
}

