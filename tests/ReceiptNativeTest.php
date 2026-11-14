<?php

declare(strict_types=1);

/**
 * Explicit .15 QA only: php ReceiptNativeTest.php contract|http /private/context.json
 *
 * Required private context: owner=paymos-givewp-receipt-6.1, authorized=true,
 * expires_at, wordpress_root, expected_table_prefix (giveqa_receipt_*), source_url,
 * checkout_sha256. Contract also requires disposable_session=true.
 * HTTP also requires cookie_jar, handle, donation_id, expected_principal, form_url,
 * transport_audit_path, evidence_path. It consumes an already genuine paid donation;
 * it never creates/marks a payment, connects a merchant or repairs an old cookie.
 * Optional HTTP-only pair: qa_tls_gateway_ip='172.31.0.2', qa_tls_gateway_port=443.
 * Run via invoke-logged-test.ps1; heavy setup uses the node flock, receipt HTTP the owned case mutex.
 */

function receiptCheck(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function receiptPrivateFile(string $path): void
{
    receiptCheck(is_file($path) && !is_link($path) && (fileperms($path) & 0077) === 0, 'Private QA file permissions required.');
}

function receiptOrigin(string $url): string
{
    $parts = parse_url($url);
    receiptCheck(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
        && (!isset($parts['port']) || $parts['port'] === 443), 'Exact HTTPS native origin required.');

    return 'https://' . strtolower($parts['host']);
}

/** Fixed owned QA transport only; original URL, TLS hostname and cookies remain authoritative. */
function receiptTlsOptions(array $context): array
{
    $origin = receiptOrigin($context['source_url']);
    $hasIp = array_key_exists('qa_tls_gateway_ip', $context);
    $hasPort = array_key_exists('qa_tls_gateway_port', $context);
    if (!$hasIp && !$hasPort) {
        return [];
    }
    receiptCheck($hasIp && $hasPort && $context['qa_tls_gateway_ip'] === '172.31.0.2'
        && $context['qa_tls_gateway_port'] === 443, 'Only the complete fixed owned QA TLS pair is allowed.');
    receiptCheck(defined('CURLOPT_CONNECT_TO'), 'Pinned native TLS requires curl CONNECT_TO support.');
    receiptCheck(is_string($context['ca_bundle'] ?? null), 'Pinned native TLS requires the private approved CA bundle.');
    receiptPrivateFile($context['ca_bundle']);

    return [CURLOPT_CONNECT_TO => [parse_url($origin, PHP_URL_HOST) . ':443:172.31.0.2:443'], CURLOPT_PROXY => ''];
}

function receiptCookies(string $jar, string $source): array
{
    receiptPrivateFile($jar);
    $host = (string) parse_url($source, PHP_URL_HOST);
    $cookies = [];
    foreach (file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, '#HttpOnly_') === 0) {
            $line = substr($line, 10);
        } elseif ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode("\t", $line);
        receiptCheck(count($parts) === 7, 'Invalid private Netscape cookie jar.');
        [$domain, $subdomains, $path, $secure, $expires, $name, $value] = $parts;
        $domain = ltrim($domain, '.');
        if ($domain !== $host && !($subdomains === 'TRUE' && substr($host, -strlen('.' . $domain)) === '.' . $domain)) {
            continue;
        }
        if ($expires !== '0' && (int) $expires <= time()) {
            continue;
        }
        $nativeGiveCookie = preg_match('/\Awp-give_session(?:_reset_nonce)?_[a-f0-9]{32}\z/', $name) === 1;
        receiptCheck($path === '/' && ($secure === 'TRUE' || ($secure === 'FALSE' && $nativeGiveCookie)),
            'Native test requires the original root-path cookie and a secure Paymos cookie.');
        $cookies[$name] = urldecode($value);
    }
    receiptCheck($cookies !== [], 'Original donor cookie is missing.');

    return $cookies;
}

function receiptBootstrap(array $context, bool $http): void
{
    $source = $context['source_url'];
    receiptOrigin($source);
    $_COOKIE = $http ? receiptCookies($context['cookie_jar'], $source) : [];
    $_SERVER['HTTP_HOST'] = parse_url($source, PHP_URL_HOST);
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $config = file_get_contents(rtrim($context['wordpress_root'], '/') . '/wp-config.php');
    foreach (['DISABLE_WP_CRON', 'WP_HTTP_BLOCK_EXTERNAL'] as $flag) {
        receiptCheck(is_string($config) && strpos($config, "define('" . $flag . "', true);") !== false,
            'Owned native config must disable cron and external HTTP before bootstrap.');
    }
    require rtrim($context['wordpress_root'], '/') . '/wp-load.php';
    receiptCheck(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true
        && defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL === true, 'Native isolation flags differ.');
    global $wpdb;
    receiptCheck(defined('GIVE_VERSION') && GIVE_VERSION === '4.19.1.1', 'Pinned GiveWP version differs.');
    receiptCheck(preg_match('/\Agiveqa_receipt_[a-z0-9_]+_\z/', $context['expected_table_prefix']) === 1
        && $wpdb->prefix === $context['expected_table_prefix'], 'Only the new owned receipt fixture is allowed.');
    receiptCheck(rtrim(home_url(), '/') === rtrim($source, '/'), 'Native source differs from the owned context.');
    $file = (new ReflectionClass(\PaymosGivewp\Checkout::class))->getFileName();
    receiptCheck(is_string($file) && hash_equals($context['checkout_sha256'], hash_file('sha256', $file)), 'Installed Checkout source differs.');
    add_filter('pre_http_request', static function () {
        throw new RuntimeException('A receipt contract/observation attempted external HTTP.');
    }, -999);
}

function receiptContract(array $context): array
{
    receiptCheck(($context['disposable_session'] ?? false) === true, 'Disposable native session authorization required.');
    receiptBootstrap($context, false);
    $before = [give()->session->get('give_purchase'), give()->session->get('give_email')];
    $payload = [
        'donation_id' => 101, 'price' => '12.68', 'purchase_key' => 'synthetic-native-receipt-contract',
        'user_email' => 'native-contract@example.invalid', 'date' => '2026-11-13 10:11:12', 'gateway' => 'paymos',
        'user_info' => [
            'id' => 0, 'first_name' => 'Original', 'last_name' => 'Donor', 'title' => '', 'email' => 'native-contract@example.invalid',
            'address' => ['line1' => 'Contract address', 'line2' => '', 'city' => '', 'state' => '', 'country' => '', 'postalCode' => '00123'],
        ],
        'post_data' => ['give-form-id' => '22', 'give-amount' => '12.68', 'give-first' => 'Original', 'give-last' => 'Donor', 'give-email' => 'native-contract@example.invalid', 'give-user-id' => '0', 'give-gateway' => 'paymos'],
        'card_info' => ['card_address' => 'Contract address', 'card_address_2' => '', 'card_city' => '', 'card_state' => '', 'card_country' => '', 'card_zip' => '00123'],
    ];
    $rejected = false;
    try {
        give_set_purchase_session($payload);
        receiptCheck(give_get_purchase_session() === $payload && give()->session->get('give_email') === 'native-contract@example.invalid', 'Actual public setter did not persist both fields.');
        $reader = (new \Give\Session\SessionDonation\DonationAccessor())->get();
        receiptCheck($reader->id === 101 && $reader->purchaseKey === 'synthetic-native-receipt-contract'
            && $reader->totalAmount === '12.68' && $reader->donorInfo->wpUserId === 0 && $reader->donorInfo->firstName === 'Original'
            && $reader->donorInfo->address->postalCode === '00123' && (int) $reader->formEntry->formId === 22
            && (int) $reader->formEntry->wpUserId === 0 && $reader->cardInfo->address->line1 === 'Contract address'
            && $reader->cardInfo->address->postalCode === '00123', 'Actual native reader mapping differs.');
        foreach (['filtered:interim-key', 'other-donation-looking-key'] as $rawKey) {
            $producer = $payload;
            $producer['purchase_key'] = $rawKey;
            $producer['price'] = 12.68;
            $producer['user_info']['id'] = 86;
            $producer['user_info']['address'] = false;
            unset($producer['post_data']['give-user-id']);
            $producer['receipt_extension'] = ['native' => 'preserved'];
            give_set_purchase_session($producer);
            $reader = (new \Give\Session\SessionDonation\DonationAccessor())->get();
            receiptCheck(give_get_purchase_session() === $producer && give()->session->get('give_email') === $producer['user_email']
                && $reader->purchaseKey === $rawKey && $reader->donorInfo->wpUserId === 86
                && $reader->formEntry->wpUserId === null, 'Actual native producer shape/raw key was not preserved.');
        }
        // Reproduce the old malformed write only inside this disposable synthetic native session.
        give()->session->set('give_purchase', ['donation_id' => 101, 'purchase_key' => 'synthetic-native-receipt-contract']);
        try {
            (new \Give\Session\SessionDonation\DonationAccessor())->get();
        } catch (Throwable $error) {
            $rejected = true;
        }
        receiptCheck($rejected, 'Native negative control accepted the old incomplete session.');
    } finally {
        give()->session->set('give_purchase', $before[0]);
        give()->session->set('give_email', $before[1]);
    }
    receiptCheck([give()->session->get('give_purchase'), give()->session->get('give_email')] === $before, 'Disposable session was not restored.');

    return ['mode' => 'contract', 'result' => 'PASS', 'syntheticNativeContract' => true, 'actualSetterAndAccessor' => true,
        'nativeProducerRawKeysReadBack' => true, 'incompletePayloadRejected' => $rejected, 'originalDisposableSessionRestored' => true, 'genuineSandboxClaim' => false, 'apiCalls' => 0];
}

function receiptObserve(array $context): array
{
    receiptBootstrap($context, true);
    global $wpdb;
    $row = \PaymosGivewp\Checkout::status($context['handle']);
    receiptCheck($row !== null && $row['donation_id'] === $context['donation_id'] && $row['form_version'] === 2
        && $row['status'] === 'verified_paid' && $row['source'] === $context['source_url']
        && $row['environment'] === 'sandbox' && $row['native_mode'] === 'test'
        && $row['principal_id'] === $context['expected_principal'] && get_current_user_id() === $context['expected_principal'], 'Existing genuine legacy donation binding is not ready.');
    $donation = \Give\Donations\Models\Donation::find($row['donation_id']);
    receiptCheck($donation instanceof \Give\Donations\Models\Donation && $donation->status->isComplete()
        && $donation->mode->getValue() === 'test' && $donation->gatewayTransactionId === $row['invoice_id']
        && \PaymosGivewp\Checkout::matchesDonation($row, $donation), 'Original native paid donation differs.');
    $projection = \PaymosGivewp\EventStore::projection($row['id'], 'paid');
    receiptCheck(is_array($projection) && $projection['stage'] === 'done', 'Natural paid projection is not complete.');
    $events = $wpdb->get_results($wpdb->prepare('SELECT event_key,body_hash,status FROM %i WHERE attempt_id=%d ORDER BY event_key', \PaymosGivewp\EventStore::eventsTable(), $row['id']), ARRAY_A);
    $notes = $wpdb->get_results($wpdb->prepare('SELECT comment_ID,comment_content FROM %i WHERE comment_parent=%d AND comment_type=%s ORDER BY comment_ID', $wpdb->prefix . 'give_comments', $row['donation_id'], 'donation'), ARRAY_A);
    $attemptCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . \PaymosGivewp\AttemptStore::table());
    $refunds = [\PaymosGivewp\RefundIntentStore::byAttempt($row['id'], 'full'), \PaymosGivewp\RefundIntentStore::byAttempt($row['id'], 'overpayment')];
    receiptCheck($wpdb->last_error === '', 'Native readback query failed.');
    $purchase = give_get_purchase_session();
    $missing = $purchase === null || $purchase === false || $purchase === [];
    $readerProof = null;
    if (!$missing) {
        receiptCheck(is_array($purchase) && is_string($purchase['purchase_key'] ?? null)
            && $purchase['purchase_key'] !== '' && !is_serialized($purchase['purchase_key']), 'Native raw key is malformed.');
        $reader = (new \Give\Session\SessionDonation\DonationAccessor())->get();
        receiptCheck((int) $reader->id === $row['donation_id'] && $reader->purchaseKey === $purchase['purchase_key']
            && \PaymosGivewpVendor\Paymos\Plugin\AmountGuard::amountsEqual($reader->totalAmount, $row['amount'])
            && $reader->donorEmail === $donation->email && (int) $reader->formEntry->formId === $row['form_id'], 'Native receipt reader points to a different donation.');
        $donor = \Give\Donors\Models\Donor::find($donation->donorId);
        receiptCheck($donor !== null, 'Same native donor is missing.');
        $identity = (int) $reader->donorInfo->wpUserId;
        receiptCheck($identity === $row['principal_id'] || ($row['principal_id'] === 0 && $identity === $donor->userId), 'Native donor identity is foreign.');
        receiptCheck($row['principal_id'] === 0 ? in_array($reader->formEntry->wpUserId, [null, 0, '0'], true)
            : (string) $reader->formEntry->wpUserId === (string) $row['principal_id'], 'Native form principal was promoted.');
        $readerProof = ['originalDonationAmountEmailForm' => true, 'currentPrincipalUnchanged' => true,
            'canonicalPurchaseHashVerified' => true, 'readerMatchesRawKey' => true,
            'rawKeyEqualsPersisted' => $purchase['purchase_key'] === $donation->purchaseKey,
            'knownDonorGuestVariant' => $row['principal_id'] === 0 && $identity > 0,
            'postPrincipalMissing' => $reader->formEntry->wpUserId === null, 'priceType' => gettype($purchase['price']),
            'addressType' => gettype($purchase['user_info']['address']), 'payloadSha256' => hash('sha256', serialize($purchase))];
    }

    return ['mode' => 'observation', 'paidBindingVerified' => true, 'missingPurchase' => $missing, 'nativeReader' => $readerProof,
        'stateSha256' => hash('sha256', serialize([$row['invoice_id'], $donation->status->getValue(), $projection, $events, $notes, $refunds, $attemptCount])),
        'attemptCount' => $attemptCount, 'eventCount' => count($events), 'noteCount' => count($notes),
        'emailSha256' => hash('sha256', serialize(give()->session->get('give_email'))),
        'auditSha256' => hash_file('sha256', $context['transport_audit_path'])];
}

function receiptObservationChild(string $contextPath): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'http', $contextPath, '--observe'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    receiptCheck(is_resource($process), 'Could not start fresh native readback.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '') {
        // Keep the actual diagnostic private, including unexpected native exception details.
        $context = json_decode(file_get_contents($contextPath), true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = $context['evidence_path'] . '.observation-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($diagnostic, json_encode(['exit' => $exit, 'stdout' => $output, 'stderr' => $errors], JSON_THROW_ON_ERROR));
        chmod($diagnostic, 0600);
        throw new RuntimeException('Fresh native readback failed; private observation diagnostic retained.');
    }
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    receiptCheck(is_array($result) && ($result['paidBindingVerified'] ?? false) === true, 'Native readback did not verify the original binding.');

    return $result;
}

function receiptHttp(array $context, string $contextPath): array
{
    $tlsOptions = receiptTlsOptions($context); // Validate before bootstrap or any HTTP.
    receiptCheck(extension_loaded('curl') && class_exists(DOMDocument::class), 'Native HTTP test needs existing curl and DOM extensions.');
    receiptPrivateFile($context['cookie_jar']);
    $source = rtrim($context['source_url'], '/');
    $origin = receiptOrigin($source);
    receiptCheck(receiptOrigin($context['form_url']) === $origin && !file_exists($context['evidence_path']), 'Same-site form and a new private result path are required.');
    $records = [];
    $save = static function () use (&$records, $context): void {
        file_put_contents($context['evidence_path'], json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($context['evidence_path'], 0600);
    };
    $request = static function (string $stage, string $url, ?array $post = null) use ($context, $origin, $tlsOptions, &$records, $save): array {
        receiptCheck(receiptOrigin($url) === $origin, 'Foreign native redirect/request refused.');
        $records[] = ['stage' => $stage, 'phase' => 'started', 'method' => $post === null ? 'GET' : 'POST', 'at' => gmdate('c')];
        $save();
        $headers = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_COOKIEFILE => $context['cookie_jar'], CURLOPT_COOKIEJAR => $context['cookie_jar'],
            CURLOPT_HTTPHEADER => ['Origin: ' . $origin, 'Sec-Fetch-Site: same-origin'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); }
                return strlen($line);
            },
        ]);
        if ($tlsOptions !== []) {
            receiptCheck(curl_setopt_array($curl, $tlsOptions), 'Pinned native TLS routing could not be applied.');
        }
        if (isset($context['ca_bundle'])) { curl_setopt($curl, CURLOPT_CAINFO, $context['ca_bundle']); }
        if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        receiptCheck(is_string($body) && $errno === 0, 'Native HTTPS request failed; no automatic retry.');
        $records[] = ['stage' => $stage, 'phase' => 'response', 'status' => $status, 'bytes' => strlen($body), 'bodySha256' => hash('sha256', $body), 'at' => gmdate('c')];
        $save();

        return [$status, $headers, $body];
    };
    $before = receiptObservationChild($contextPath);
    if (($context['expect_known_donor_guest'] ?? false) === true) {
        receiptCheck(!$before['missingPurchase'] && $before['nativeReader']['knownDonorGuestVariant']
            && $before['nativeReader']['postPrincipalMissing'], 'The actual known-donor guest producer variant was not captured.');
    }
    $preserved = $before;
    for ($repeat = 0; $repeat < 2; ++$repeat) {
        $prefix = $repeat === 0 ? '' : 'repeat-';
        $statusUrl = $source . '/?paymos_givewp=' . rawurlencode($context['handle']);
        [$status, , $body] = $request($prefix . 'current-local-page', $statusUrl);
        receiptCheck($status === 200, 'Original donor status page is unavailable.');
        $previousErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($dom);
        $forms = $xpath->query('//form[translate(@method,"POST","post")="post"][input[@name="paymos_action" and @value="receipt"]]');
        receiptCheck($forms->length === 1, 'A single genuine receipt form is required.');
        $nonces = $xpath->query('.//input[@name="paymos_csrf"]', $forms->item(0));
        receiptCheck($nonces->length === 1, 'Receipt purpose nonce is missing.');
        $nonce = $nonces->item(0)->getAttribute('value');
        receiptCheck(preg_match('/\A[a-f0-9]{64}\z/', $nonce) === 1, 'Invalid actual receipt nonce.');
        [$status, $headers] = $request($prefix . 'receipt', $statusUrl, ['paymos_action' => 'receipt', 'paymos_csrf' => $nonce]);
        receiptCheck($status === 303 && isset($headers['location']), 'Native receipt did not redirect successfully.');
        $confirmation = $headers['location'];
        receiptCheck(receiptOrigin($confirmation) === $origin, 'Foreign receipt redirect refused.');
        foreach (['confirmation' => $confirmation, 'home' => $source . '/', 'legacy-form' => $context['form_url']] as $stage => $url) {
            [$status] = $request($prefix . $stage, $url);
            receiptCheck($status === 200, 'Same-cookie native page failed: ' . $stage);
        }

        $after = receiptObservationChild($contextPath);
        receiptCheck(!$after['missingPurchase'] && $after['nativeReader'] !== null, 'Native receipt session is absent after HTTP.');
        receiptCheck($before['stateSha256'] === $after['stateSha256'] && $before['auditSha256'] === $after['auditSha256'], 'Receipt changed financial/native state or made outbound API calls.');
        if (!$preserved['missingPurchase']) {
            receiptCheck($preserved['nativeReader']['payloadSha256'] === $after['nativeReader']['payloadSha256']
                && $preserved['emailSha256'] === $after['emailSha256'], 'Valid native purchase/email session was rewritten.');
        } else {
            receiptCheck($after['nativeReader']['rawKeyEqualsPersisted'] === true, 'Reconstructed missing purchase did not use the canonical key.');
        }
        $preserved = $after;
    }
    $result = ['mode' => 'http', 'result' => 'PASS', 'sameCookiePages' => true, 'nativeReader' => $after['nativeReader'],
        'unchangedFinancialState' => true, 'noOutboundApi' => true, 'preservedExistingPayload' => !$before['missingPurchase'],
        'repeatSameCookiePassed' => true, 'emailPreserved' => !$before['missingPurchase'],
        'tlsGatewayPinned' => $tlsOptions !== [], 'genuinePaidFixtureConsumed' => true, 'newFinancialEffects' => 0, 'manualUIClaim' => false, 'records' => $records];
    $records[] = $result;
    $save();

    return $result;
}

try {
    receiptCheck(PHP_SAPI === 'cli' && DIRECTORY_SEPARATOR === '/' && $argc >= 3
        && in_array($argv[1], ['contract', 'http'], true), 'Use the explicit logged native CLI mode on .15.');
    receiptPrivateFile($argv[2]);
    $context = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    receiptCheck(is_array($context) && ($context['owner'] ?? '') === 'paymos-givewp-receipt-6.1'
        && ($context['authorized'] ?? false) === true && is_int($context['expires_at'] ?? null)
        && $context['expires_at'] > time(), 'Current owned QA context authorization required.');
    foreach (['wordpress_root', 'expected_table_prefix', 'source_url', 'checkout_sha256'] as $key) {
        receiptCheck(is_string($context[$key] ?? null) && $context[$key] !== '', 'Incomplete private native context.');
    }
    receiptCheck(preg_match('/\A[a-f0-9]{64}\z/', $context['checkout_sha256']) === 1, 'Exact Checkout source hash required.');
    if ($argv[1] === 'http') {
        receiptTlsOptions($context); // Invalid/partial pairs fail before any native bootstrap or network call.
        foreach (['cookie_jar', 'handle', 'form_url', 'transport_audit_path', 'evidence_path'] as $key) {
            receiptCheck(is_string($context[$key] ?? null) && $context[$key] !== '', 'Incomplete private HTTP context.');
        }
        receiptCheck(is_int($context['donation_id'] ?? null) && $context['donation_id'] > 0
            && is_int($context['expected_principal'] ?? null) && $context['expected_principal'] >= 0
            && preg_match('/\A[a-f0-9]{64}\z/', $context['handle']) === 1, 'Exact native donation binding required.');
        receiptPrivateFile($context['transport_audit_path']);
    }
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    if (($argv[3] ?? '') === '--observe') {
        receiptCheck($argv[1] === 'http', 'Observation belongs only to HTTP mode.');
        $result = receiptObserve($context);
    } else {
        receiptCheck($argc === 3, 'Unknown native test arguments.');
        $result = $argv[1] === 'contract' ? receiptContract($context) : receiptHttp($context, $argv[2]);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    // Private context and HTTP bodies never belong in the console/test journal.
    fwrite(STDERR, json_encode(['result' => 'FAIL', 'type' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
