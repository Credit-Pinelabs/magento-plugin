define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/customer-data',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Ui/js/modal/alert'
    ],
    function ($, quote, customerData, customer, fullScreenLoader, alert) {
        'use strict';

        // ── Loader helpers ──────────────────────────────────────────────
        function showPinepgLoader(message) {
            var loader = document.getElementById('pinepg-loader');
            var text   = document.getElementById('pinepg-loading-text');
            if (loader) {
                text.textContent = message || 'Processing payment...';
                loader.style.display = 'flex';
            }
        }

        function hidePinepgLoader() {
            var loader = document.getElementById('pinepg-loader');
            if (loader) { loader.style.display = 'none'; }
        }

        // ── Payment‑status overlay ──────────────────────────────────────
        function showPaymentStatus(isSuccess, message) {
            var statusDiv = document.getElementById('pinepg-payment-status');
            var icon      = document.getElementById('pinepg-status-icon');
            var msg       = document.getElementById('pinepg-status-message');
            var buttons   = document.getElementById('pinepg-status-buttons');

            if (!statusDiv) return;

            statusDiv.className = isSuccess
                ? 'pinepg-payment-status pinepg-success'
                : 'pinepg-payment-status pinepg-failure';

            icon.innerHTML = isSuccess ? '✅' : '❌';
            msg.innerHTML  = message;

            if (isSuccess) {
                buttons.innerHTML =
                    '<button class="pinepg-status-button pinepg-continue-btn" ' +
                    'onclick="window.location.href=\'' + window.BASE_URL + 'checkout/onepage/success\'">Continue to Order Details</button>';
            } else {
                buttons.innerHTML =
                    '<button class="pinepg-status-button pinepg-retry-btn" onclick="window.location.reload()">Try Again</button>' +
                    '<button class="pinepg-status-button pinepg-cart-btn" onclick="window.location.href=\'' + window.BASE_URL + 'checkout/cart\'">Return to Cart</button>';
            }

            hidePinepgLoader();
            statusDiv.style.display = 'block';
        }

        // ── Dynamically load Plural JS SDK ──────────────────────────────
        function loadPluralScript(url, callback) {
            if (window.Plural) { callback(); return; }
            var s  = document.createElement('script');
            s.src  = url;
            s.onload = callback;
            s.onerror = function () {
                hidePinepgLoader();
                alert({ content: $.mage.__('Failed to load payment SDK. Please refresh and try again.') });
            };
            document.head.appendChild(s);
        }

        // ── Mark order as success via Magento callback controller ────────
        function markOrderAsSuccess(paymentResponse, callbackUrl) {
            showPinepgLoader('Finalizing payment...');

            return $.ajax({
                url: callbackUrl,
                type: 'POST',
                data: {
                    order_id: paymentResponse.order_id,
                    status: 'PROCESSED',
                    source: 'iframe'
                }
            });
        }

        // ── Open Plural iframe checkout ─────────────────────────────────
        function handleIframeCheckout(redirectUrl, orderId, callbackUrl) {
            showPinepgLoader('Opening secure payment gateway...');

            var options = {
                redirectUrl: redirectUrl,
                successHandler: function (response) {
                    console.log('Plural iframe – payment success:', response);
                    showPinepgLoader('Verifying payment...');

                    markOrderAsSuccess(response, callbackUrl)
                        .done(function (res) {
                            var success = (typeof res === 'object')
                                ? res.success
                                : (typeof res === 'string' && res.indexOf('SUCCESS') !== -1);

                            if (success) {
                                // Redirect to Magento order success page (same as redirect flow)
                                window.location.href = window.BASE_URL + 'checkout/onepage/success';
                            } else {
                                showPaymentStatus(false, 'Payment completed but verification failed. Please contact support.');
                            }
                        })
                        .fail(function () {
                            showPaymentStatus(false, 'Payment completed but verification failed. Please contact support.');
                        });
                },
                failedHandler: function (response) {
                    console.log('Plural iframe – payment failed:', response);
                    showPaymentStatus(false, 'Payment failed. Please try again with a different payment method.');
                }
            };

            var plural = new window.Plural(options);
            plural.open(options);
        }

        // ── Main entry point (called by afterPlaceOrder) ────────────────
        return function (messageContainer) {
            var email;
            var config = window.checkoutConfig.payment.pinepg;
            var integrationMode = config.integrationMode || 'REDIRECT';

            // Get customer email
            if (!customer.isLoggedIn()) {
                email = quote.guestEmail;
            } else {
                email = customer.customerData.email;
            }

            var serviceUrl = config.redirectUrl + '?email=' + encodeURIComponent(email);

            if (integrationMode === 'IFRAME') {
                showPinepgLoader('Processing your order...');
            } else {
                fullScreenLoader.startLoader();
            }

            // 1️⃣ AJAX call to Redirect controller → get redirect_url + order_id
            $.ajax({
                url: serviceUrl,
                type: 'POST',
                context: this,
                data: { isAjax: 1 },
                success: function (response) {
                    if (integrationMode !== 'IFRAME') {
                        fullScreenLoader.stopLoader();
                    }

                    if ($.type(response) === 'object' && !$.isEmptyObject(response) && response.url) {

                        // ── IFRAME flow ─────────────────────────────────
                        if (integrationMode === 'IFRAME') {
                            var scriptUrl   = config.pluralScriptUrl;
                            var callbackUrl = config.callbackUrl;
                            var orderId     = response.order_id;

                            loadPluralScript(scriptUrl, function () {
                                handleIframeCheckout(response.url, orderId, callbackUrl);
                            });
                        } else {
                            // ── REDIRECT flow (original behaviour) ──────
                            console.log('Redirecting to:', response.url);
                            window.location.href = response.url;
                        }

                    } else {
                        hidePinepgLoader();
                        alert({
                            content: $.mage.__('Sorry, something went wrong. Please try again.')
                        });
                    }
                },
                error: function () {
                    if (integrationMode === 'IFRAME') {
                        hidePinepgLoader();
                    } else {
                        fullScreenLoader.stopLoader();
                    }
                    alert({
                        content: $.mage.__('Sorry, something went wrong. Please try again later.')
                    });
                }
            });
        };
    }
);
