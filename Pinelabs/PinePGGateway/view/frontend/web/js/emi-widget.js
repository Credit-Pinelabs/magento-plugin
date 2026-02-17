define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config, element) {
        var $widget = $(element);

        /**
         * Open modal
         */
        function openModal() {
            var $modal = $('#emi-modal');
            console.log('Opening EMI modal', 'Found elements:', $modal.length);
            if ($modal.length > 0) {
                $modal.show();
                $('body').addClass('emi-modal-open').css('overflow', 'hidden');
                console.log('Modal should be visible now');
            } else {
                console.error('Modal element not found!');
            }
        }

        /**
         * Close modal
         */
        function closeModal() {
            var $modal = $('#emi-modal');
            console.log('Closing EMI modal');
            $modal.hide();
            $('body').removeClass('emi-modal-open').css('overflow', '');
        }

        /**
         * Initialize widget
         */
        function init() {
            console.log('Pinelabs EMI Widget initialized');
            console.log('Modal exists:', $('#emi-modal').length);
            console.log('Button exists:', $('#emi-show-all-btn').length);
            
            // Open modal on button click
            $(document).on('click', '#emi-show-all-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('View All button clicked');
                openModal();
                return false;
            });

            // Close modal on close button
            $(document).on('click', '#emi-modal-close', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeModal();
                return false;
            });

            // Close modal on overlay click
            $(document).on('click', '#emi-modal-overlay', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeModal();
                return false;
            });

            // Close modal on ESC key
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && $('#emi-modal').is(':visible')) {
                    closeModal();
                }
            });
        }

        // Initialize on load
        init();
    };
});
