<?php

namespace App\Controllers;

use App\Database;
use PDO;

class PaymentController {

    private const PRICE_PER_TOKEN_CENTS = 20; // $0.20 per token = 5 tokens per $1.00 USD
    private const TOKENS_PER_DOLLAR = 5;
    private const MIN_TOKENS = 5;             // $1.00 minimum purchase
    private const MAX_TOKENS = 2500;          // $500.00 single-order cap

    /**
     * =========================================================================
     * STRIPE CREDENTIALS & CONFIGURATION NOTE
     * =========================================================================
     * All Stripe API keys live in the backend .env file (NOT committed to git):
     *   c:\Program Files\Ampps\www\pantry-rebuild-api\.env   (local)
     *   <api-root>/.env                                      (production)
     *
     * To rotate keys or switch between Test Mode (sk_test_...) and Live Mode
     * (sk_live_...), edit these variables in .env:
     *   - STRIPE_SECRET_KEY       (Stripe Secret Key: sk_test_... or sk_live_...)
     *   - STRIPE_PUBLISHABLE_KEY  (Stripe Publishable Key: pk_test_... or pk_live_...)
     *   - STRIPE_WEBHOOK_SECRET   (Webhook Signing Secret: whsec_...)
     *   - FRONTEND_URL            (React app base URL for Stripe return redirects)
     *
     * To accept Crypto alongside Cards in Stripe Checkout:
     *   Enable "Crypto" in your Stripe Dashboard -> Settings -> Payment methods -> Crypto.
     * =========================================================================
     */
    private function getStripeConfig(): array {
        $env = Database::loadEnv();

        $secretKey = trim((string)($env['STRIPE_SECRET_KEY'] ?? ''));
        $publishableKey = trim((string)($env['STRIPE_PUBLISHABLE_KEY'] ?? ''));
        $webhookSecret = trim((string)($env['STRIPE_WEBHOOK_SECRET'] ?? ''));
        $frontendUrl = rtrim(trim((string)($env['FRONTEND_URL'] ?? '')), '/');
        $cryptoPmcId = trim((string)($env['STRIPE_CRYPTO_PMC_ID'] ?? ''));
        $productTaxCode = trim((string)($env['STRIPE_PRODUCT_TAX_CODE'] ?? 'txcd_10103000'));
        $managedEnv = strtolower(trim((string)($env['STRIPE_MANAGED_PAYMENTS'] ?? 'true')));
        $managedPaymentsEnabled = !in_array($managedEnv, ['false', '0', 'off', 'no'], true);

        $isPlaceholder = ($secretKey === '' || stripos($secretKey, 'REPLACE_WITH') !== false);

        return [
            'secret_key' => $secretKey,
            'publishable_key' => $publishableKey,
            'webhook_secret' => $webhookSecret,
            'frontend_url' => $frontendUrl,
            'crypto_pmc_id' => $cryptoPmcId,
            'product_tax_code' => $productTaxCode,
            'managed_payments_enabled' => $managedPaymentsEnabled,
            'is_configured' => !$isPlaceholder,
        ];
    }

    private function getBundles(): array {
        return [
            ['id' => 'starter', 'label' => 'Starter Pack', 'tokens' => 5, 'amountCents' => 100, 'priceDisplay' => '$1.00', 'badge' => null],
            ['id' => 'chef', 'label' => "Chef's Stash", 'tokens' => 25, 'amountCents' => 500, 'priceDisplay' => '$5.00', 'badge' => null],
            ['id' => 'masterpiece', 'label' => 'Masterpiece Pack', 'tokens' => 50, 'amountCents' => 1000, 'priceDisplay' => '$10.00', 'badge' => 'Popular'],
            ['id' => 'legend', 'label' => 'Vault Legend', 'tokens' => 100, 'amountCents' => 2000, 'priceDisplay' => '$20.00', 'badge' => 'Best Value'],
        ];
    }

    private function ensureTablesExist(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS token_purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                stripe_session_id VARCHAR(255) NOT NULL UNIQUE,
                stripe_payment_intent VARCHAR(255) DEFAULT NULL,
                tokens_purchased DECIMAL(10, 2) NOT NULL,
                amount_cents INT NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'usd',
                payment_method_hint VARCHAR(32) NOT NULL DEFAULT 'card',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function resolveFrontendUrl(array $config): string {
        if (!empty($config['frontend_url'])) {
            return $config['frontend_url'];
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = [
            'https://veggievault.chrisandemmashow.com',
            'https://www.veggievault.chrisandemmashow.com',
            'http://localhost:5173',
        ];
        if (in_array($origin, $allowedOrigins, true)) {
            return rtrim($origin, '/');
        }

        return 'https://veggievault.chrisandemmashow.com';
    }

    private function resolveCaBundlePath(): ?string {
        $configured = trim((string)ini_get('curl.cainfo')) ?: trim((string)ini_get('openssl.cafile'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'C:\\Program Files\\Ampps\\php82\\extras\\ssl\\cacert.pem',
            'C:\\Program Files\\Ampps\\php\\extras\\ssl\\cacert.pem',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function stripeRequest(string $method, string $path, string $secretKey, array $params = []): array {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'PHP cURL extension is required to contact Stripe.',
                'data' => null,
            ];
        }

        $url = 'https://api.stripe.com/v1' . $path;
        $ch = curl_init();

        $headers = ['Accept: application/json'];
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
        ];
        $caBundle = $this->resolveCaBundlePath();
        if ($caBundle !== null) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOpts);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'ok' => false,
                'status' => 502,
                'error' => 'Could not reach Stripe API: ' . $curlError,
                'data' => null,
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $httpCode ?: 502,
                'error' => 'Received an unexpected response from Stripe.',
                'data' => null,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
            $stripeMsg = $decoded['error']['message'] ?? 'Stripe rejected the request.';
            return [
                'ok' => false,
                'status' => $httpCode ?: 400,
                'error' => $stripeMsg,
                'data' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'status' => $httpCode,
            'error' => null,
            'data' => $decoded,
        ];
    }

    private function fulfillPurchaseSession(PDO $db, array $session): array {
        $sessionId = trim((string)($session['id'] ?? ''));
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $userId = (int)($metadata['user_id'] ?? $session['client_reference_id'] ?? 0);
        $tokens = (float)($metadata['tokens'] ?? 0);
        $amountCents = (int)($session['amount_total'] ?? ($tokens * self::PRICE_PER_TOKEN_CENTS));
        $paymentIntent = is_string($session['payment_intent'] ?? null)
            ? $session['payment_intent']
            : ($session['payment_intent']['id'] ?? null);
        $paymentMethodHint = trim((string)($metadata['payment_method_hint'] ?? 'card')) ?: 'card';

        if ($tokens <= 0 && $amountCents > 0) {
            $tokens = round($amountCents / self::PRICE_PER_TOKEN_CENTS, 2);
        }

        if ($sessionId === '' || $userId <= 0 || $tokens <= 0) {
            return [
                'success' => false,
                'message' => 'Missing user or token metadata on the Stripe session.',
            ];
        }

        $startedTx = !$db->inTransaction();
        if ($startedTx) {
            $db->beginTransaction();
        }

        try {
            $lockStmt = $db->prepare("
                SELECT id, status, tokens_purchased
                FROM token_purchases
                WHERE stripe_session_id = :sid
                LIMIT 1
                FOR UPDATE
            ");
            $lockStmt->execute([':sid' => $sessionId]);
            $existing = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] === 'completed') {
                $balStmt = $db->prepare("SELECT generation_tokens FROM users WHERE id = :uid LIMIT 1");
                $balStmt->execute([':uid' => $userId]);
                $currentBalance = (float)$balStmt->fetchColumn();

                if ($startedTx) {
                    $db->commit();
                }

                return [
                    'success' => true,
                    'alreadyCredited' => true,
                    'tokensAdded' => (float)$existing['tokens_purchased'],
                    'tokensRemaining' => $currentBalance,
                    'message' => 'Your tokens have already been added to your Vault balance!',
                ];
            }

            if ($existing) {
                $updPurchase = $db->prepare("
                    UPDATE token_purchases
                    SET status = 'completed',
                        stripe_payment_intent = :pi,
                        completed_at = NOW()
                    WHERE id = :id
                ");
                $updPurchase->execute([
                    ':pi' => $paymentIntent,
                    ':id' => $existing['id'],
                ]);
            } else {
                $insPurchase = $db->prepare("
                    INSERT INTO token_purchases
                        (user_id, stripe_session_id, stripe_payment_intent, tokens_purchased, amount_cents, currency, payment_method_hint, status, completed_at)
                    VALUES
                        (:uid, :sid, :pi, :tokens, :amount, :currency, :pm_hint, 'completed', NOW())
                ");
                $insPurchase->execute([
                    ':uid' => $userId,
                    ':sid' => $sessionId,
                    ':pi' => $paymentIntent,
                    ':tokens' => $tokens,
                    ':amount' => $amountCents,
                    ':currency' => strtolower((string)($session['currency'] ?? 'usd')),
                    ':pm_hint' => $paymentMethodHint,
                ]);
            }

            $creditStmt = $db->prepare("UPDATE users SET generation_tokens = generation_tokens + :tokens WHERE id = :uid");
            $creditStmt->execute([
                ':tokens' => $tokens,
                ':uid' => $userId,
            ]);

            $balStmt = $db->prepare("SELECT generation_tokens FROM users WHERE id = :uid LIMIT 1");
            $balStmt->execute([':uid' => $userId]);
            $newBalance = (float)$balStmt->fetchColumn();

            if ($startedTx) {
                $db->commit();
            }

            return [
                'success' => true,
                'credited' => true,
                'tokensAdded' => $tokens,
                'tokensRemaining' => $newBalance,
                'message' => "Top-up complete! {$tokens} tokens added to your Vault.",
            ];
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * GET /payments/config
     * Returns token pricing tiers, Stripe readiness, current user token balance, and recent purchases.
     */
    public function getPaymentConfig() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = Database::connect();
        $this->ensureTablesExist($db);
        $config = $this->getStripeConfig();

        $userId = $_SESSION['user_id'] ?? null;
        $currentTokens = null;
        $purchases = [];

        if ($userId) {
            $balStmt = $db->prepare("SELECT generation_tokens FROM users WHERE id = :uid LIMIT 1");
            $balStmt->execute([':uid' => $userId]);
            $val = $balStmt->fetchColumn();
            if ($val !== false) {
                $currentTokens = (float)$val;
                $_SESSION['generation_tokens'] = $currentTokens;
            }

            $histStmt = $db->prepare("
                SELECT id, tokens_purchased, amount_cents, currency, payment_method_hint, status, created_at, completed_at
                FROM token_purchases
                WHERE user_id = :uid AND status = 'completed'
                ORDER BY COALESCE(completed_at, created_at) DESC, id DESC
                LIMIT 15
            ");
            $histStmt->execute([':uid' => $userId]);
            $purchases = $histStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'pricePerTokenCents' => self::PRICE_PER_TOKEN_CENTS,
            'tokensPerDollar' => self::TOKENS_PER_DOLLAR,
            'minTokens' => self::MIN_TOKENS,
            'maxTokens' => self::MAX_TOKENS,
            'bundles' => $this->getBundles(),
            'stripeConfigured' => $config['is_configured'],
            'publishableKey' => $config['is_configured'] ? $config['publishable_key'] : null,
            'currentTokens' => $currentTokens,
            'purchases' => $purchases,
        ]);
    }

    /**
     * POST /payments/create-checkout-session
     * Creates a hosted Stripe Checkout Session for buying tokens via Card or Crypto.
     */
    public function createCheckoutSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in to purchase tokens.']);
            return;
        }

        $config = $this->getStripeConfig();
        if (!$config['is_configured']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'code' => 'STRIPE_NOT_CONFIGURED',
                'message' => 'Stripe credentials have not been configured yet. Add STRIPE_SECRET_KEY and STRIPE_PUBLISHABLE_KEY in pantry-rebuild-api/.env.',
            ]);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $tokens = (int)($data['tokens'] ?? 0);
        if ($tokens < self::MIN_TOKENS || $tokens > self::MAX_TOKENS) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please choose between ' . self::MIN_TOKENS . ' and ' . self::MAX_TOKENS . ' tokens (5 tokens per $1.00 USD, minimum $1.00).',
            ]);
            return;
        }

        $paymentMethod = (($data['paymentMethod'] ?? 'card') === 'crypto') ? 'crypto' : 'card';

        $db = Database::connect();
        $this->ensureTablesExist($db);

        $userStmt = $db->prepare("SELECT email FROM users WHERE id = :uid LIMIT 1");
        $userStmt->execute([':uid' => $userId]);
        $userEmail = trim((string)($userStmt->fetchColumn() ?: ($_SESSION['email'] ?? '')));

        $frontendUrl = $this->resolveFrontendUrl($config);
        $amountCents = $tokens * self::PRICE_PER_TOKEN_CENTS;

        $productData = [
            'name' => "Veggie Vault Generation Tokens ({$tokens} Tokens)",
            'description' => 'Unlock Emma Masterpiece Recipes, AI Dish Photos, Deep Dive Nutrition, and Live Audio Sessions ($0.20/token — 5 tokens per $1).',
        ];
        if ($config['product_tax_code'] !== '') {
            $productData['tax_code'] = $config['product_tax_code'];
        }

        $basePayload = [
            'mode' => 'payment',
            'success_url' => $frontendUrl . '/buy-tokens?session_id={CHECKOUT_SESSION_ID}&status=success',
            'cancel_url' => $frontendUrl . '/buy-tokens?status=cancelled',
            'client_reference_id' => (string)$userId,
            'metadata' => [
                'user_id' => (string)$userId,
                'tokens' => (string)$tokens,
                'payment_method_hint' => $paymentMethod,
            ],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => self::PRICE_PER_TOKEN_CENTS,
                        'product_data' => $productData,
                    ],
                    'quantity' => $tokens,
                ],
            ],
        ];

        if (!$config['managed_payments_enabled']) {
            $basePayload['managed_payments'] = ['enabled' => 'false'];
        }

        if ($userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $basePayload['customer_email'] = $userEmail;
        }

        $fallbackUsed = false;
        $payload = $basePayload;
        if ($paymentMethod === 'crypto' && !empty($config['crypto_pmc_id'])) {
            // Optional Stripe Dashboard Payment Method Configuration ID for crypto-first checkout
            $payload['payment_method_configuration'] = $config['crypto_pmc_id'];
        }

        // Dynamic Payment Methods are managed in Stripe Dashboard -> Settings -> Payment methods
        // (do not pass legacy payment_method_types, which Stripe rejects on modern API versions).
        $res = $this->stripeRequest('POST', '/checkout/sessions', $config['secret_key'], $payload);

        if (!$res['ok'] || empty($res['data']['id']) || empty($res['data']['url'])) {
            http_response_code($res['status'] >= 400 ? $res['status'] : 500);
            echo json_encode([
                'success' => false,
                'message' => $res['error'] ?: 'Unable to start Stripe Checkout.',
            ]);
            return;
        }

        $session = $res['data'];
        $insertStmt = $db->prepare("
            INSERT INTO token_purchases
                (user_id, stripe_session_id, tokens_purchased, amount_cents, currency, payment_method_hint, status)
            VALUES
                (:uid, :sid, :tokens, :amount, 'usd', :pm_hint, 'pending')
            ON DUPLICATE KEY UPDATE
                tokens_purchased = VALUES(tokens_purchased),
                amount_cents = VALUES(amount_cents)
        ");
        $insertStmt->execute([
            ':uid' => $userId,
            ':sid' => $session['id'],
            ':tokens' => $tokens,
            ':amount' => $amountCents,
            ':pm_hint' => $paymentMethod,
        ]);

        echo json_encode([
            'success' => true,
            'checkoutUrl' => $session['url'],
            'sessionId' => $session['id'],
            'paymentMethod' => $paymentMethod,
            'fallbackUsed' => $fallbackUsed,
        ]);
    }

    /**
     * POST /payments/verify-session
     * Verifies a Stripe Checkout Session upon redirect return and idempotently credits tokens.
     */
    public function verifyCheckoutSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in to verify your purchase.']);
            return;
        }

        $config = $this->getStripeConfig();
        if (!$config['is_configured']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Stripe credentials are not configured on the server.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $sessionId = trim((string)($data['session_id'] ?? ''));
        if ($sessionId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing Stripe session_id.']);
            return;
        }

        $res = $this->stripeRequest('GET', '/checkout/sessions/' . urlencode($sessionId), $config['secret_key']);
        if (!$res['ok'] || !is_array($res['data'])) {
            http_response_code($res['status'] >= 400 ? $res['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $res['error'] ?: 'Could not verify Stripe session.',
            ]);
            return;
        }

        $session = $res['data'];
        $sessionUserId = (int)($session['metadata']['user_id'] ?? $session['client_reference_id'] ?? 0);
        if ($sessionUserId !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This checkout session does not belong to your account.']);
            return;
        }

        $paymentStatus = (string)($session['payment_status'] ?? '');
        if ($paymentStatus !== 'paid') {
            echo json_encode([
                'success' => false,
                'pending' => true,
                'paymentStatus' => $paymentStatus,
                'message' => "Payment is currently '{$paymentStatus}'. Tokens will be credited once payment completes.",
            ]);
            return;
        }

        $db = Database::connect();
        $this->ensureTablesExist($db);
        $result = $this->fulfillPurchaseSession($db, $session);

        if (!empty($result['success']) && isset($result['tokensRemaining'])) {
            $_SESSION['generation_tokens'] = $result['tokensRemaining'];
        } else {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /payments/webhook
     * Handles asynchronous Stripe Checkout webhooks (including async crypto confirmations).
     */
    public function handleWebhook() {
        $config = $this->getStripeConfig();
        $webhookSecret = $config['webhook_secret'];
        if ($webhookSecret === '' || stripos($webhookSecret, 'REPLACE_WITH') !== false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'STRIPE_WEBHOOK_SECRET is not configured in .env.']);
            return;
        }

        $rawPayload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if ($sigHeader === '' || $rawPayload === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing Stripe signature header.']);
            return;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't') {
                $timestamp = (int)$kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }

        if (!$timestamp || empty($signatures) || abs(time() - $timestamp) > 300) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired Stripe webhook timestamp.']);
            return;
        }

        $expectedSig = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $webhookSecret);
        $validSig = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expectedSig, $sig)) {
                $validSig = true;
                break;
            }
        }

        if (!$validSig) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid Stripe webhook signature.']);
            return;
        }

        $event = json_decode($rawPayload, true);
        if (!is_array($event)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid webhook JSON payload.']);
            return;
        }

        $type = $event['type'] ?? '';
        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            $session = $event['data']['object'] ?? [];
            if (($session['payment_status'] ?? '') === 'paid') {
                $db = Database::connect();
                $this->ensureTablesExist($db);
                $this->fulfillPurchaseSession($db, $session);
            }
        }

        echo json_encode(['received' => true]);
    }
}
