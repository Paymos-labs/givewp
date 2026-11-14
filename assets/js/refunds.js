(() => {
    'use strict';

    const root = document.querySelector('#paymos-givewp-refunds');
    const config = window.PaymosGivewpRefunds;
    if (!root || !config) {
        return;
    }

    const lookup = root.querySelector('[data-paymos-refund-lookup]');
    if (!lookup) {
        return;
    }

    const donation = root.querySelector('[data-paymos-donation]');
    const message = root.querySelector('[data-paymos-refund-message]');
    const record = root.querySelector('[data-paymos-refund-record]');
    const payment = root.querySelector('[data-paymos-refund-payment]');
    const email = root.querySelector('[data-paymos-refund-email]');
    const mode = root.querySelector('[data-paymos-refund-mode]');
    const status = root.querySelector('[data-paymos-refund-state]');
    const requestButton = root.querySelector('[data-paymos-refund-action="request"]');
    const pollButton = root.querySelector('[data-paymos-refund-action="poll"]');
    let loaded = null;
    let busy = false;

    function draw() {
        const operation = loaded && loaded.modes[mode.value];
        record.hidden = !operation;
        requestButton.disabled = busy || !operation || !operation.can_request;
        pollButton.disabled = busy || !operation || !operation.can_poll;
        mode.disabled = busy;
        if (!operation) {
            return;
        }

        payment.textContent = [loaded.invoice_id, loaded.amount + ' ' + loaded.currency, loaded.environment].join(' · ');
        email.hidden = !loaded.receipt_delivery_unknown;
        const details = [config.states[operation.state] || config.unavailable];
        if (operation.amount && operation.currency && operation.network) {
            details.push(operation.amount + ' ' + operation.currency + ' (' + operation.network + ')');
        }
        if (operation.reference) {
            details.push(operation.reference);
        }
        if (operation.review_reason) {
            details.push(config.review);
        }
        status.textContent = details.join(' · ');
    }

    async function call(action, fields) {
        const endpoint = new URL(config.routes[action], window.location.href);
        if (endpoint.protocol !== 'https:' || endpoint.origin !== window.location.origin) {
            throw new Error(config.unavailable);
        }

        const response = await window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ blog_id: config.blogId, ...fields }),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(typeof data.message === 'string' ? data.message : config.unavailable);
        }

        return data;
    }

    async function run(action, fields, cleanup) {
        if (busy) {
            return;
        }
        busy = true;
        donation.disabled = true;
        message.textContent = config.loading;
        draw();
        try {
            const data = await call(action, fields);
            if (cleanup) {
                cleanup.remove();
                message.textContent = config.removed;
            } else {
                loaded = data;
                message.textContent = '';
            }
        } catch (error) {
            if (action === 'status') {
                loaded = null;
            }
            message.textContent = error.message || config.unavailable;
        } finally {
            donation.disabled = false;
            busy = false;
            draw();
        }
    }

    lookup.addEventListener('submit', event => {
        event.preventDefault();
        const id = Number(donation.value);
        if (Number.isSafeInteger(id) && id > 0) {
            run('status', { donation_id: id });
        }
    });

    donation.addEventListener('input', () => {
        loaded = null;
        message.textContent = '';
        draw();
    });

    mode.addEventListener('change', draw);
    requestButton.addEventListener('click', () => {
        if (loaded && window.confirm(config.confirmation[mode.value])) {
            run('request', { donation_id: loaded.donation_id, mode: mode.value });
        }
    });
    pollButton.addEventListener('click', () => {
        if (loaded) {
            run('poll', { donation_id: loaded.donation_id, mode: mode.value });
        }
    });

    root.querySelectorAll('[data-paymos-cleanup]').forEach(button => {
        button.addEventListener('click', () => {
            if (!busy && window.confirm(config.confirmCleanup)) {
                run('cleanup', {
                    kind: button.dataset.paymosCleanup,
                    record_id: Number(button.dataset.paymosRecord),
                }, button.closest('li'));
            }
        });
    });
})();
