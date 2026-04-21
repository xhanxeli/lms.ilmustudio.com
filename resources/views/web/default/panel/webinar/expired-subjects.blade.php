@extends(getTemplate() . '.panel.layouts.panel_layout')

@push('styles_top')
    <link rel="stylesheet" href="/assets/default/vendors/daterangepicker/daterangepicker.min.css">
@endpush

@section('content')
    <section>
        <div class="d-flex align-items-start align-items-md-center justify-content-between flex-column flex-md-row">
            <h2 class="section-title">{{ trans('panel.my_expired_subject') }}</h2>
        </div>

        <div class="mt-25 mb-25">
            <form method="get" action="{{ url('/panel/webinars/purchases/expired-subjects') }}" class="d-flex align-items-center flex-wrap">
                <div class="form-group mb-0 mr-2" style="min-width: 250px;">
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           value="{{ $search ?? '' }}" 
                           placeholder="Search subject name">
                </div>
                
                <button type="submit" class="btn btn-primary mr-2">
                    <i class="fa fa-search"></i> {{ trans('public.search') }}
                </button>
                
                @if(!empty($search))
                    <a href="{{ url('/panel/webinars/purchases/expired-subjects') }}" class="btn btn-secondary">
                        <i class="fa fa-times"></i> {{ trans('admin/main.clear') }}
                    </a>
                @endif
            </form>
        </div>

        @if(!empty($expiredSales) && $expiredSales->count() > 0)
            <div class="mb-20 d-flex align-items-center justify-content-between">
                <div>
                    <button type="button" class="btn btn-sm btn-secondary" id="selectAllBtn">
                        Select all
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary ml-2" id="deselectAllBtn">
                        Deselect all
                    </button>
                </div>
                <button type="button" class="btn btn-primary" id="resubscribeSelectedBtn" disabled>
                    <i class="fa fa-check"></i> Resubscribe selected
                </button>
            </div>
            @foreach($expiredSales as $sale)
                @php
                    $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;

                    $lastSession = !empty($sale->webinar) ? $sale->webinar->lastSession() : null;
                    $nextSession = !empty($sale->webinar) ? $sale->webinar->nextSession() : null;
                    $isProgressing = false;

                    if(!empty($sale->webinar) and $sale->webinar->start_date <= time() and !empty($lastSession) and $lastSession->date > time()) {
                        $isProgressing = true;
                    }
                @endphp

                @if(!empty($item))
                    <div class="row mt-30">
                        <div class="col-12">
                            <div class="webinar-card webinar-list d-flex" style="opacity: 0.7;">
                                <div class="image-box">
                                    <img src="{{ $item->getImage() }}" class="img-cover" alt="">

                                    @if(!empty($sale->webinar))
                                        @php
                                            $percent = $item->getProgress();

                                            if($item->isWebinar()){
                                                if($item->isProgressing()) {
                                                    $progressTitle = trans('public.course_learning_passed',['percent' => $percent]);
                                                } else {
                                                    $progressTitle = $item->sales_count .'/'. $item->capacity .' '. trans('quiz.students');
                                                }
                                            } else {
                                               $progressTitle = trans('public.course_learning_passed',['percent' => $percent]);
                                            }
                                        @endphp

                                        @if(!empty($sale->gift_id) and $sale->buyer_id == $authUser->id)
                                            {{--  --}}
                                        @else
                                            <div class="progress cursor-pointer" data-toggle="tooltip" data-placement="top" title="{{ $progressTitle }}">
                                                <span class="progress-bar" style="width: {{ $percent }}%"></span>
                                            </div>
                                        @endif
                                    @else
                                        <div class="badges-lists">
                                            <span class="badge badge-secondary">{{ trans('update.bundle') }}</span>
                                        </div>
                                    @endif
                                </div>

                                <div class="webinar-card-body w-100 d-flex flex-column">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <a href="{{ $item->getUrl() }}">
                                                <h3 class="webinar-title font-weight-bold font-16 text-dark-blue">
                                                    {{ $item->title }}

                                                    <span class="badge badge-outlined-danger ml-10">{{ trans('update.subscribe_expired') }}</span>

                                                    @if(!empty($sale->webinar))
                                                        <span class="badge badge-dark ml-10 status-badge-dark">{{ trans('webinars.'.$item->type) }}</span>
                                                    @endif

                                                    @if(!empty($sale->gift_id))
                                                        <span class="badge badge-primary ml-10">{{ trans('update.gift') }}</span>
                                                    @endif
                                                </h3>
                                            </a>
                                            <div class="mt-10">
                                                <input type="checkbox" 
                                                       class="subject-checkbox" 
                                                       data-webinar-id="{{ !empty($sale->webinar_id) ? $sale->webinar_id : '' }}"
                                                       data-bundle-id="{{ !empty($sale->bundle_id) ? $sale->bundle_id : '' }}"
                                                       data-item-title="{{ $item->title }}">
                                                <label class="ml-5">Select subject</label>
                                            </div>
                                        </div>

                                        <div class="btn-group dropdown table-actions">
                                            <button type="button" class="btn-transparent dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                <i data-feather="more-vertical" height="20"></i>
                                            </button>

                                            <div class="dropdown-menu">
                                                <a href="#" 
                                                   class="webinar-actions d-block mt-10 resubscribe-btn-dropdown" 
                                                   data-webinar-id="{{ !empty($sale->webinar_id) ? $sale->webinar_id : '' }}"
                                                   data-bundle-id="{{ !empty($sale->bundle_id) ? $sale->bundle_id : '' }}"
                                                   data-item-title="{{ $item->title }}">{{ trans('panel.re_subscribe') }}</a>
                                            </div>
                                        </div>
                                    </div>

                                    @include(getTemplate() . '.includes.webinar.rate',['rate' => $item->getRate()])

                                    <div class="mt-15">
                                        <button type="button" 
                                                class="btn btn-primary resubscribe-btn" 
                                                data-webinar-id="{{ !empty($sale->webinar_id) ? $sale->webinar_id : '' }}"
                                                data-bundle-id="{{ !empty($sale->bundle_id) ? $sale->bundle_id : '' }}"
                                                data-item-title="{{ $item->title }}">
                                            {{ trans('panel.re_subscribe') }}
                                        </button>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between flex-wrap mt-auto">
                                        <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                            <span class="stat-title">{{ trans('public.item_id') }}:</span>
                                            <span class="stat-value">{{ $item->id }}</span>
                                        </div>

                                        <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                            <span class="stat-title">{{ trans('public.category') }}:</span>
                                            <span class="stat-value">{{ !empty($item->category_id) ? $item->category->title : '' }}</span>
                                        </div>

                                        @if(!empty($sale->webinar) and $item->type == 'webinar')
                                            <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                                <span class="stat-title">{{ trans('public.duration') }}:</span>
                                                <span class="stat-value">{{ convertMinutesToHourAndMinute($item->duration) }} Hrs</span>
                                            </div>

                                            <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                                <span class="stat-title">{{ trans('public.start_date') }}:</span>
                                                <span class="stat-value">{{ dateTimeFormat($item->start_date,'j M Y') }}</span>
                                            </div>
                                        @elseif(!empty($sale->bundle))
                                            <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                                <span class="stat-title">{{ trans('public.duration') }}:</span>
                                                <span class="stat-value">{{ convertMinutesToHourAndMinute($item->getBundleDuration()) }} Hrs</span>
                                            </div>
                                        @endif

                                        <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                            <span class="stat-title">{{ trans('public.instructor') }}:</span>
                                            <span class="stat-value">{{ $item->teacher->full_name }}</span>
                                        </div>

                                        <div class="d-flex align-items-start flex-column mt-20 mr-15">
                                            <span class="stat-title">{{ trans('panel.purchase_date') }}:</span>
                                            <span class="stat-value">{{ dateTimeFormat($sale->created_at,'j M Y') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach

            <div class="my-30">
                {{ $expiredSales->appends(request()->input())->links('vendor.pagination.panel') }}
            </div>
        @else
            <div class="mt-30">
                <div class="alert alert-info">
                    No expired subjects
                </div>
            </div>
        @endif
    </section>
@endsection

@push('scripts_bottom')
    <script>
        $(document).ready(function() {
            // Initialize feather icons if available
            if (typeof feather !== 'undefined') {
                feather.replace();
            }
            
            // Handle select all / deselect all
            $('#selectAllBtn').on('click', function() {
                $('.subject-checkbox').prop('checked', true);
                updateResubscribeButton();
            });
            
            $('#deselectAllBtn').on('click', function() {
                $('.subject-checkbox').prop('checked', false);
                updateResubscribeButton();
            });
            
            // Handle checkbox change
            $(document).on('change', '.subject-checkbox', function() {
                updateResubscribeButton();
            });
            
            // Update resubscribe button state
            function updateResubscribeButton() {
                var checkedCount = $('.subject-checkbox:checked').length;
                $('#resubscribeSelectedBtn').prop('disabled', checkedCount === 0);
            }
            
            // Handle bulk resubscribe
            $('#resubscribeSelectedBtn').on('click', function() {
                var selectedItems = [];
                $('.subject-checkbox:checked').each(function() {
                    var $checkbox = $(this);
                    var item = {};
                    if ($checkbox.data('webinar-id')) {
                        item.webinar_id = $checkbox.data('webinar-id');
                    } else if ($checkbox.data('bundle-id')) {
                        item.bundle_id = $checkbox.data('bundle-id');
                    }
                    item.title = $checkbox.data('item-title');
                    selectedItems.push(item);
                });
                
                if (selectedItems.length === 0) {
                    return;
                }
                
                var $btn = $(this);
                $btn.prop('disabled', true);
                var originalText = $btn.html();
                $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + '{{ trans("public.processing") }}');
                
                $.ajax({
                    url: '/panel/webinars/purchases/resubscribe-selected',
                    method: 'POST',
                    data: {
                        selected_items: selectedItems
                    },
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            var message = response.message;
                            if (response.errors && response.errors.length > 0) {
                                message += '\n' + response.errors.slice(0, 3).join('\n');
                            }
                            
                            if (typeof showToastMessage !== 'undefined') {
                                showToastMessage('success', message);
                            } else {
                                alert(message);
                            }
                            
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            var message = response.message;
                            if (response.errors && response.errors.length > 0) {
                                message += '\n' + response.errors.slice(0, 3).join('\n');
                            }
                            
                            if (typeof showToastMessage !== 'undefined') {
                                showToastMessage('error', message);
                            } else {
                                alert(message);
                            }
                            
                            $btn.prop('disabled', false);
                            $btn.html(originalText);
                        }
                    },
                    error: function(xhr) {
                        var errorMessage = '{{ trans("public.request_failed") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        
                        if (typeof showToastMessage !== 'undefined') {
                            showToastMessage('error', errorMessage);
                        } else {
                            alert(errorMessage);
                        }
                        
                        $btn.prop('disabled', false);
                        $btn.html(originalText);
                    }
                });
            });
            
            // Handle resubscribe button click (both main button and dropdown)
            $('.resubscribe-btn, .resubscribe-btn-dropdown').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var webinarId = $btn.data('webinar-id');
                var bundleId = $btn.data('bundle-id');
                var itemTitle = $btn.data('item-title');
                
                // Disable button and show loading
                $btn.prop('disabled', true);
                var originalText = $btn.html();
                $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + '{{ trans("public.processing") }}');
                
                // Prepare data
                var data = {};
                if (webinarId) {
                    data.webinar_id = webinarId;
                } else if (bundleId) {
                    data.bundle_id = bundleId;
                }
                
                // Make AJAX request
                $.ajax({
                    url: '/panel/webinars/purchases/resubscribe-single',
                    method: 'POST',
                    data: data,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            // Show success message
                            if (typeof showToastMessage !== 'undefined') {
                                showToastMessage('success', response.message);
                            } else {
                                alert(response.message);
                            }
                            
                            // Reload page after 1 second
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            // Show error message
                            if (typeof showToastMessage !== 'undefined') {
                                showToastMessage('error', response.message);
                            } else {
                                alert(response.message);
                            }
                            
                            // Re-enable button
                            $btn.prop('disabled', false);
                            $btn.html(originalText);
                        }
                    },
                    error: function(xhr) {
                        var errorMessage = '{{ trans("public.request_failed") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        
                        // Show error message
                        if (typeof showToastMessage !== 'undefined') {
                            showToastMessage('error', errorMessage);
                        } else {
                            alert(errorMessage);
                        }
                        
                        // Re-enable button
                        $btn.prop('disabled', false);
                        $btn.html(originalText);
                    }
                });
            });
        });
    </script>
@endpush
