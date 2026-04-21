@extends(getTemplate() .'.panel.layouts.panel_layout')

@section('content')
    @if($activeSubscribes && $activeSubscribes->count() > 0)
        <section>
            <h2 class="section-title">{{ trans('financial.my_active_plan') }}</h2>

            @foreach($activeSubscribes as $subscribe)
                @php
                    // Calculate days remaining - use custom_expiration_date if set, otherwise use days_remaining from model or calculate
                    $daysRemaining = 0;
                    if (isset($subscribe->days_remaining)) {
                        $daysRemaining = $subscribe->days_remaining;
                    } elseif (!empty($subscribe->custom_expiration_date)) {
                        // Calculate from custom expiration date
                        $expirationCarbon = \Carbon\Carbon::createFromTimestamp($subscribe->custom_expiration_date);
                        $now = \Carbon\Carbon::now();
                        $daysRemaining = max(0, $now->diffInDays($expirationCarbon, false));
                    } elseif (!empty($subscribe->sale_created_at)) {
                        // Fallback: calculate from purchase date + days
                        $saleCreatedAt = \Carbon\Carbon::createFromTimestamp($subscribe->sale_created_at);
                        $now = \Carbon\Carbon::now();
                        $countDayOfSale = $saleCreatedAt->diffInDays($now);
                        $daysRemaining = max(0, $subscribe->days - $countDayOfSale);
                    } else {
                        $daysRemaining = isset($dayOfUse) ? max(0, $subscribe->days - $dayOfUse) : 0;
                    }
                    
                    // Calculate expiration date - use custom_expiration_date if set, otherwise calculate from purchase date + days
                    $expirationDate = null;
                    if (!empty($subscribe->custom_expiration_date)) {
                        $expirationDate = \Carbon\Carbon::createFromTimestamp($subscribe->custom_expiration_date);
                    } elseif (!empty($subscribe->sale_created_at)) {
                        $saleCreatedAt = \Carbon\Carbon::createFromTimestamp($subscribe->sale_created_at);
                        $expirationDate = $saleCreatedAt->copy()->addDays($subscribe->days);
                    }
                @endphp
                
                <div class="activities-container mt-25 p-20 p-lg-35">
                    <div class="row">
                        <div class="col-12 col-md-4 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/webinars.svg" width="64" height="64" alt="">
                                <strong class="font-30 font-weight-bold mt-5">{{ $subscribe->title }}</strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.active_plan') }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-4 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/53.svg" width="64" height="64" alt="">
                                <strong class="font-30 text-dark-blue font-weight-bold mt-5">
                                    @if($subscribe->infinite_use)
                                        {{ trans('update.unlimited') }}
                                    @else
                                        {{ $subscribe->usable_count - $subscribe->used_count }}
                                    @endif
                                </strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.remained_downloads') }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-4 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/54.svg" width="64" height="64" alt="">
                                <strong class="font-30 text-dark-blue text-dark-blue font-weight-bold mt-5">{{ $daysRemaining }}</strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.days_remained') }}</span>
                                @if($expirationDate)
                                    <span class="font-14 text-gray font-weight-400 mt-5">{{ trans('financial.expires_on') }}: {{ dateTimeFormat($expirationDate->timestamp, 'j M Y') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </section>
    @else
        @include(getTemplate() . '.includes.no-result',[
           'file_name' => 'subcribe.png',
           'title' => trans('financial.subcribe_no_result'),
           'hint' => nl2br(trans('financial.subcribe_no_result_hint')),
       ])
    @endif

    @if($expiredSubscribes && $expiredSubscribes->count() > 0)
        <section class="mt-30">
            <h2 class="section-title">{{ trans('financial.my_expired_plan') }}</h2>

            @foreach($expiredSubscribes as $subscribe)
                <div class="activities-container mt-25 p-20 p-lg-35" style="opacity: 0.7;">
                    <div class="row">
                        <div class="col-12 col-md-3 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/webinars.svg" width="64" height="64" alt="">
                                <strong class="font-30 font-weight-bold mt-5">{{ $subscribe->title }}</strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.expired_plan') }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/53.svg" width="64" height="64" alt="">
                                <strong class="font-30 text-gray font-weight-bold mt-5">
                                    @if($subscribe->infinite_use)
                                        {{ trans('update.unlimited') }}
                                    @else
                                        {{ $subscribe->usable_count - $subscribe->used_count }}
                                    @endif
                                </strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.remained_downloads') }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center">
                                <img src="/assets/default/img/activity/54.svg" width="64" height="64" alt="">
                                <strong class="font-30 text-gray font-weight-bold mt-5">{{ isset($subscribe->days_expired) ? $subscribe->days_expired : 0 }}</strong>
                                <span class="font-16 text-gray font-weight-500">{{ trans('financial.days_expired') }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 d-flex align-items-center justify-content-center">
                            <div class="d-flex flex-column align-items-center text-center w-100">
                                <form action="/panel/financial/pay-subscribes" method="post" class="w-100">
                                    {{ csrf_field() }}
                                    <input name="amount" value="{{ $subscribe->price }}" type="hidden">
                                    <input name="id" value="{{ $subscribe->id }}" type="hidden">
                                    <button type="submit" class="btn btn-primary btn-block mt-15" style="min-width: 150px;">
                                        <i class="fa fa-refresh"></i> {{ trans('financial.renew_plan') }}
                                    </button>
                                </form>
                                <span class="font-16 text-gray font-weight-500 mt-10">{{ trans('financial.renew_to_continue') }}</span>
                            </div>
                        </div>

                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <section class="mt-30">
        <div class="d-flex align-items-start align-items-md-center justify-content-between flex-column flex-md-row">
            <h2 class="section-title">{{ trans('financial.select_a_subscribe_plan') }}</h2>
        </div>

        <div class="mb-4 mt-20">
            <form method="get" action="{{ url('/panel/financial/subscribes') }}" class="d-flex align-items-center flex-wrap">
                <div class="form-group mb-0 mr-2">
                    <input type="text" name="search" class="form-control"
                           placeholder="{{ trans('panel.search_by_plan_name') }}"
                           value="{{ $search ?? '' }}"
                           style="width: 300px;">
                </div>
                <div class="form-group mb-0 mr-2">
                    <select name="total_subjects" class="form-control" style="width: 200px;">
                        <option value="">{{ trans('panel.filter_by_total_subjects') }}</option>
                        <option value="unlimited" {{ $totalSubjectsFilter == 'unlimited' ? 'selected' : '' }}>{{ trans('update.unlimited') }}</option>
                        @foreach($uniqueUsableCounts as $count)
                            <option value="{{ $count }}" {{ $totalSubjectsFilter == $count ? 'selected' : '' }}>{{ $count }} {{ trans('update.subscribes') }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary mr-2">
                    <i class="fa fa-search"></i> {{ trans('admin/main.search') }}
                </button>
                @if(!empty($search) || !empty($totalSubjectsFilter))
                    <a href="{{ url('/panel/financial/subscribes') }}" class="btn btn-secondary">
                        <i class="fa fa-times"></i> {{ trans('admin/main.clear') }}
                    </a>
                @endif
            </form>
        </div>

        <div class="row mt-15">

            @foreach($subscribes as $subscribe)
                @php
                    $subscribeSpecialOffer = $subscribe->activeSpecialOffer();
                @endphp

                <div class="col-12 col-sm-6 col-lg-3 mt-15">
                    <div class="subscribe-plan position-relative bg-white d-flex flex-column align-items-center rounded-sm shadow pt-50 pb-20 px-20">
                        @if($subscribe->is_popular)
                            <span class="badge badge-primary badge-popular px-15 py-5">{{ trans('panel.popular') }}</span>
                        @elseif(!empty($subscribeSpecialOffer))
                            <span class="badge badge-danger badge-popular px-15 py-5">{{ trans('update.percent_off', ['percent' => $subscribeSpecialOffer->percent]) }}</span>
                        @endif

                        <div class="plan-icon">
                            <img src="{{ $subscribe->icon }}" class="img-cover" alt="">
                        </div>

                        <h3 class="mt-20 font-30 text-secondary">{{ $subscribe->title }}</h3>
                        <p class="font-weight-500 font-14 text-gray mt-10">{{ $subscribe->description }}</p>

                        <div class="d-flex align-items-start mt-30">
                            @if(!empty($subscribe->price) and $subscribe->price > 0)
                                @if(!empty($subscribeSpecialOffer))
                                    <div class="d-flex align-items-end line-height-1">
                                        <span class="font-36 text-primary">{{ handlePrice($subscribe->getPrice(), true, true, false, null, true) }}</span>
                                        <span class="font-14 text-gray ml-5 text-decoration-line-through">{{ handlePrice($subscribe->price, true, true, false, null, true) }}</span>
                                    </div>
                                @else
                                    <span class="font-36 text-primary line-height-1">{{ handlePrice($subscribe->price, true, true, false, null, true) }}</span>
                                @endif
                            @else
                                <span class="font-36 text-primary line-height-1">{{ trans('public.free') }}</span>
                            @endif
                        </div>

                        <ul class="mt-20 plan-feature">
                            <li class="mt-10">{{ $subscribe->days }} {{ trans('financial.days_of_subscription') }}</li>
                            <li class="mt-10">
                                @if($subscribe->infinite_use)
                                    {{ trans('update.unlimited') }}
                                @else
                                    {{ $subscribe->usable_count }}
                                @endif
                                <span class="ml-5">{{ trans('update.subscribes') }}</span>
                            </li>
                        </ul>
                        <form action="/panel/financial/pay-subscribes" method="post" class="btn-block">
                            {{ csrf_field() }}
                            <input name="amount" value="{{ $subscribe->price }}" type="hidden">
                            <input name="id" value="{{ $subscribe->id }}" type="hidden">

                            <div class="d-flex align-items-center mt-50 w-100">
                                <button type="submit" class="btn btn-primary {{ !empty($subscribe->has_installment) ? '' : 'btn-block' }}">{{ trans('update.purchase') }}</button>

                                @if(!empty($subscribe->has_installment))
                                    <a href="/panel/financial/subscribes/{{ $subscribe->id }}/installments" class="btn btn-outline-primary flex-grow-1 ml-10">{{ trans('update.installments') }}</a>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endsection

@push('scripts_bottom')
    <script src="/assets/default/js/panel/financial/subscribes.min.js"></script>
@endpush
