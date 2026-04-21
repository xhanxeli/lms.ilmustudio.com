@push('styles_top')
    <link rel="stylesheet" href="/assets/default/vendors/daterangepicker/daterangepicker.min.css">
@endpush

<div class="tab-pane mt-3 fade" id="subscription_plans" role="tabpanel" aria-labelledby="subscription_plans-tab">
    <div class="row">
        <div class="col-12">
            <div class="mt-5">
                <h5 class="section-title after-line">{{ trans('financial.subscribe_plans') }}</h5>

                <div class="table-responsive mt-3">
                    <table class="table table-striped table-md">
                        <tr>
                            <th>{{ trans('admin/main.plan') }}</th>
                            <th>{{ trans('admin/main.price') }}</th>
                            <th>{{ trans('admin/main.duration') }}</th>
                            <th>{{ trans('update.usable_count') }}</th>
                            <th>{{ trans('update.used_count') }}</th>
                            <th>{{ trans('update.remaining') }}</th>
                            <th>{{ trans('update.days_remaining') }}</th>
                            <th class="text-center">{{ trans('panel.purchase_date') }}</th>
                            <th class="text-center">{{ trans('admin/main.expire_date') }}</th>
                            <th>{{ trans('admin/main.status') }}</th>
                        </tr>

                        @if(!empty($subscriptionPlans) && count($subscriptionPlans) > 0)
                            @foreach($subscriptionPlans as $planData)
                                @php
                                    $sale = $planData['sale'];
                                    $subscribe = $planData['subscribe'];
                                    $isActive = $planData['isActive'];
                                    $usedCount = $planData['usedCount'];
                                    $remaining = $planData['remaining'];
                                    $daysRemaining = $planData['daysRemaining'];
                                    $expirationDate = $planData['expirationDate'];
                                @endphp

                                <tr>
                                    <td width="25%">
                                        <strong>{{ $subscribe->title ?? trans('update.deleted_item') }}</strong>
                                        @if($subscribe->infinite_use)
                                            <span class="badge badge-success ml-1">{{ trans('update.unlimited') }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        {{ !empty($subscribe->price) ? handlePrice($subscribe->price) : '-' }}
                                    </td>

                                    <td>
                                        @if($subscribe->days > 0)
                                            {{ $subscribe->days }} days
                                        @else
                                            {{ trans('update.unlimited') }}
                                        @endif
                                    </td>

                                    <td>
                                        @if($subscribe->infinite_use)
                                            {{ trans('update.unlimited') }}
                                        @else
                                            {{ $subscribe->usable_count }}
                                        @endif
                                    </td>

                                    <td>
                                        {{ $usedCount }}
                                    </td>

                                    <td>
                                        @if($subscribe->infinite_use)
                                            {{ trans('update.unlimited') }}
                                        @else
                                            {{ $remaining }}
                                        @endif
                                    </td>

                                    <td>
                                        @if(is_numeric($daysRemaining))
                                            {{ $daysRemaining }} days
                                        @else
                                            {{ $daysRemaining }}
                                        @endif
                                    </td>

                                    <td class="text-center">{{ dateTimeFormat($sale->created_at,'j M Y | H:i') }}</td>

                                    <td class="text-center">
                                        <div class="expiration-date-wrapper" data-sale-id="{{ $sale->id }}">
                                            @if($expirationDate)
                                                <div class="expiration-date-display">
                                                    <span class="expiration-date-text">{{ dateTimeFormat($expirationDate,'j M Y | H:i') }}</span>
                                                    <button type="button" class="btn btn-sm btn-link edit-expiration-btn" title="{{ trans('admin/main.edit') }}">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                                <div class="expiration-date-edit" style="display: none;">
                                                    <input type="text" 
                                                           class="form-control form-control-sm datetimepicker expiration-date-input" 
                                                           data-format="YYYY-MM-DD HH:mm"
                                                           value="{{ date('Y-m-d H:i', $expirationDate) }}"
                                                           style="min-width: 180px; display: inline-block;">
                                                    <button type="button" class="btn btn-sm btn-success save-expiration-btn" title="{{ trans('admin/main.save') }}">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary cancel-expiration-btn" title="{{ trans('admin/main.cancel') }}">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger clear-expiration-btn" title="{{ trans('admin/main.clear') }}">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            @else
                                                <div class="expiration-date-display">
                                                    <span class="expiration-date-text">{{ trans('update.unlimited') }}</span>
                                                    <button type="button" class="btn btn-sm btn-link edit-expiration-btn" title="{{ trans('admin/main.edit') }}">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                                <div class="expiration-date-edit" style="display: none;">
                                                    <input type="text" 
                                                           class="form-control form-control-sm datetimepicker expiration-date-input" 
                                                           data-format="YYYY-MM-DD HH:mm"
                                                           value=""
                                                           style="min-width: 180px; display: inline-block;">
                                                    <button type="button" class="btn btn-sm btn-success save-expiration-btn" title="{{ trans('admin/main.save') }}">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary cancel-expiration-btn" title="{{ trans('admin/main.cancel') }}">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            @endif
                                            <div class="expiration-date-loading" style="display: none;">
                                                <i class="fas fa-spinner fa-spin"></i>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        @if($isActive)
                                            <span class="badge badge-success">{{ trans('admin/main.active') }}</span>
                                        @else
                                            <span class="badge badge-danger">Expired</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="10" class="text-center">
                                    <p class="text-gray mt-3">{{ trans('update.no_subscription_plans') }}</p>
                                </td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts_bottom')
    <script src="/assets/default/vendors/daterangepicker/daterangepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize datetime pickers
            if (typeof resetDatePickers === 'function') {
                resetDatePickers();
            } else {
                // Fallback initialization
                $('.datetimepicker').each(function() {
                    var $input = $(this);
                    var format = $input.attr('data-format') || 'YYYY-MM-DD HH:mm';
                    
                    $input.daterangepicker({
                        locale: {
                            format: format,
                            cancelLabel: 'Clear'
                        },
                        singleDatePicker: true,
                        timePicker: true,
                        timePicker24Hour: true,
                        autoUpdateInput: false,
                        drops: 'down'
                    });
                    
                    $input.on('apply.daterangepicker', function (ev, picker) {
                        $(this).val(picker.startDate.format(format));
                    });
                    
                    $input.on('cancel.daterangepicker', function (ev, picker) {
                        $(this).val('');
                    });
                });
            }

            // Edit button click
            $(document).on('click', '.edit-expiration-btn', function() {
                var $wrapper = $(this).closest('.expiration-date-wrapper');
                $wrapper.find('.expiration-date-display').hide();
                $wrapper.find('.expiration-date-edit').show();
                $wrapper.find('.expiration-date-input').focus();
            });

            // Cancel button click
            $(document).on('click', '.cancel-expiration-btn', function() {
                var $wrapper = $(this).closest('.expiration-date-wrapper');
                $wrapper.find('.expiration-date-edit').hide();
                $wrapper.find('.expiration-date-display').show();
            });

            // Save button click
            $(document).on('click', '.save-expiration-btn', function() {
                var $wrapper = $(this).closest('.expiration-date-wrapper');
                var saleId = $wrapper.attr('data-sale-id');
                var expirationDate = $wrapper.find('.expiration-date-input').val();
                var userId = {{ $user->id }};

                if (!expirationDate) {
                    alert('{{ trans("public.please_select_date") }}');
                    return;
                }

                $wrapper.find('.expiration-date-loading').show();
                $wrapper.find('.expiration-date-edit').hide();

                $.ajax({
                    url: '{{ getAdminPanelUrl() }}/users/' + userId + '/updateSubscriptionExpiration',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        sale_id: saleId,
                        expiration_date: expirationDate
                    },
                    success: function(response) {
                        if (response.success) {
                            var displayText = response.expiration_date || '{{ trans("update.unlimited") }}';
                            $wrapper.find('.expiration-date-text').text(displayText);
                            $wrapper.find('.expiration-date-display').show();
                            
                            // Show success message
                            if (typeof toastr !== 'undefined') {
                                toastr.success(response.message || '{{ trans("admin/main.save_change") }}');
                            }
                        } else {
                            alert(response.message || '{{ trans("public.request_failed") }}');
                            $wrapper.find('.expiration-date-edit').show();
                        }
                        $wrapper.find('.expiration-date-loading').hide();
                    },
                    error: function(xhr) {
                        var errorMsg = '{{ trans("public.request_failed") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        alert(errorMsg);
                        $wrapper.find('.expiration-date-edit').show();
                        $wrapper.find('.expiration-date-loading').hide();
                    }
                });
            });

            // Clear expiration date button click
            $(document).on('click', '.clear-expiration-btn', function() {
                if (!confirm('{{ trans("public.are_you_sure") }}')) {
                    return;
                }

                var $wrapper = $(this).closest('.expiration-date-wrapper');
                var saleId = $wrapper.attr('data-sale-id');
                var userId = {{ $user->id }};

                $wrapper.find('.expiration-date-loading').show();
                $wrapper.find('.expiration-date-edit').hide();

                $.ajax({
                    url: '{{ getAdminPanelUrl() }}/users/' + userId + '/updateSubscriptionExpiration',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        sale_id: saleId,
                        expiration_date: ''
                    },
                    success: function(response) {
                        if (response.success) {
                            $wrapper.find('.expiration-date-text').text('{{ trans("update.unlimited") }}');
                            $wrapper.find('.expiration-date-display').show();
                            
                            if (typeof toastr !== 'undefined') {
                                toastr.success(response.message || '{{ trans("admin/main.save_change") }}');
                            }
                        } else {
                            alert(response.message || '{{ trans("public.request_failed") }}');
                            $wrapper.find('.expiration-date-edit').show();
                        }
                        $wrapper.find('.expiration-date-loading').hide();
                    },
                    error: function(xhr) {
                        var errorMsg = '{{ trans("public.request_failed") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        alert(errorMsg);
                        $wrapper.find('.expiration-date-edit').show();
                        $wrapper.find('.expiration-date-loading').hide();
                    }
                });
            });
        });
    </script>
@endpush
