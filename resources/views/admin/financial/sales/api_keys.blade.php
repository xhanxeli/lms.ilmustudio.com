@extends('admin.layouts.app')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>{{ trans('admin/main.sales_api_keys') }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="{{ getAdminPanelUrl() }}">{{ trans('admin/main.dashboard') }}</a></div>
                <div class="breadcrumb-item"><a href="{{ getAdminPanelUrl() }}/financial/sales">{{ trans('admin/main.sales') }}</a></div>
                <div class="breadcrumb-item">{{ trans('admin/main.sales_api_keys') }}</div>
            </div>
        </div>

        <div class="section-body">
            @if(session('toast_success'))
                <div class="alert alert-success">{{ session('toast_success') }}</div>
            @endif

            @if(!empty(session('new_credentials')))
                @php $c = session('new_credentials'); @endphp
                <div class="alert alert-warning">
                    <strong>{{ trans('admin/main.sales_api_copy_once') }}</strong>
                    <div class="mt-2">
                        <div class="mb-1"><span class="font-weight-bold">{{ trans('admin/main.sales_api_access_key') }}:</span>
                            <code class="user-select-all">{{ $c['access_key'] }}</code></div>
                        <div><span class="font-weight-bold">{{ trans('admin/main.sales_api_secret_key') }}:</span>
                            <code class="user-select-all">{{ $c['secret_key'] }}</code></div>
                    </div>
                </div>
            @endif

            <div class="row">
                <div class="col-12 col-lg-5 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h4>{{ trans('admin/main.sales_api_create') }}</h4>
                        </div>
                        <form method="post" action="{{ getAdminPanelUrl() }}/financial/sales/api-access/store" class="card-body">
                            {{ csrf_field() }}
                            <div class="form-group">
                                <label>{{ trans('admin/main.sales_api_optional_name') }}</label>
                                <input type="text" name="name" class="form-control" maxlength="255" placeholder="{{ trans('admin/main.sales_api_optional_name') }}">
                            </div>
                            <button type="submit" class="btn btn-primary">{{ trans('admin/main.sales_api_create') }}</button>
                        </form>
                    </div>
                </div>

                <div class="col-12 col-lg-7 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h4>{{ trans('admin/main.sales_api_endpoints') }}</h4>
                        </div>
                        <div class="card-body font-14">
                            <p class="mb-2">{{ trans('admin/main.sales_api_headers') }}</p>
                            <p class="mb-1"><code>GET {{ url('/api/v1/sales') }}</code></p>
                            <p class="text-muted mb-2">{{ trans('admin/main.sales_api_query_hint') }}</p>
                            <ul class="mb-0 pl-3">
                                <li><code>per_page</code>, <code>page</code></li>
                                <li><code>from</code>, <code>to</code></li>
                                <li><code>status</code> — <span class="text-muted">success | refund | blocked</span></li>
                                <li><code>item_title</code> — <span class="text-muted">sale id / user email / item title / affiliate</span></li>
                                <li><code>teacher_ids[]</code>, <code>student_ids[]</code>, <code>webinar_ids[]</code></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped font-14 mb-0">
                            <tr>
                                <th>{{ trans('admin/main.id') }}</th>
                                <th>{{ trans('admin/main.name') }}</th>
                                <th>{{ trans('admin/main.sales_api_access_key') }}</th>
                                <th>{{ trans('admin/main.sales_api_status') }}</th>
                                <th>{{ trans('admin/main.sales_api_last_used') }}</th>
                                <th>{{ trans('admin/main.actions') }}</th>
                            </tr>
                            <tbody>
                            @foreach($clients as $client)
                                <tr>
                                    <td>{{ $client->id }}</td>
                                    <td>{{ $client->name ?: '—' }}</td>
                                    <td><code class="user-select-all">{{ $client->access_key }}</code></td>
                                    <td>
                                        @if($client->is_active)
                                            <span class="badge badge-success">{{ trans('admin/main.sales_api_active') }}</span>
                                        @else
                                            <span class="badge badge-secondary">{{ trans('admin/main.sales_api_inactive') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(!empty($client->last_used_at))
                                            {{ dateTimeFormat($client->last_used_at, 'Y M j H:i') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="{{ getAdminPanelUrl() }}/financial/sales/api-access/{{ $client->id }}/toggle" class="btn btn-sm btn-outline-primary">{{ trans('admin/main.status') }}</a>
                                        <a href="{{ getAdminPanelUrl() }}/financial/sales/api-access/{{ $client->id }}/regenerate"
                                           class="btn btn-sm btn-outline-warning"
                                           onclick="return confirm('{{ trans('admin/main.sales_api_regenerate_confirm') }}');">{{ trans('admin/main.sales_api_regenerate') }}</a>
                                        <a href="{{ getAdminPanelUrl() }}/financial/sales/api-access/{{ $client->id }}/delete"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('{{ trans('admin/main.delete_confirm_msg') }}');">{{ trans('admin/main.delete') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($clients->hasPages())
                    <div class="card-footer text-center">
                        {{ $clients->appends(request()->input())->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection

