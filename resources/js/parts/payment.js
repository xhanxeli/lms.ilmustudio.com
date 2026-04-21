(function ($) {
    "use strict";

    var gateway = 'other';
    $('body').on('change', 'input[name="gateway"]', function (e) {
        e.preventDefault();

        var submitButton = $('button#paymentSubmit');

        submitButton.removeAttr('disabled');

        $('html, body').animate({
            scrollTop: submitButton.offset().top - 250
        }, 600);

        gateway = $(this).attr('data-class');
    });

    $('body').on('click', '#paymentSubmit', function (e) {
        e.preventDefault();

        $(this).addClass('loadingbar primary').prop('disabled', true);

        if (gateway === 'Razorpay') {
            $('.razorpay-payment-button').trigger('click');
        } else {
            $(this).closest('form').trigger('submit');
        }
    });

    // Coupon validation for subscription payments
    $('body').on('click', '#checkCoupon', function (e) {
        e.preventDefault();
        e.stopPropagation();
        validateCoupon();
    });

    // Prevent form submission on Enter key in coupon input
    $('body').on('keydown', '#coupon_input', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            e.stopPropagation();
            validateCoupon();
            return false;
        }
    });

    function validateCoupon() {
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
            feedback.addClass('text-danger').text(typeof couponInvalidLng !== 'undefined' ? couponInvalidLng : 'Please enter a coupon code').show();
            return;
        }

        $this.addClass('loadingbar primary').prop('disabled', true);

        var orderId = $('#paymentForm input[name="order_id"]').val();
        var subscribeId = $('#subscribe_id_input').val() || (typeof subscribeId !== 'undefined' ? window.subscribeId : '');

        if (!orderId || !subscribeId) {
            couponInput.addClass('is-invalid');
            feedback.addClass('text-danger').text(typeof couponInvalidLng !== 'undefined' ? couponInvalidLng : 'Invalid request').show();
            $this.removeClass('loadingbar primary').prop('disabled', false);
            return;
        }

        var csrfToken = $('input[name="_token"]').first().val() || $('meta[name="csrf-token"]').attr('content');
        
        $.ajax({
            url: '/panel/financial/subscribes/coupon/apply-to-order',
            method: 'POST',
            data: {
                coupon: coupon,
                order_id: orderId,
                subscribe_id: subscribeId,
                _token: csrfToken
            },
            success: function (result) {
                if (result && result.status == 200) {
                    couponInput.addClass('is-valid');
                    feedback.addClass('text-success').text(typeof couponValidLng !== 'undefined' ? couponValidLng : 'Coupon valid').show();
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
                    
                    $this.prop('disabled', true);
                } else if (result && result.status == 422) {
                    couponInput.removeClass('is-valid').addClass('is-invalid');
                    feedback.addClass('text-danger').text(result.msg || (typeof couponInvalidLng !== 'undefined' ? couponInvalidLng : 'Invalid coupon')).show();
                    if (discountIdInput.length) {
                        discountIdInput.val('');
                    }
                    couponSummary.hide();
                }
            },
            error: function(xhr) {
                var errorMsg = typeof couponInvalidLng !== 'undefined' ? couponInvalidLng : 'Invalid coupon';
                if (xhr.responseJSON && xhr.responseJSON.msg) {
                    errorMsg = xhr.responseJSON.msg;
                }
                couponInput.removeClass('is-valid').addClass('is-invalid');
                feedback.addClass('text-danger').text(errorMsg).show();
                if (discountIdInput.length) {
                    discountIdInput.val('');
                }
                couponSummary.hide();
            },
            complete: function() {
                $this.removeClass('loadingbar primary').prop('disabled', false);
            }
        });
    }
})(jQuery);
