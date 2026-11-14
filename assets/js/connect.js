(() => {
    'use strict';

    const panel = document.getElementById('paymos-givewp-connect');
    const config = window.PaymosGivewpConnect;
    if (!panel || !config) {
        return;
    }

    const mode = panel.querySelector('[data-paymos-mode]');
    const start = panel.querySelector('[data-paymos-start]');
    const disable = panel.querySelector('[data-paymos-disable]');
    const reset = panel.querySelector('[data-paymos-reset]');
    const connection = panel.querySelector('[data-paymos-connection]');
    const message = panel.querySelector('[data-paymos-message]');
    const approval = panel.querySelector('[data-paymos-approval]');
    const code = panel.querySelector('[data-paymos-code]');
    const open = panel.querySelector('[data-paymos-open]');
    const messages = config.messages;
    let current = { environment: null, retained: false };
    let pending = null;
    let timer;
    let busy = false;

    function setHidden(element, hidden) {
        element.hidden = hidden;
        element.classList.toggle('hidden', hidden);
    }

    function render() {
        const environment = current.environment;
        const label = environment === 'sandbox' || environment === 'live' ? messages[environment] : '';
        const hint = current.project_hint ? ' · …' + current.project_hint : '';
        connection.textContent = label ? label + hint : messages.disconnected;
        start.disabled = busy;
        mode.disabled = busy;
        disable.disabled = busy;
        reset.disabled = busy;
        setHidden(disable, !environment && !pending);
        setHidden(reset, !!environment || !current.retained || !!pending);
    }

    async function request(action, data) {
        const endpoint = new URL(config.routes[action], window.location.href);
        if (endpoint.protocol !== 'https:' || endpoint.origin !== window.location.origin) {
            throw new Error(messages.failed);
        }
        const options = {
            method: data ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' },
        };
        if (data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }
        const response = await fetch(endpoint, options);
        const result = await response.json();
        if (!response.ok) {
            const error = new Error(typeof result.message === 'string' ? result.message : messages.failed);
            error.code = result.code;
            throw error;
        }

        return result;
    }

    function schedule(seconds) {
        window.clearTimeout(timer);
        if (!pending) {
            return;
        }
        const requestId = pending.request_id;
        const delay = Math.max(1, Math.min(60, Number(seconds) || 5));
        timer = window.setTimeout(() => poll(requestId), delay * 1000);
    }

    function setPending(value) {
        window.clearTimeout(timer);
        pending = null;
        open.removeAttribute('href');
        code.textContent = '';
        setHidden(approval, true);

        if (value) {
            const url = new URL(value.verification_url);
            if (
                url.origin !== config.approvalOrigin || url.pathname !== '/connect/cms/verify'
                || url.username || url.password || url.hash
            ) {
                throw new Error(messages.failed);
            }
            pending = Object.assign({}, value, { until: Date.now() + value.expires_in * 1000 });
            code.textContent = value.user_code;
            open.href = url.href;
            setHidden(approval, false);
            message.textContent = messages.waiting;
            schedule(value.retry_after);
        }
        render();
    }

    async function poll(requestId) {
        if (!pending || pending.request_id !== requestId) {
            return;
        }
        if (Date.now() >= pending.until) {
            setPending(null);
            message.textContent = messages.expired;
            return;
        }
        if (busy) {
            schedule(1);
            return;
        }

        try {
            const result = await request('poll', { request_id: requestId });
            if (!pending || pending.request_id !== requestId) {
                return;
            }
            if (result.status === 'connected') {
                current = result.connection;
                setPending(null);
                message.textContent = '';
            } else {
                schedule(result.retry_after);
            }
        } catch (error) {
            if (!pending || pending.request_id !== requestId) {
                return;
            }
            if (error.code === 'paymos_connect_busy') {
                schedule(2);
                return;
            }
            setPending(null);
            message.textContent = error.message || messages.failed;
        }
    }

    async function change(action, data) {
        if (busy) {
            return;
        }
        busy = true;
        render();
        try {
            const result = await request(action, data);
            if (action === 'start') {
                setPending(result);
            } else {
                current = result.connection;
                setPending(null);
                message.textContent = '';
            }
        } catch (error) {
            message.textContent = error.message || messages.failed;
        } finally {
            busy = false;
            render();
        }
    }

    start.addEventListener('click', () => {
        if (mode.value !== 'live' || window.confirm(messages.confirmLive)) {
            change('start', { environment: mode.value });
        }
    });
    disable.addEventListener('click', () => {
        if (window.confirm(messages.confirmDisable)) {
            change('disable', {});
        }
    });
    reset.addEventListener('click', () => {
        if (window.confirm(messages.confirmReset)) {
            change('reset', {});
        }
    });

    request('status').then(result => {
        current = result.connection;
        setPending(result.pending);
    }).catch(error => {
        message.textContent = error.message || messages.failed;
        render();
    });
})();
