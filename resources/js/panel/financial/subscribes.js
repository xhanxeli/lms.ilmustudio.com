(function ($) {
    "use strict";

    $('body').on('click', '.request-payout', function (e) {
        e.preventDefault();

        Swal.fire({
            html: $('#requestPayoutModal').html(),
            showCancelButton: false,
            showConfirmButton: false,
            customClass: {
                content: 'p-0 text-left',
            },
            width: '40rem',
        });
    });

    // Coupon validation for subscriptions
    $('body').on('click', '[id^="checkCoupon_"]', function (e) {
        e.preventDefault();
        var $this = $(this);
        var subscribeId = $this.data('subscribe-id');
        var couponInput = $('#coupon_input_' + subscribeId);
        var feedback = $('#coupon_feedback_' + subscribeId);
        var couponSummary = $('#coupon_summary_' + subscribeId);
        var discountIdInput = $('#discount_id_' + subscribeId);
        var discountAmount = $('#discount_amount_' + subscribeId);
        var totalAmount = $('#total_amount_' + subscribeId);
        var coupon = couponInput.val();
        
        couponInput.removeClass('is-invalid is-valid');
        feedback.hide().text('').removeClass('text-danger text-success');
        couponSummary.hide();

        if (coupon) {
            $this.addClass('loadingbar primary').prop('disabled', true);

            var form = $('#subscribeForm_' + subscribeId);
            var csrfToken = form.find('input[name="_token"]').val() || $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').first().val();
            
            $.post('/panel/financial/subscribes/coupon/validate', {
                coupon: coupon,
                subscribe_id: subscribeId,
                _token: csrfToken
            }, function (result) {
                if (result && result.status == 200) {
                    couponInput.addClass('is-valid');
                    feedback.addClass('text-success').text(couponValidLng || 'Coupon valid').show();
                    discountIdInput.val(result.discount_id);
                    
                    // Show discount summary
                    discountAmount.text('-' + result.total_discount);
                    totalAmount.text(result.total_amount);
                    couponSummary.show();
                    
                    $this.prop('disabled', true);
                } else if (result && result.status == 422) {
                    couponInput.removeClass('is-valid').addClass('is-invalid');
                    feedback.addClass('text-danger').text(result.msg || couponInvalidLng || 'Invalid coupon').show();
                    discountIdInput.val('');
                    couponSummary.hide();
                }
            }).fail(function() {
                couponInput.removeClass('is-valid').addClass('is-invalid');
                feedback.addClass('text-danger').text(couponInvalidLng || 'Invalid coupon').show();
                discountIdInput.val('');
                couponSummary.hide();
            }).always(function() {
                $this.removeClass('loadingbar primary').prop('disabled', false);
            });
        } else {
            couponInput.addClass('is-invalid');
            feedback.addClass('text-danger').text(couponInvalidLng || 'Please enter a coupon code').show();
        }
    });

})(jQuery);
