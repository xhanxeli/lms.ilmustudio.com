(function ($) {
    "use strict";

    // Wait for DOM to be ready before initializing chart
    $(document).ready(function() {
        // Initialize chart only if element exists and is a canvas
        var chartElement = document.getElementById('myChart');
        if (!chartElement) {
            return;
        }
        if (chartElement.tagName !== 'CANVAS') {
            return;
        }
        if (typeof chartElement.getContext !== 'function') {
            return;
        }
        try {
            var ctx = chartElement.getContext('2d');
                if (ctx && typeof $chartDataMonths !== 'undefined' && typeof $chartData !== 'undefined') {
                    var myChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: $chartDataMonths,
                            datasets: [{
                                label: '',
                                data: $chartData,
                                backgroundColor: 'transparent',
                                borderColor: '#43d477',
                                borderWidth: 2
                            }]
                        },
                    });
                }
            } catch (error) {
                console.error('Error initializing chart:', error);
            }
    });

    function handleNextBadgeChart() {
        const card = $('#nextBadgeChart');
        var percent = card.attr('data-percent');
        var label = card.attr('data-label');

        var options = {
            series: [Number(percent)],
            chart: {
                height: 300,
                width: "100%",
                type: 'radialBar',
                offsetY: -30,
            },

            plotOptions: {
                radialBar: {
                    startAngle: -130,
                    endAngle: 130,
                    inverseOrder: true,

                    hollow: {
                        margin: 5,
                        size: '50%',
                        image: '/assets/default/img/radial-image.png',
                        imageWidth: 140,
                        imageHeight: 140,
                        imageClipped: false,
                    },
                    track: {
                        opacity: 0.4,
                        colors: '#222'
                    },
                    dataLabels: {
                        enabled: false,
                        enabledOnSeries: undefined,
                        formatter: function (val, opts) {
                            return val + "%"
                        },
                        textAnchor: 'middle',
                        distributed: false,
                        offsetX: 0,
                        offsetY: 0,

                        style: {
                            fontSize: '14px',
                            fontFamily: 'Helvetica, Arial, sans-serif',
                            fill: ['#2b2b2b'],

                        },
                    },
                }
            },

            fill: {
                type: 'gradient',
                gradient: {
                    shade: 'light',
                    shadeIntensity: 0.05,
                    inverseColors: false,
                    opacityFrom: 1,
                    opacityTo: 1,
                    stops: [0, 100],
                    gradientToColors: ['#a927f9'],
                    type: 'horizontal'
                },
                strokeLinecap: 'round'
            },
            stroke: {
                dashArray: 9,
                strokecolor: ['#ffffff'],
            },

            labels: [label],
            colors: ['#0d6efd'],
        };

        var chart = new ApexCharts(document.querySelector("#nextBadgeChart"), options);
        chart.render();
    }

    handleNextBadgeChart();

    $('body').on('change', '#iNotAvailable', function (e) {
        e.preventDefault();

        if (this.checked) {
            Swal.fire({
                html: $('#iNotAvailableModal').html(),
                showCancelButton: false,
                showConfirmButton: false,
                customClass: {
                    content: 'p-0 text-left',
                },
                width: '40rem',
            });
        } else {
            handleOffline('', false);
        }
    });

    // Noticeboard info button handler
    $(document).ready(function() {
        console.log('Dashboard script loaded, attaching noticeboard handler');
    });
    
    $('body').on('click', '.js-noticeboard-info', function (e) {
        console.log('Noticeboard info button clicked');
        e.preventDefault();
        e.stopPropagation();
        
        const $this = $(this);

        const noticeboard_id = $this.attr('data-id');
        console.log('Noticeboard ID:', noticeboard_id);
        
        if (!noticeboard_id) {
            console.error('Noticeboard ID not found');
            return false;
        }

        const card = $this.closest('.noticeboard-item');
        if (!card.length) {
            console.error('Noticeboard card not found');
            return false;
        }

        // Get title - handle HTML content properly
        const $titleElement = card.find('.js-noticeboard-title');
        const title = $titleElement.length ? $titleElement.html() : '';
        
        // Get time
        const $timeElement = card.find('.js-noticeboard-time');
        const time = $timeElement.length ? $timeElement.text() : '';
        
        // Get message from hidden input
        const $messageElement = card.find('.js-noticeboard-message');
        const message = $messageElement.length ? $messageElement.val() : '';

        console.log('Title:', title);
        console.log('Time:', time);
        console.log('Message length:', message ? message.length : 0);

        if (!title && !message) {
            console.error('Noticeboard content not found');
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Could not load noticeboard content'
            });
            return false;
        }

        // Build the modal content HTML directly
        const modalContent = '<div class="text-center p-20">' +
            '<h3 class="font-20 font-weight-500 text-dark-blue mb-15">' + title + '</h3>' +
            '<span class="d-block font-12 text-gray mb-15">' + time + '</span>' +
            '<div class="font-weight-500 text-gray text-left">' + message + '</div>' +
            '</div>';

        console.log('Showing Swal modal');
        Swal.fire({
            html: modalContent,
            showCancelButton: false,
            showConfirmButton: false,
            customClass: {
                content: 'p-0 text-left',
            },
            width: '30rem',
        });

        if (!$this.hasClass('seen-at')) {
            $.get('/panel/noticeboard/' + noticeboard_id + '/saveStatus', function () {
                $this.addClass('seen-at');
            }).fail(function() {
                console.error('Failed to save noticeboard status');
            });
        }
        
        return false;
    });

    $('body').on('click', '.js-save-offline-toggle', function (e) {
        const $this = $(this);

        const $card = $this.closest('.offline-modal');
        const textarea = $card.find('textarea');
        const message = textarea.val();

        handleOffline(message, true);
    });

    function handleOffline(message, toggle) {
        const action = '/panel/users/offlineToggle';

        const data = {
            message: message,
            toggle: toggle
        };

        $.post(action, data, function (result) {
            if (result && result.code === 200) {
                Swal.fire({
                    icon: 'success',
                    html: '<h3 class="font-20 text-center text-dark-blue">' + offlineSuccess + '</h3>',
                    showConfirmButton: false,
                });

                setTimeout(() => {
                    window.location.reload();
                }, 2000)
            } else {
                Swal.fire({
                    icon: 'error',
                    showConfirmButton: false,
                });
            }
        })
    }
})(jQuery)
