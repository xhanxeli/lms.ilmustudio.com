@extends(getTemplate().'.layouts.app')

@push('styles_top')

@endpush

@section('content')
    <section class="cart-banner position-relative text-center">
        <h1 class="font-30 text-white font-weight-bold">{{ trans('cart.checkout') }}</h1>
        <span class="payment-hint font-20 text-white d-block">{{ handlePrice($total) . ' ' .  trans('cart.for_items',['count' => $count]) }}</span>
    </section>

    <section class="container mt-45">

        @if(!empty($totalCashbackAmount))
            <div class="d-flex align-items-center mb-25 p-15 success-transparent-alert">
                <div class="success-transparent-alert__icon d-flex align-items-center justify-content-center">
                    <i data-feather="credit-card" width="18" height="18" class=""></i>
                </div>

                <div class="ml-10">
                    <div class="font-14 font-weight-bold ">{{ trans('update.get_cashback') }}</div>
                    <div class="font-12 ">{{ trans('update.by_purchasing_this_cart_you_will_get_amount_as_cashback',['amount' => handlePrice($totalCashbackAmount)]) }}</div>
                </div>
            </div>
        @endif

        @php
            $isMultiCurrency = !empty(getFinancialCurrencySettings('multi_currency'));
            $userCurrency = currency();
            $invalidChannels = [];
        @endphp

        @if(!empty($subscribe_id))
            <div class="rounded-sm shadow mt-20 py-25 px-20 mb-30">
                <h3 class="section-title mb-20">{{ trans('cart.coupon_code') }}</h3>
                <p class="text-gray font-14 mb-20">{{ trans('cart.coupon_code_hint') }}</p>

                <div id="couponForm">
                    <input type="hidden" name="subscribe_id" id="subscribe_id_input" value="{{ $subscribe_id }}">
                    <div class="form-group">
                        <input type="text" name="coupon" id="coupon_input" class="form-control"
                               placeholder="{{ trans('cart.enter_your_code_here') }}">
                        <span class="invalid-feedback d-block" id="coupon_feedback" style="display: none !important;"></span>
                    </div>

                    <button type="button" id="checkCoupon" class="btn btn-sm btn-primary">
                        {{ trans('cart.validate') }}
                    </button>
                </div>

                <div class="mt-20" id="coupon_summary" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between font-14">
                        <span class="text-gray">{{ trans('cart.discount') }}:</span>
                        <span class="text-danger font-weight-bold" id="discount_amount">-</span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between font-14 mt-5">
                        <span class="text-gray">{{ trans('public.total') }}:</span>
                        <span class="text-secondary font-weight-bold" id="total_amount_after_discount">-</span>
                    </div>
                </div>
            </div>
        @endif

        <h2 class="section-title">{{ trans('financial.select_a_payment_gateway') }}</h2>

        <form action="/payments/payment-request" method="post" class=" mt-25" id="paymentForm">
            {{ csrf_field() }}
            <input type="hidden" name="order_id" value="{{ $order->id }}">
            <input type="hidden" name="discount_id" value="" id="discount_id_input">

            <div class="row">
                @if(!empty($paymentChannels))
                    @foreach($paymentChannels as $paymentChannel)
                        @if(!$isMultiCurrency or (!empty($paymentChannel->currencies) and in_array($userCurrency, $paymentChannel->currencies)))
                            <div class="col-6 col-lg-4 mb-40 charge-account-radio">
                                <input type="radio" name="gateway" id="{{ $paymentChannel->title }}" data-class="{{ $paymentChannel->class_name }}" value="{{ $paymentChannel->id }}">
                                <label for="{{ $paymentChannel->title }}" class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center">
                                    <img src="{{ $paymentChannel->image }}" width="120" height="60" alt="">

                                    <p class="mt-30 mt-lg-50 font-weight-500 text-dark-blue">
                                        {{ trans('financial.pay_via') }}
                                        <span class="font-weight-bold font-14">{{ $paymentChannel->title }}</span>
                                    </p>
                                </label>
                            </div>
                        @else
                            @php
                                $invalidChannels[] = $paymentChannel;
                            @endphp
                        @endif
                    @endforeach
                @endif

                <div class="col-6 col-lg-4 mb-40 charge-account-radio">
                    <input type="radio" @if(empty($userCharge) or ($total > $userCharge)) disabled @endif name="gateway" id="offline" value="credit">
                    <label for="offline" class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center">
                        <img src="/assets/default/img/activity/pay.svg" width="120" height="60" alt="">

                        <p class="mt-30 mt-lg-50 font-weight-500 text-dark-blue">
                            {{ trans('financial.account') }}
                            <span class="font-weight-bold">{{ trans('financial.charge') }}</span>
                        </p>

                        <span class="mt-5">{{ handlePrice($userCharge) }}</span>
                    </label>
                </div>
            </div>

            @if(!empty($invalidChannels) and empty(getFinancialSettings("hide_disabled_payment_gateways")))
                <div class="d-flex align-items-center mt-30 rounded-lg border p-15">
                    <div class="size-40 d-flex-center rounded-circle bg-gray200">
                        <i data-feather="info" class="text-gray" width="20" height="20"></i>
                    </div>
                    <div class="ml-5">
                        <h4 class="font-14 font-weight-bold text-gray">{{ trans('update.disabled_payment_gateways') }}</h4>
                        <p class="font-12 text-gray">{{ trans('update.disabled_payment_gateways_hint') }}</p>
                    </div>
                </div>

                <div class="row mt-20">
                    @foreach($invalidChannels as $invalidChannel)
                        <div class="col-6 col-lg-4 mb-40 charge-account-radio">
                            <div class="disabled-payment-channel bg-white border rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center">
                                <img src="{{ $invalidChannel->image }}" width="120" height="60" alt="">

                                <p class="mt-30 mt-lg-50 font-weight-500 text-dark-blue">
                                    {{ trans('financial.pay_via') }}
                                    <span class="font-weight-bold font-14">{{ $invalidChannel->title }}</span>
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif


            <div class="d-flex align-items-center justify-content-between mt-45">
                <div>
                    <span class="font-16 font-weight-500 text-gray">{{ trans('financial.total_amount') }} </span>
                    <span class="font-16 font-weight-bold text-secondary" id="total_display">{{ handlePrice($total) }}</span>
                </div>
                <button type="button" id="paymentSubmit" disabled class="btn btn-sm btn-primary">{{ trans('public.start_payment') }}</button>
            </div>
        </form>

        @if(!empty($razorpay) and $razorpay)
            <form action="/payments/verify/Razorpay" method="get">
                <input type="hidden" name="order_id" value="{{ $order->id }}">

                <script src="https://checkout.razorpay.com/v1/checkout.js"
                        data-key="{{ getRazorpayApiKey()['api_key'] }}"
                        data-amount="{{ (int)($order->total_amount * 100) }}"
                        data-buttontext="product_price"
                        data-description="Rozerpay"
                        data-currency="{{ currency() }}"
                        data-image="{{ $generalSettings['logo'] }}"
                        data-prefill.name="{{ $order->user->full_name }}"
                        data-prefill.email="{{ $order->user->email }}"
                        data-theme.color="#43d477">
                </script>
            </form>
        @endif
    </section>

@endsection

@push('scripts_bottom')
    <script>
        @if(!empty($subscribe_id))
        var couponInvalidLng = '{{ trans('cart.coupon_invalid') }}';
        var couponValidLng = '{{ trans('cart.coupon_valid') }}';
        var subscribeId = {{ $subscribe_id }};
        var userCharge = {{ !empty($userCharge) ? $userCharge : 0 }};
        var originalTotal = {{ $total }};
        
        (function ($) {
            "use strict";

            // Function to enable/disable account charge option based on total amount
            function updateAccountChargeOption(totalAmountNumeric) {
                var accountChargeRadio = $('#offline');
                var accountChargeLabel = accountChargeRadio.closest('.charge-account-radio').find('label');
                
                if (!accountChargeRadio.length) {
                    return; // Account charge option doesn't exist
                }
                
                // Use numeric value if available, otherwise try to parse from formatted string
                var totalAmount = totalAmountNumeric;
                if (typeof totalAmount === 'undefined' || totalAmount === null) {
                    // Fallback: try to parse from the total display
                    var totalText = $('#total_display').text().trim();
                    totalAmount = parseFloat(totalText.replace(/[^0-9.-]+/g, '')) || originalTotal;
                }
                
                console.log('Updating account charge option', {
                    totalAmount: totalAmount,
                    userCharge: userCharge,
                    shouldEnable: userCharge > 0 && totalAmount <= userCharge
                });
                
                // Enable if user has charge balance and total is less than or equal to charge balance
                if (userCharge > 0 && totalAmount <= userCharge) {
                    accountChargeRadio.prop('disabled', false);
                    accountChargeLabel.removeClass('disabled');
                } else {
                    accountChargeRadio.prop('disabled', true);
                    accountChargeLabel.addClass('disabled');
                }
            }

            function validateCoupon() {
                console.log('validateCoupon called');
                var $this = $('#checkCoupon');
                var couponInput = $('#coupon_input');
                var feedback = $('#coupon_feedback');
                var couponSummary = $('#coupon_summary');
                var discountIdInput = $('#discount_id_input');
                var discountAmount = $('#discount_amount');
                var totalAmountDisplay = $('#total_amount_after_discount');
                var totalDisplay = $('#total_display');
                var coupon = couponInput.val().trim();
                
                couponInput.removeClass('is-invalid is-valid');
                feedback.hide().text('').removeClass('text-danger text-success');
                couponSummary.hide();

                if (!coupon) {
                    couponInput.addClass('is-invalid');
                    feedback.addClass('text-danger').text(couponInvalidLng || 'Please enter a coupon code').show();
                    return;
                }

                $this.addClass('loadingbar primary').prop('disabled', true);

                var orderId = $('#paymentForm input[name="order_id"]').val();
                var subscribeIdValue = $('#subscribe_id_input').val() || subscribeId;

                if (!orderId || !subscribeIdValue) {
                    console.log('Missing orderId or subscribeId', {orderId: orderId, subscribeId: subscribeIdValue});
                    couponInput.addClass('is-invalid');
                    feedback.addClass('text-danger').text(couponInvalidLng || 'Invalid request').show();
                    $this.removeClass('loadingbar primary').prop('disabled', false);
                    return;
                }

                var csrfToken = $('input[name="_token"]').first().val() || $('meta[name="csrf-token"]').attr('content');
                
                console.log('Sending AJAX request', {
                    coupon: coupon,
                    order_id: orderId,
                    subscribe_id: subscribeIdValue,
                    url: '/panel/financial/subscribes/coupon/apply-to-order'
                });
                
                $.ajax({
                    url: '/panel/financial/subscribes/coupon/apply-to-order',
                    method: 'POST',
                    data: {
                        coupon: coupon,
                        order_id: orderId,
                        subscribe_id: subscribeIdValue,
                        _token: csrfToken
                    },
                    success: function (result) {
                        console.log('Success response', result);
                        if (result && result.status == 200) {
                            couponInput.addClass('is-valid');
                            feedback.addClass('text-success').text(couponValidLng || 'Coupon valid').show();
                            if (discountIdInput.length) {
                                discountIdInput.val(result.discount_id);
                            }
                            
                            // Show discount summary
                            if (discountAmount.length) {
                                discountAmount.text('-' + result.total_discount);
                            }
                            if (totalAmountDisplay.length) {
                                totalAmountDisplay.text(result.total_amount);
                            }
                            if (totalDisplay.length) {
                                totalDisplay.text(result.total_amount);
                            }
                            couponSummary.show();
                            
                            // Enable/disable account charge option based on discounted total
                            updateAccountChargeOption(result.total_amount_numeric);
                            
                            $this.prop('disabled', true);
                        } else if (result && result.status == 422) {
                            couponInput.removeClass('is-valid').addClass('is-invalid');
                            feedback.addClass('text-danger').text(result.msg || couponInvalidLng || 'Invalid coupon').show();
                            if (discountIdInput.length) {
                                discountIdInput.val('');
                            }
                            couponSummary.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('Error response', xhr, status, error);
                        var errorMsg = couponInvalidLng || 'Invalid coupon';
                        if (xhr.responseJSON && xhr.responseJSON.msg) {
                            errorMsg = xhr.responseJSON.msg;
                        } else if (xhr.responseText) {
                            try {
                                var jsonResponse = JSON.parse(xhr.responseText);
                                if (jsonResponse.msg) {
                                    errorMsg = jsonResponse.msg;
                                }
                            } catch(e) {}
                        }
                        couponInput.removeClass('is-valid').addClass('is-invalid');
                        feedback.addClass('text-danger').text(errorMsg).show();
                        if (discountIdInput.length) {
                            discountIdInput.val('');
                        }
                        couponSummary.hide();
                        
                        // Reset account charge option to original state
                        updateAccountChargeOption(originalTotal);
                    },
                    complete: function() {
                        $this.removeClass('loadingbar primary').prop('disabled', false);
                    }
                });
            }

            // Coupon validation for subscription payments
            $(document).ready(function() {
                console.log('Document ready, setting up coupon validation');
                
                // Initialize account charge option state on page load
                updateAccountChargeOption(originalTotal);
                
                $('body').on('click', '#checkCoupon', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Validate button clicked');
                    validateCoupon();
                    return false;
                });

                // Prevent form submission on Enter key in coupon input
                $('body').on('keydown', '#coupon_input', function (e) {
                    if (e.key === 'Enter' || e.keyCode === 13) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Enter key pressed in coupon input');
                        validateCoupon();
                        return false;
                    }
                });
            });
        })(jQuery);
        @endif
    </script>
    <script src="/assets/default/js/parts/payment.min.js"></script>
@endpush
