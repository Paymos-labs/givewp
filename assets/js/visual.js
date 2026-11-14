(() => {
    'use strict';

    const gateway = {
        id: 'paymos',

        Fields() {
            return window.wp.element.createElement('span', null, gateway.settings.message);
        },

        async beforeCreatePayment() {
            const checkout = window.paymosGivewpCheckout;
            if (!checkout || typeof checkout.prepare !== 'function') {
                throw new Error(gateway.settings.unavailable);
            }

            const token = await checkout.prepare(gateway.settings.formId);
            if (typeof token !== 'string' || token === '') {
                throw new Error(gateway.settings.unavailable);
            }

            return { paymos_submit: token };
        },
    };

    window.givewp.gateways.register(gateway);
})();
