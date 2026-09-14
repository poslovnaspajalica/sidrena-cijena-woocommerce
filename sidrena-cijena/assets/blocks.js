/* Sidrena cijena u Cart / Checkout / Mini-cart blokovima (WooCommerce Blocks checkout filters). */
(function () {
    'use strict';
    if (!window.wc || !window.wc.blocksCheckout || typeof window.wc.blocksCheckout.registerCheckoutFilters !== 'function') {
        return;
    }
    window.wc.blocksCheckout.registerCheckoutFilters('sidrena-cijena', {
        cartItemPrice: function (defaultValue, extensions) {
            var ext = extensions && extensions['sidrena-cijena'];
            if (!ext || !ext.label) {
                return defaultValue;
            }
            return '<price/> ' + ext.label;
        }
    });
})();
