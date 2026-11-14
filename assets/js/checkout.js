(() => {
    'use strict';

    const config = window.PaymosGivewpCheckoutConfig;
    const attempts = new Map();
    let pendingLegacyForms = new WeakSet();
    let activeDocument = true;
    const legacySubmit = 'input[name="give-purchase"].give-submit';
    const legacyField = 'input[name="gatewayData[paymos_submit]"][data-paymos-givewp-form]';
    const recoveryMount = '[data-paymos-recovery-form]';
    const cookieLifetime = Number(config.cookieLifetime) * 1000;
    const expiryBuffer = 30 * 1000;

    function validForm(form) {
        return Number.isSafeInteger(form) && form > 0;
    }

    function recoveryKey(form) {
        return 'paymos-givewp-recovery:' + config.siteScope + ':' + form;
    }

    function trustedRecoveryUrl(value) {
        if (typeof value !== 'string') {
            return null;
        }
        try {
            const url = new URL(value);
            const base = new URL(config.statusBase);

            return url.protocol === 'https:' && url.origin === window.location.origin && url.origin === base.origin
                && url.pathname === base.pathname && url.username === '' && url.password === '' && url.hash === ''
                && /^\?paymos_givewp=[a-f0-9]{64}$/.test(url.search) && url.href === value ? url : null;
        } catch {
            return null;
        }
    }

    function validRecoveryExpiry(expiresAt) {
        const now = Date.now();

        return Number.isSafeInteger(expiresAt) && expiresAt > now && expiresAt <= now + cookieLifetime;
    }

    function savedRecovery(form) {
        try {
            const entry = JSON.parse(window.sessionStorage.getItem(recoveryKey(form)));
            if (!entry || !validRecoveryExpiry(entry.expiresAt)) {
                return null;
            }

            return trustedRecoveryUrl(entry.url);
        } catch {
            return null;
        }
    }

    function rememberRecovery(form, value, expiresAt) {
        const url = trustedRecoveryUrl(value);
        if (!url || !validRecoveryExpiry(expiresAt)) {
            return;
        }
        try {
            window.sessionStorage.setItem(recoveryKey(form), JSON.stringify({ url: url.href, expiresAt }));
        } catch {
            // Browsers can block storage while the current document can still submit normally.
        }
    }

    function retireRecovery(marker) {
        const match = /^([1-9][0-9]*):([a-f0-9]{64})$/.exec(marker);
        if (!match) {
            return;
        }
        const [, form, handle] = match;
        try {
            const url = savedRecovery(form);
            if (url && url.searchParams.get('paymos_givewp') === handle) {
                window.sessionStorage.removeItem(recoveryKey(form));
            }
        } catch {
            // A status page remains accessible without browser storage.
        }
    }

    function renderRecoveryHints() {
        for (const mount of document.querySelectorAll(recoveryMount)) {
            const form = Number(mount.dataset.paymosRecoveryForm);
            const url = validForm(form) && !attempts.has(form) ? savedRecovery(form) : null;
            mount.replaceChildren();
            mount.hidden = url === null;
            if (url) {
                const link = document.createElement('a');
                link.href = url.href;
                link.textContent = config.resumeLabel;
                link.target = '_top';
                link.referrerPolicy = 'no-referrer';
                mount.append(link);
            }
        }
    }

    function newIntent() {
        return Array.from(window.crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    }

    function hasUsableToken(result) {
        return result && result.expiresAt > Date.now() + expiryBuffer;
    }

    async function bootstrap(form, state) {
        const endpoint = new URL(config.bootstrapUrl, window.location.href);
        if (endpoint.protocol !== 'https:' || endpoint.origin !== window.location.origin) {
            throw new Error(config.unavailable);
        }
        if (!state.intent) {
            state.intent = newIntent();
            state.recoveryExpiry = Date.now() + cookieLifetime;
        }
        const response = await window.fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            body: JSON.stringify({ form, intent: state.intent }),
        });
        if (!response.ok) {
            throw new Error(config.unavailable);
        }
        const { token, expires_in, resume_url } = await response.json();
        if (typeof token !== 'string' || token === '' || !Number.isSafeInteger(expires_in) || expires_in <= 30) {
            throw new Error(config.unavailable);
        }

        return { token, expiresAt: Date.now() + expires_in * 1000, resumeUrl: resume_url };
    }

    function prepare(form) {
        if (!activeDocument || !validForm(form)) {
            return Promise.reject(new Error(config.unavailable));
        }
        let state = attempts.get(form);
        if (!state) {
            state = {};
            attempts.set(form, state);
            renderRecoveryHints();
        }
        if (state.promise && (!state.result || hasUsableToken(state.result))) {
            return state.promise;
        }

        state.result = null;
        state.promise = bootstrap(form, state).then(result => {
            // A response from a document that has been left cannot restore its submit token or hint.
            if (attempts.get(form) !== state) {
                throw new Error(config.unavailable);
            }
            state.result = result;
            rememberRecovery(form, result.resumeUrl, state.recoveryExpiry);

            return result.token;
        }).catch(() => {
            state.promise = null;
            throw new Error(config.unavailable);
        });
        return state.promise;
    }

    function usesPaymos(form) {
        const selected = form.querySelector('input[name="payment-mode"]:checked')
            || form.querySelector('input[name="give-gateway"]');

        return selected && selected.value === 'paymos';
    }

    function showUnavailable(form) {
        let notice = form.querySelector('[data-paymos-checkout-error]');
        if (!notice) {
            notice = document.createElement('p');
            notice.setAttribute('data-paymos-checkout-error', '');
            notice.setAttribute('role', 'alert');
            form.prepend(notice);
        }
        notice.textContent = config.unavailable;
    }

    function prepareLegacy(event, form, button) {
        if (!form || !form.matches('form.give-form') || !usesPaymos(form)) {
            return;
        }

        const field = form.querySelector(legacyField);
        if (!field) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showUnavailable(form);

            return;
        }
        const formId = Number(field.dataset.paymosGivewpForm);
        const state = attempts.get(formId);
        if (state && hasUsableToken(state.result) && field.value === state.result.token) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const pending = pendingLegacyForms;
        if (pending.has(form)) {
            return;
        }
        pending.add(form);
        prepare(formId).then(token => {
            // GiveWP can replace the fields, and the donor can leave the form during bootstrap.
            const currentField = form.querySelector(legacyField);
            if (pending !== pendingLegacyForms || !usesPaymos(form) || !currentField || Number(currentField.dataset.paymosGivewpForm) !== formId) {
                return;
            }

            currentField.value = token;
            const notice = form.querySelector('[data-paymos-checkout-error]');
            if (notice) {
                notice.remove();
            }
            const submit = button && button.isConnected && button.form === form ? button : form.querySelector(legacySubmit);
            if (submit) {
                submit.click();
            } else if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            }
        }).catch(() => {
            if (pending === pendingLegacyForms) {
                showUnavailable(form);
            }
        }).finally(() => pending.delete(form));
    }

    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }
        const button = target.closest(legacySubmit);
        if (button) {
            prepareLegacy(event, button.form, button);
        }
    }, true);

    document.addEventListener('submit', event => {
        prepareLegacy(event, event.target, event.submitter);
    }, true);

    window.addEventListener('pagehide', () => {
        activeDocument = false;
        attempts.clear();
        pendingLegacyForms = new WeakSet();
        for (const field of document.querySelectorAll(legacyField)) {
            field.value = '';
        }
    });

    window.addEventListener('pageshow', event => {
        activeDocument = true;
        if (event.persisted) {
            renderRecoveryHints();
        }
    });

    const status = document.querySelector('[data-paymos-recovery-status]');
    if (status) {
        retireRecovery(status.dataset.paymosRecoveryStatus);
    }

    // GiveWP renders Visual in its own document. Keep the hint outside its React root.
    const visualRoot = document.getElementById('root-givewp-donation-form');
    const visualForm = Number(config.visualFormId);
    if (visualRoot && validForm(visualForm)) {
        const mount = document.createElement('p');
        mount.dataset.paymosRecoveryForm = String(visualForm);
        visualRoot.before(mount);
    }
    renderRecoveryHints();

    window.paymosGivewpCheckout = { prepare };
})();
