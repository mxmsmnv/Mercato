<?php
namespace ProcessWire;

trait MercatoPublicEndpoints {

    public function handleHeadlessApi(HookEvent $event): void {
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,80}$/', (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''))
            ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : 'req_' . bin2hex(random_bytes(10));
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Request-ID: ' . $requestId);
        $service = $this->headlessApiService();
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && $service->isAllowedOrigin($origin)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Request-ID, X-Mercato-Token'); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code($origin === '' || $service->isAllowedOrigin($origin) ? 204 : 403); exit; }
        try {
            if (empty($this->headless_api_enabled)) throw new MercatoHeadlessApiException('api_disabled', 'Headless API is disabled.', 503);
            $service->assertRequestAllowed((string) ($event->arguments('resource') ?: 'store'));
            $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0); if ($length > $service->maxBodyBytes()) throw new MercatoHeadlessApiException('body_too_large', 'JSON request body is too large.', 413);
            $raw = file_get_contents('php://input') ?: ''; if (strlen($raw) > $service->maxBodyBytes()) throw new MercatoHeadlessApiException('body_too_large', 'JSON request body is too large.', 413);
            $body = $raw !== '' ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : [];
            if (!is_array($body)) throw new MercatoHeadlessApiException('invalid_json', 'JSON object expected.', 400);
            $response = $service->dispatch(
                strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
                (string) ($event->arguments('resource') ?: 'store'), (string) $event->arguments('id'), (string) $event->arguments('action'),
                $body, array_merge($this->wire('input')->get->getArray(), ['request_id'=>$requestId]), $service->requestHeaders()
            );
            http_response_code((int) ($response['status'] ?? 200)); unset($response['status']); echo json_encode(['ok'=>true,'api_version'=>'v1','request_id'=>$requestId]+$response, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } catch (MercatoHeadlessApiException $e) {
            http_response_code($e->httpStatus); echo json_encode(['ok'=>false,'api_version'=>'v1','request_id'=>$requestId,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage(),'fields'=>$e->fields]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            http_response_code(400); echo json_encode(['ok'=>false,'api_version'=>'v1','request_id'=>$requestId,'error'=>['code'=>'invalid_json','message'=>'Malformed JSON request body.','fields'=>[]]]);
        } catch (WireException $e) {
            $status = in_array($e->getCode(), [400,401,403,404,409,410,422,429,502,503], true) ? $e->getCode() : 400; http_response_code($status);
            echo json_encode(['ok'=>false,'api_version'=>'v1','request_id'=>$requestId,'error'=>['code'=>'commerce_error','message'=>$e->getMessage(),'fields'=>[]]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->wire('log')->error('Mercato API ' . $requestId . ': ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'api_version'=>'v1','request_id'=>$requestId,'error'=>['code'=>'internal_error','message'=>'The request could not be completed.','fields'=>[]]]);
        }
        exit;
    }

    public function handleAnalyticsConsent(HookEvent $event): void {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
        $csrf = $this->wire('session')->CSRF ?? null; if ($csrf && method_exists($csrf, 'hasValidToken') && !$csrf->hasValidToken()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Invalid form session.']); exit; }
        $consent = $this->analyticsService()->setConsent(['analytics' => (bool) $this->wire('input')->post->int('analytics'), 'marketing' => (bool) $this->wire('input')->post->int('marketing')]); echo json_encode(['ok' => true, 'consent' => $consent], JSON_UNESCAPED_SLASHES); exit;
    }

    public function handleHealthCheck(HookEvent $event): void {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
        $detailed = (bool) $this->wire('input')->get->int('details');
        if ($detailed) {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            $token = preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ? trim((string) $matches[1]) : '';
            if (!$this->operationalService()->verifyHealthToken($token)) { http_response_code(401); echo json_encode(['service' => 'mercato', 'status' => 'unauthorized']); exit; }
        }
        $health = $this->operationalService()->health($detailed); http_response_code($health['status'] === 'down' ? 503 : 200); echo json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
    }

    public function handleSeoSitemap(HookEvent $event): void {
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->seoService()->sitemapXml();
        exit;
    }

    public function handleEmailWebhook(HookEvent $event): void {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST a signed transactional email provider event.']); exit;
        }
        try {
            $result = $this->emailWebhookService()->process((string) $this->wire('input')->get->text('provider'), file_get_contents('php://input') ?: '', function_exists('getallheaders') ? (array) getallheaders() : []);
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (WireException $e) {
            http_response_code(in_array($e->getCode(), [401, 404, 422], true) ? $e->getCode() : 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function handleShippingWebhook(HookEvent $event): void {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST a signed provider tracking event.']);
            exit;
        }
        $provider = trim((string) $this->wire('input')->get->text('provider'));
        $payload = file_get_contents('php://input') ?: '';
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        try {
            $result = $this->shippingProviderService()->processTrackingWebhook($provider, $payload, $headers);
            http_response_code(200);
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (WireException $e) {
            http_response_code(in_array($e->getCode(), [401, 404, 422], true) ? $e->getCode() : 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function handleQuoteStatus(HookEvent $event): void {
        $input = $this->wire('input');
        $quote = $this->wire('pages')->get((int) $input->get('quote'));
        $token = (string) $input->get->text('token');
        $ok = $quote instanceof Page && $quote->id && $this->quoteService()->verifyToken($quote, $token);
        http_response_code($ok ? 200 : 404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok
            ? ['ok' => true, 'resource' => 'quote', 'data' => $this->quoteService()->serializePublic($quote)]
            : ['ok' => false, 'resource' => 'quote', 'error' => 'Quote request not found.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    public function hookCleanupExpiredReservations(?HookEvent $event = null): void {
        $this->runBackgroundJobs(['reservation_cleanup'], ['source' => 'lazycron']);
        $this->runBackgroundJobs(['stale_draft_expiration'], ['source' => 'lazycron']);
    }

    public function hookRunRecoveryAutomation(?HookEvent $event = null): void {
        $this->runBackgroundJobs(['recovery_automation'], ['source' => 'lazycron']);
    }

    public function hookRunPrivacyRetention(?HookEvent $event = null): void {
        $this->runBackgroundJobs(['privacy_retention'], ['source' => 'lazycron']);
    }

    public function handleRecoveryUnsubscribe(HookEvent $event): void {
        $input = $this->wire('input');
        $email = strtolower((string) $this->wire('sanitizer')->email((string) ($input->get->text('email') ?: $input->post->text('email'))));
        $token = (string) ($input->get->text('token') ?: $input->post->text('token'));
        $isOneClickPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && (string) $input->post->text('List-Unsubscribe') === 'One-Click';
        $ok = $this->verifyRecoveryUnsubscribeToken($email, $token);
        if ($ok) {
            $ok = $this->suppressRecoveryEmail($email, $isOneClickPost ? 'list_unsubscribe_post' : 'unsubscribe_link');
        }

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><title>Mercato recovery emails</title></head><body>';
        echo '<main style="font-family: system-ui, sans-serif; max-width: 640px; margin: 48px auto; padding: 0 20px;">';
        echo '<h1>' . ($ok ? 'Recovery emails disabled' : 'Invalid unsubscribe link') . '</h1>';
        echo '<p>' . ($ok ? 'This email address will no longer receive Mercato recovery payment-link reminders.' : 'The unsubscribe link is invalid or expired.') . '</p>';
        echo '</main></body></html>';
        exit;
    }

    public function handleOrderStatus(HookEvent $event): void {
        $input = $this->wire('input');
        $order = $this->wire('pages')->get((int) $input->get('order'));
        $token = (string) $input->get->text('token');
        $ok = $this->verifyOrderStatusToken($order, $token);

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($ok ? 200 : 404);
        echo $ok ? $this->renderPublicOrderStatus($order) : $this->renderPublicOrderStatusError();
        exit;
    }

    public function handleOrderLookup(HookEvent $event): void {
        $input = $this->wire('input');
        $reference = trim((string) ($input->post->text('order') ?: $input->get->text('order')));
        $email = trim((string) ($input->post->text('email') ?: $input->get->text('email')));
        $attempted = $reference !== '' || $email !== '';

        if ($reference !== '' && $email !== '') {
            $order = $this->findOrderForPublicLookup($reference, $email);
            if ($order && $order->id) {
                header('Location: ' . $this->getOrderStatusUrl($order), true, 303);
                exit;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($attempted ? 404 : 200);
        echo $this->renderPublicOrderLookupForm(
            $reference,
            $email,
            $attempted ? 'No order matched that email and order number.' : ''
        );
        exit;
    }

    public function handleOrderReceipt(HookEvent $event): void {
        $input = $this->wire('input');
        $order = $this->wire('pages')->get((int) $input->get('order'));
        $token = (string) $input->get->text('token');
        $ok = $this->verifyOrderReceiptToken($order, $token);

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($ok ? 200 : 404);
        echo $ok ? $this->renderPublicOrderReceipt($order) : $this->renderPublicOrderReceiptError();
        exit;
    }

    public function handleOrderReceiptPdf(HookEvent $event): void {
        $input = $this->wire('input');
        $order = $this->wire('pages')->get((int) $input->get('order'));
        $token = (string) $input->get->text('token');
        $ok = $this->verifyOrderReceiptToken($order, $token);
        if (!$ok) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Receipt PDF unavailable.';
            exit;
        }

        $invoice = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($order->mrc_invoice_number ?: $order->title)) ?: 'receipt';
        $pdf = $this->renderPublicOrderReceiptPdf($order);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="receipt-' . $invoice . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function handleOrderPackingSlipPdf(HookEvent $event): void {
        $input = $this->wire('input');
        $order = $this->wire('pages')->get((int) $input->get('order'));
        $token = (string) $input->get->text('token');
        $ok = $this->verifyOrderStatusToken($order, $token);
        if (!$ok) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Packing slip unavailable.';
            exit;
        }

        $invoice = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($order->mrc_invoice_number ?: $order->title)) ?: 'order';
        $pdf = $this->renderOrderPackingSlipPdf($order);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="packing-slip-' . $invoice . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function handleOrderDownload(HookEvent $event): void {
        $input = $this->wire('input');
        $order = $this->wire('pages')->get((int) $input->get('order'));
        $productId = (int) $input->get('product');
        $fileIndex = (int) $input->get('file');
        $token = (string) $input->get->text('token');
        $download = $this->getOrderDownloadFile($order, $productId, $fileIndex, $token);
        if (!$download) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Download unavailable.';
            exit;
        }

        $path = (string) $download['path'];
        $filename = (string) $download['filename'];
        $this->recordOrderDownload($order, $download);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function handleReadApi(HookEvent $event): void {
        $input = $this->wire('input');
        $resource = trim((string) ($input->get->text('resource') ?: $input->get->text('r') ?: 'info'));
        $params = [
            'id' => (int) $input->get('id'),
            'q' => trim((string) $input->get->text('q')),
            'limit' => (int) $input->get('limit'),
        ];

        $response = $this->getReadApiResponse($resource, $params);
        $ok = !empty($response['ok']);
        http_response_code($ok ? 200 : (int) ($response['status'] ?? 404));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function getReadApiResponse(string $resource = 'info', array $params = []): array {
        $resource = strtolower(trim($resource)) ?: 'info';
        $limit = max(1, min(50, (int) ($params['limit'] ?? 20) ?: 20));

        if ($resource === 'info') {
            $response = [
                'ok' => true,
                'resource' => 'info',
                'data' => [
                    'name' => 'Mercato',
                    'currency' => MercatoCurrency::normalizeCode((string) ($this->currency ?? 'GBP')),
                    'resources' => ['info', 'products', 'product'],
                ],
            ];
            $hooked = $this->readApiResponse($response, $resource, $params);
            return is_array($hooked) ? $hooked : $response;
        }

        if ($resource === 'products') {
            $productTemplate = (string) ($this->product_template ?: $this->cart_template ?: 'mrc-product');
            $selector = 'template=' . $this->wire('sanitizer')->selectorValue($productTemplate) . ', sort=title, limit=' . $limit;
            $query = trim((string) ($params['q'] ?? ''));
            if ($query !== '') {
                $selector .= ', title|mrc_sku|mrc_variants%=' . $this->wire('sanitizer')->selectorValue($query);
            }
            $products = $this->wire('pages')->find($selector);
            $items = [];
            foreach ($products as $product) {
                if ($product instanceof Page && $product->id) {
                    $items[] = $this->serializeProductForReadApi($product);
                }
            }
            $response = [
                'ok' => true,
                'resource' => 'products',
                'data' => $items,
                'meta' => [
                    'limit' => $limit,
                    'count' => count($items),
                    'query' => $query,
                ],
            ];
            $hooked = $this->readApiResponse($response, $resource, $params);
            return is_array($hooked) ? $hooked : $response;
        }

        if ($resource === 'product') {
            $productTemplate = (string) ($this->product_template ?: $this->cart_template ?: 'mrc-product');
            $product = $this->wire('pages')->get('template=' . $this->wire('sanitizer')->selectorValue($productTemplate) . ', id=' . (int) ($params['id'] ?? 0));
            $ok = $product instanceof Page && $product->id && $product->template->name === $productTemplate;
            $response = [
                'ok' => $ok,
                'resource' => 'product',
                'data' => $ok ? $this->serializeProductForReadApi($product) : null,
                'status' => $ok ? 200 : 404,
            ];
            $hooked = $this->readApiResponse($response, $resource, $params);
            return is_array($hooked) ? $hooked : $response;
        }

        $response = [
            'ok' => false,
            'resource' => $resource,
            'error' => 'Unknown read API resource.',
            'status' => 404,
        ];
        $hooked = $this->readApiResponse($response, $resource, $params);
        return is_array($hooked) ? $hooked : $response;
    }

    protected function serializeProductForReadApi(Page $product): array {
        $type = $product->hasField('mrc_product_type') ? strtolower(trim((string) $product->mrc_product_type)) : '';
        $definition = $this->variantService()->getDefinition($product);
        $variants = [];
        foreach ($definition['variants'] as $variant) {
            if ($variant['status'] === 'archived') continue;
            $purchasability = $this->getProductPurchasability($product, 1, 0, 0, (string) $variant['id']);
            $publicVariant = $variant;
            $publicVariant['images'] = $this->variantService()->resolveImageUrls($product, (array) $variant['images']);
            $variants[] = $publicVariant + [
                'resolved_price' => (float) $purchasability['resolved_price'],
                'available_stock' => (int) $purchasability['available_stock'],
                'purchasable' => (bool) $purchasability['ok'],
            ];
        }
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'title' => (string) $product->title,
            'url' => (string) $product->url,
            'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
            'price' => $product->hasField('mrc_price') ? round((float) $product->mrc_price, 2) : 0.0,
            'currency' => MercatoCurrency::normalizeCode((string) ($this->currency ?? 'GBP')),
            'tax_rate' => $product->hasField('mrc_tax_rate') ? round((float) $product->mrc_tax_rate, 4) : 0.0,
            'tax_code' => $product->hasField('mrc_tax_code') ? (string) $product->mrc_tax_code : '',
            'shipping_price' => $product->hasField('mrc_shipping_price') ? round((float) $product->mrc_shipping_price, 2) : 0.0,
            'product_type' => in_array($type, ['physical', 'digital', 'service', 'placeholder', 'recurring', 'bundle'], true) ? $type : 'physical',
            'stock_policy' => $product->hasField('mrc_stock_policy') ? (string) $product->mrc_stock_policy : '',
            'has_variants' => $variants !== [],
            'variant_options' => $definition['options'],
            'variants' => $variants,
        ];
    }

    protected function renderPublicOrderStatusError(): string {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>Order status unavailable</title>' . $this->renderPublicOrderStatusStyles() . '</head><body>'
            . '<main class="mrc-public-status"><section class="mrc-status-card">'
            . '<p class="mrc-kicker">Mercato</p><h1>Order status unavailable</h1>'
            . '<p>The order status link is invalid or expired.</p>'
            . '</section></main></body></html>';
    }

    protected function renderPublicOrderLookupForm(string $reference = '', string $email = '', string $message = ''): string {
        $messageHtml = $message !== ''
            ? '<p class="mrc-status-alert">' . $this->h($message) . '</p>'
            : '';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>Find order status</title>' . $this->renderPublicOrderStatusStyles() . '</head><body>'
            . '<main class="mrc-public-status"><section class="mrc-status-card">'
            . '<p class="mrc-kicker">Mercato</p><h1>Find order status</h1>'
            . '<p>Enter the email address used at checkout and your order or invoice number.</p>'
            . $messageHtml
            . '<form method="post" action="' . $this->h($this->getOrderLookupUrl()) . '" class="mrc-public-form">'
            . '<label>Order number <input type="text" name="order" value="' . $this->h($reference) . '" autocomplete="off" required></label>'
            . '<label>Email <input type="email" name="email" value="' . $this->h($email) . '" autocomplete="email" required></label>'
            . '<button type="submit">Find order</button>'
            . '</form></section></main></body></html>';
    }

    protected function renderPublicOrderReceiptError(): string {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>Receipt unavailable</title>' . $this->renderPublicOrderStatusStyles() . '</head><body>'
            . '<main class="mrc-public-status"><section class="mrc-status-card">'
            . '<p class="mrc-kicker">Mercato</p><h1>Receipt unavailable</h1>'
            . '<p>The receipt link is invalid or the order has not been paid.</p>'
            . '</section></main></body></html>';
    }

    protected function getOrderDownloadFile(Page $order, int $productId, int $fileIndex, string $token): ?array {
        if (!$this->verifyOrderDownloadToken($order, $productId, $fileIndex, $token) || !$this->isOrderReceiptAvailable($order)) {
            return null;
        }
        if (!$this->orderContainsProduct($order, $productId)) {
            return null;
        }

        $product = $this->wire('pages')->get($productId);
        if (!$this->isProductDownloadableForOrder($order, $product)) {
            return null;
        }
        if ($this->isDigitalDownloadLimitReached($order, $product, $fileIndex)) {
            return null;
        }

        $file = $product->mrc_digital_files->eq($fileIndex);
        $path = is_object($file) && isset($file->filename) ? (string) $file->filename : '';
        $filename = is_object($file) ? $this->getDigitalDownloadFilename($file) : '';
        if ($path === '' || $filename === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        return [
            'product' => $product,
            'product_id' => (int) $product->id,
            'product_title' => (string) $product->title,
            'file_index' => $fileIndex,
            'file' => $file,
            'path' => $path,
            'filename' => $filename,
        ];
    }

    protected function isDigitalDownloadLimitReached(Page $order, Page $product, int $fileIndex): bool {
        $limit = $product->hasField('mrc_download_limit') ? max(0, (int) $product->mrc_download_limit) : 0;
        return $limit > 0 && $this->getOrderDownloadCount($order, (int) $product->id, $fileIndex) >= $limit;
    }

    protected function getOrderDownloadCount(Page $order, int $productId, int $fileIndex): int {
        $details = $this->getOrderDownloadDetails($order);
        $count = 0;
        foreach ((array) ($details['events'] ?? []) as $event) {
            if ((int) ($event['product_id'] ?? 0) === $productId && (int) ($event['file_index'] ?? -1) === $fileIndex) {
                $count++;
            }
        }
        return $count;
    }

    protected function getOrderDownloadDetails(Page $order): array {
        if (!$order->hasField('mrc_download_details')) {
            return ['events' => []];
        }
        $details = json_decode((string) $order->mrc_download_details, true);
        return is_array($details) ? array_merge(['events' => []], $details) : ['events' => []];
    }

    protected function recordOrderDownload(Page $order, array $download): void {
        if (!$order->hasField('mrc_download_details')) {
            $this->wire('log')->save('mercato-downloads', json_encode([
                'event' => 'downloaded',
                'order_id' => (int) $order->id,
                'product_id' => (int) ($download['product_id'] ?? 0),
                'file_index' => (int) ($download['file_index'] ?? 0),
                'filename' => (string) ($download['filename'] ?? ''),
                'at' => date('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $details = $this->getOrderDownloadDetails($order);
        $details['events'][] = [
            'event' => 'downloaded',
            'at' => date('c'),
            'product_id' => (int) ($download['product_id'] ?? 0),
            'product_title' => (string) ($download['product_title'] ?? ''),
            'file_index' => (int) ($download['file_index'] ?? 0),
            'filename' => (string) ($download['filename'] ?? ''),
        ];
        $order->of(false);
        $order->mrc_download_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('pages')->save($order);
    }

    protected function orderContainsProduct(Page $order, int $productId): bool {
        if ($productId <= 0) {
            return false;
        }
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === $productId) {
                return true;
            }
        }
        return false;
    }

    protected function isProductDownloadableForOrder(Page $order, ?Page $product): bool {
        if (!$product || !$product->id || !$product->template || $product->template->name !== 'mrc-product') {
            return false;
        }
        if (!$product->hasField('mrc_digital_files') || !count($product->mrc_digital_files)) {
            return false;
        }
        $type = $product->hasField('mrc_product_type') ? strtolower(trim((string) $product->mrc_product_type)) : '';
        if ($type !== 'digital') {
            return false;
        }
        $expiresAt = $this->getDigitalDownloadExpiryTimestamp($order, $product);
        return $expiresAt <= 0 || time() <= $expiresAt;
    }

    protected function getDigitalDownloadExpiryTimestamp(Page $order, Page $product): int {
        $days = $product->hasField('mrc_download_expiry_days') ? max(0, (int) $product->mrc_download_expiry_days) : 0;
        if ($days <= 0) {
            return 0;
        }
        $paidDate = trim((string) ($order->mrc_paid_date ?? ''));
        $base = $paidDate !== '' ? strtotime($paidDate) : false;
        if (!$base) {
            $base = (int) $order->created;
        }
        return $base + ($days * 86400);
    }

    protected function getDigitalDownloadFilename(object $file): string {
        $name = isset($file->name) ? (string) $file->name : '';
        if ($name === '' && isset($file->basename)) {
            $name = (string) $file->basename;
        }
        if ($name === '' && isset($file->filename)) {
            $name = basename((string) $file->filename);
        }
        return preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?: '';
    }

    protected function renderPublicOrderReceipt(Page $order): string {
        $invoice = (string) ($order->mrc_invoice_number ?: $order->title);
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        $shippingTotal = $order->hasField('mrc_shipping_amount') ? (float) $order->mrc_shipping_amount : 0.0;
        $discountTotal = $order->hasField('mrc_discount_total') ? (float) $order->mrc_discount_total : 0.0;
        $subtotal = $order->hasField('mrc_subtotal_amount') ? (float) $order->mrc_subtotal_amount : 0.0;
        $total = $this->orderRepository()->getTotalAmount($order);
        $taxRates = $this->getReceiptTaxRates($order, $items, $shippingTotal);
        $fulfilmentLabel = $order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== ''
            ? (string) $order->mrc_fulfilment_label
            : 'Shipping';
        $billingAddress = $this->formatAddressSnapshot($order, 'mrc_billing_address');
        $shippingAddress = $this->formatAddressSnapshot($order, 'mrc_shipping_address');
        $merchantDetails = $this->getReceiptMerchantLegalDetails($order);
        $merchantHtml = $merchantDetails !== ''
            ? '<div class="mrc-status-card"><h2>Merchant</h2><p>' . nl2br($this->h($merchantDetails)) . '</p></div>'
            : '';
        $taxLabel = $this->getTaxLabel($order);
        $paymentStatus = trim((string) $order->mrc_payment_status) ?: ((int) $order->mrc_payment_complete === 1 ? self::PAYMENT_STATUS_PAID : self::PAYMENT_STATUS_PENDING);
        $refund = $this->getPublicRefundSummary($order);
        $pdfUrl = $this->getOrderReceiptPdfUrl($order);
        $digitalDownloads = $this->getOrderDigitalDownloads($order);
        $pdfAction = $pdfUrl !== ''
            ? '<a class="mrc-primary-action" href="' . $this->h($pdfUrl) . '" target="_blank" rel="noopener">Download PDF</a>'
            : '';
        $customReceipt = $this->renderCustomOrderReceipt($order, compact(
            'invoice',
            'items',
            'shippingTotal',
            'discountTotal',
            'subtotal',
            'total',
            'taxRates',
            'fulfilmentLabel',
            'billingAddress',
            'shippingAddress',
            'merchantDetails',
            'taxLabel',
            'paymentStatus',
            'refund',
            'pdfUrl',
            'digitalDownloads'
        ));
        if ($customReceipt !== '') {
            return $customReceipt;
        }

        $rows = '';
        foreach ($items as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
            $sku = trim((string) ($item['sku'] ?? ''));
            $unit = (float) ($item['price'] ?? 0);
            $line = (float) ($item['sum'] ?? ($unit * $quantity));
            $rows .= '<tr><td>' . $this->h($title) . ($sku !== '' ? '<br><small>' . $this->h($sku) . '</small>' : '') . '</td><td>' . $quantity . '</td><td>' . $this->h($this->formatPrice($unit)) . '</td><td>' . $this->h($this->formatPrice($line)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No receipt items are available.</td></tr>';
        }
        $taxRows = '';
        foreach ($taxRates as $rate) {
            $taxRows .= '<tr><td colspan="3">incl. ' . $this->h($taxLabel) . ' ' . $this->h((string) ($rate['tax_rate'] ?? 0)) . '%</td><td>' . $this->h($this->formatPrice((float) ($rate['sum'] ?? 0))) . '</td></tr>';
        }
        $downloadHtml = '';
        if ($digitalDownloads) {
            $downloadRows = '';
            foreach ($digitalDownloads as $download) {
                $expires = (string) ($download['expires_at'] ?? '');
                $downloadRows .= '<li><a class="mrc-primary-action" href="' . $this->h((string) $download['url']) . '">' . $this->h((string) $download['filename']) . '</a>'
                    . '<br><small>' . $this->h((string) $download['product_title']) . ($expires !== '' ? ' &middot; Expires ' . $expires : '') . '</small></li>';
            }
            $downloadHtml = '<section class="mrc-status-card"><h2>Downloads</h2><ul>' . $downloadRows . '</ul></section>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>Receipt ' . $this->h($invoice) . '</title>' . $this->renderPublicOrderStatusStyles() . '<style>@media print {.mrc-print-actions{display:none}.mrc-public-status{margin:0;width:100%}body{background:#fff}.mrc-status-card{border-color:#aaa}}</style></head><body>'
            . '<main class="mrc-public-status">'
            . '<section class="mrc-status-card mrc-status-hero"><p class="mrc-kicker">Receipt</p><h1>Receipt ' . $this->h($invoice) . '</h1>'
            . '<div class="mrc-status-pills"><span>Payment: ' . $this->h($this->humanizeStatus($paymentStatus)) . '</span><span>' . $this->h((string) ($order->mrc_invoice_date ?: date('Y-m-d H:i', (int) $order->created))) . '</span></div>'
            . '<p class="mrc-print-actions"><button onclick="window.print()">Print receipt</button>' . $pdfAction . '</p></section>'
            . '<section class="mrc-status-grid"><div class="mrc-status-card"><h2>Customer</h2><p>' . $this->h(trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name)) . '</p><p>' . $this->h((string) $order->mrc_email) . '</p></div>'
            . '<div class="mrc-status-card"><h2>Addresses</h2><p><strong>Billing</strong><br>' . nl2br($this->h($billingAddress ?: '-')) . '</p><p><strong>' . $this->h($fulfilmentLabel) . '</strong><br>' . nl2br($this->h($shippingAddress ?: '-')) . '</p></div>' . $merchantHtml . '</section>'
            . '<section class="mrc-status-card"><h2>Items</h2><table><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>' . $rows . '</tbody><tfoot>'
            . ($subtotal > 0 ? '<tr><td colspan="3">Subtotal</td><td>' . $this->h($this->formatPrice($subtotal)) . '</td></tr>' : '')
            . '<tr><td colspan="3">' . $this->h($fulfilmentLabel) . '</td><td>' . ($shippingTotal > 0 ? $this->h($this->formatPrice($shippingTotal)) : 'Free') . '</td></tr>'
            . ($discountTotal > 0 ? '<tr><td colspan="3">Discount</td><td>-' . $this->h($this->formatPrice($discountTotal)) . '</td></tr>' : '')
            . ($refund['refunded'] > 0 ? '<tr><td colspan="3">Refunded</td><td>-' . $this->h($this->formatPrice((float) $refund['refunded'])) . '</td></tr>' : '')
            . ($refund['pending'] > 0 ? '<tr><td colspan="3">Pending refund</td><td>-' . $this->h($this->formatPrice((float) $refund['pending'])) . '</td></tr>' : '')
            . '<tr><td colspan="3"><strong>Total paid</strong></td><td><strong>' . $this->h($this->formatPrice($total)) . '</strong></td></tr>'
            . ($refund['has_refund'] ? '<tr><td colspan="3"><strong>Net paid</strong></td><td><strong>' . $this->h($this->formatPrice((float) $refund['net_paid'])) . '</strong></td></tr>' : '')
            . $taxRows
            . '</tfoot></table></section>'
            . $downloadHtml
            . '</main></body></html>';
    }

    protected function renderPublicOrderReceiptPdf(Page $order): string {
        $invoice = (string) ($order->mrc_invoice_number ?: $order->title);
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        $shippingTotal = $order->hasField('mrc_shipping_amount') ? (float) $order->mrc_shipping_amount : 0.0;
        $discountTotal = $order->hasField('mrc_discount_total') ? (float) $order->mrc_discount_total : 0.0;
        $subtotal = $order->hasField('mrc_subtotal_amount') ? (float) $order->mrc_subtotal_amount : 0.0;
        $total = $this->orderRepository()->getTotalAmount($order);
        $refund = $this->getPublicRefundSummary($order);
        $taxRates = $this->getReceiptTaxRates($order, $items, $shippingTotal);
        $fulfilmentLabel = $order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== ''
            ? (string) $order->mrc_fulfilment_label
            : 'Shipping';
        $paymentStatus = trim((string) $order->mrc_payment_status) ?: ((int) $order->mrc_payment_complete === 1 ? self::PAYMENT_STATUS_PAID : self::PAYMENT_STATUS_PENDING);

        $lines = [
            'Receipt ' . $invoice,
            'Payment: ' . $this->humanizeStatus($paymentStatus),
            'Date: ' . (string) ($order->mrc_invoice_date ?: date('Y-m-d H:i', (int) $order->created)),
            '',
            'Customer',
            trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            (string) $order->mrc_email,
            '',
            'Billing address',
        ];
        $lines = array_merge($lines, preg_split('/\R/', $this->formatAddressSnapshot($order, 'mrc_billing_address') ?: '-', -1, PREG_SPLIT_NO_EMPTY) ?: ['-']);
        $lines[] = '';
        $lines[] = $fulfilmentLabel . ' address';
        $lines = array_merge($lines, preg_split('/\R/', $this->formatAddressSnapshot($order, 'mrc_shipping_address') ?: '-', -1, PREG_SPLIT_NO_EMPTY) ?: ['-']);
        $merchantDetails = $this->getReceiptMerchantLegalDetails($order);
        if ($merchantDetails !== '') {
            $lines[] = '';
            $lines[] = 'Merchant';
            $lines = array_merge($lines, preg_split('/\R/', $merchantDetails, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        $lines[] = '';
        $lines[] = 'Items';
        foreach ($items as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
            $sku = trim((string) ($item['sku'] ?? ''));
            $unit = (float) ($item['price'] ?? 0);
            $line = (float) ($item['sum'] ?? ($unit * $quantity));
            $lines[] = sprintf('%s%s x%d @ %s = %s', $title, $sku !== '' ? ' (' . $sku . ')' : '', $quantity, $this->formatPrice($unit), $this->formatPrice($line));
        }
        if (!$items) {
            $lines[] = 'No receipt items are available.';
        }
        $lines[] = '';
        if ($subtotal > 0) {
            $lines[] = 'Subtotal: ' . $this->formatPrice($subtotal);
        }
        $lines[] = $fulfilmentLabel . ': ' . ($shippingTotal > 0 ? $this->formatPrice($shippingTotal) : 'Free');
        if ($discountTotal > 0) {
            $lines[] = 'Discount: -' . $this->formatPrice($discountTotal);
        }
        if ($refund['refunded'] > 0) {
            $lines[] = 'Refunded: -' . $this->formatPrice((float) $refund['refunded']);
        }
        if ($refund['pending'] > 0) {
            $lines[] = 'Pending refund: -' . $this->formatPrice((float) $refund['pending']);
        }
        $lines[] = 'Total paid: ' . $this->formatPrice($total);
        if ($refund['has_refund']) {
            $lines[] = 'Net paid: ' . $this->formatPrice((float) $refund['net_paid']);
        }
        $taxLabel = $this->getTaxLabel($order);
        foreach ($taxRates as $rate) {
            $lines[] = 'incl. ' . $taxLabel . ' ' . (string) ($rate['tax_rate'] ?? 0) . '%: ' . $this->formatPrice((float) ($rate['sum'] ?? 0));
        }

        return $this->buildSimplePdf($lines);
    }

    protected function renderOrderPackingSlipPdf(Page $order): string {
        $invoice = (string) ($order->mrc_invoice_number ?: $order->title);
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        $fulfilmentLabel = $order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== ''
            ? (string) $order->mrc_fulfilment_label
            : 'Shipping';
        $fulfilmentStatus = $order->hasField('mrc_fulfilment_status') && trim((string) $order->mrc_fulfilment_status) !== ''
            ? (string) $order->mrc_fulfilment_status
            : 'unfulfilled';
        $tracking = $order->hasField('mrc_fulfilment_tracking') ? trim((string) $order->mrc_fulfilment_tracking) : '';

        $lines = [
            'Packing slip ' . $invoice,
            'Created: ' . date('Y-m-d H:i', (int) $order->created),
            'Fulfilment: ' . $this->humanizeStatus($fulfilmentStatus),
            '',
            'Customer',
            trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            (string) $order->mrc_email,
            '',
            $fulfilmentLabel . ' address',
        ];
        $lines = array_merge($lines, preg_split('/\R/', $this->formatAddressSnapshot($order, 'mrc_shipping_address') ?: '-', -1, PREG_SPLIT_NO_EMPTY) ?: ['-']);
        if ($tracking !== '') {
            $lines[] = '';
            $lines[] = 'Tracking: ' . $tracking;
        }
        if (trim((string) $order->mrc_notes) !== '') {
            $lines[] = '';
            $lines[] = 'Customer notes';
            $lines = array_merge($lines, preg_split('/\R/', (string) $order->mrc_notes, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        $lines[] = '';
        $lines[] = 'Items';
        foreach ($items as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
            $sku = trim((string) ($item['sku'] ?? ''));
            $lines[] = sprintf('[ ] %s%s x%d', $title, $sku !== '' ? ' (' . $sku . ')' : '', $quantity);
        }
        if (!$items) {
            $lines[] = 'No order items are available.';
        }
        $lines[] = '';
        $lines[] = 'Packed by: ____________________';
        $lines[] = 'Checked by: ___________________';

        return $this->buildSimplePdf($lines);
    }

    protected function buildSimplePdf(array $lines): string {
        $wrapped = [];
        foreach ($lines as $line) {
            $line = $this->pdfText((string) $line);
            if ($line === '') {
                $wrapped[] = '';
                continue;
            }
            foreach (str_split($line, 92) as $part) {
                $wrapped[] = $part;
            }
        }

        $pages = array_chunk($wrapped, 52);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $pageObjectNumbers = [];
        $contentObjectNumbers = [];
        $next = 4;
        foreach ($pages as $_) {
            $pageObjectNumbers[] = $next++;
            $contentObjectNumbers[] = $next++;
        }
        foreach ($pageObjectNumbers as $pageObjectNumber) {
            $kids[] = $pageObjectNumber . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageObjectNumbers) . ' >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        foreach ($pages as $index => $pageLines) {
            $contentNumber = $contentObjectNumbers[$index];
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentNumber . ' 0 R >>';
            $stream = "BT\n/F1 10 Tf\n12 TL\n50 800 Td\n";
            foreach ($pageLines as $line) {
                $stream .= '(' . $this->escapePdfString($line) . ") Tj\nT*\n";
            }
            $stream .= "ET\n";
            $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    protected function pdfText(string $text): string {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]+/', '', $text) ?: '';
    }

    protected function escapePdfString(string $text): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    protected function renderPublicOrderStatus(Page $order): string {
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        $invoice = (string) ($order->mrc_invoice_number ?: $order->title);
        $orderStatus = $this->deriveOrderStatus($order);
        $paymentStatus = trim((string) $order->mrc_payment_status) ?: ((int) $order->mrc_payment_complete === 1 ? self::PAYMENT_STATUS_PAID : self::PAYMENT_STATUS_PENDING);
        $fulfilmentStatus = $order->hasField('mrc_fulfilment_status') && trim((string) $order->mrc_fulfilment_status) !== ''
            ? (string) $order->mrc_fulfilment_status
            : (((int) $order->mrc_payment_complete === 1) ? 'unfulfilled' : 'waiting_for_payment');
        $fulfilmentLabel = $order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== ''
            ? (string) $order->mrc_fulfilment_label
            : 'Shipping';
        $tracking = $order->hasField('mrc_fulfilment_tracking') ? trim((string) $order->mrc_fulfilment_tracking) : '';
        $trackingUrl = $order->hasField('mrc_fulfilment_tracking_url') ? trim((string) $order->mrc_fulfilment_tracking_url) : '';
        $details = [];
        if ($order->hasField('mrc_fulfilment_details')) {
            $decoded = json_decode((string) $order->mrc_fulfilment_details, true);
            $details = is_array($decoded) ? $decoded : [];
        }
        $detailText = trim((string) ($details['details'] ?? ''));
        $shippingTotal = $order->hasField('mrc_shipping_amount') ? (float) $order->mrc_shipping_amount : 0.0;
        $discountTotal = $order->hasField('mrc_discount_total') ? (float) $order->mrc_discount_total : 0.0;
        $total = $this->orderRepository()->getTotalAmount($order);
        $refund = $this->getPublicRefundSummary($order);

        $rows = '';
        foreach ($items as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
            $unit = (float) ($item['price'] ?? 0);
            $rows .= '<tr><td>' . $this->h($title) . '</td><td>' . $quantity . '</td><td>' . $this->h($this->formatPrice($unit)) . '</td><td>' . $this->h($this->formatPrice($unit * $quantity)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No order items are available.</td></tr>';
        }

        $trackingHtml = $tracking !== ''
            ? '<p><strong>Tracking:</strong> ' . ($trackingUrl !== '' ? '<a href="' . $this->h($trackingUrl) . '" rel="noopener">' . $this->h($tracking) . '</a>' : $this->h($tracking)) . '</p>'
            : '';
        $policyLinks = $this->getPolicyLinksText();
        $policyHtml = '';
        if ($policyLinks !== '') {
            $policyHtml = '<section class="mrc-status-card"><h2>Store policies</h2><pre>' . $this->h($policyLinks) . '</pre></section>';
        }
        $receiptHtml = $this->isOrderReceiptAvailable($order)
            ? '<p><a href="' . $this->h($this->getOrderReceiptUrl($order)) . '">View receipt</a></p>'
            : '';
        $retryHtml = $this->isOrderPaymentRetryAvailable($order)
            ? '<p class="mrc-status-actions"><a class="mrc-primary-action" href="' . $this->h($this->getPaymentLinkUrl($order)) . '">Retry payment</a></p>'
            : '';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>Order ' . $this->h($invoice) . ' status</title>' . $this->renderPublicOrderStatusStyles() . '</head><body>'
            . '<main class="mrc-public-status">'
            . '<section class="mrc-status-card mrc-status-hero"><p class="mrc-kicker">Order status</p><h1>Order ' . $this->h($invoice) . '</h1>'
            . '<div class="mrc-status-pills"><span>Order: ' . $this->h((string) $orderStatus['label']) . '</span><span>Payment: ' . $this->h($this->humanizeStatus($paymentStatus)) . '</span><span>Fulfilment: ' . $this->h($this->humanizeStatus($fulfilmentStatus)) . '</span></div></section>'
            . '<section class="mrc-status-grid"><div class="mrc-status-card"><h2>Summary</h2>'
            . '<dl><dt>Total</dt><dd>' . $this->h($this->formatPrice($total)) . '</dd>'
            . ($refund['refunded'] > 0 ? '<dt>Refunded</dt><dd>-' . $this->h($this->formatPrice((float) $refund['refunded'])) . '</dd>' : '')
            . ($refund['pending'] > 0 ? '<dt>Pending refund</dt><dd>-' . $this->h($this->formatPrice((float) $refund['pending'])) . '</dd>' : '')
            . ($refund['has_refund'] ? '<dt>Net paid</dt><dd>' . $this->h($this->formatPrice((float) $refund['net_paid'])) . '</dd>' : '')
            . '<dt>Order</dt><dd>' . $this->h((string) $orderStatus['label']) . '</dd><dt>Payment</dt><dd>' . $this->h($this->humanizeStatus($paymentStatus)) . '</dd><dt>' . $this->h($fulfilmentLabel) . '</dt><dd>' . $this->h($this->humanizeStatus($fulfilmentStatus)) . '</dd></dl>'
            . ($detailText !== '' ? '<p>' . nl2br($this->h($detailText)) . '</p>' : '') . $trackingHtml . $receiptHtml . $retryHtml . '</div>'
            . '<div class="mrc-status-card"><h2>Customer</h2><p>' . $this->h(trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name)) . '</p><p>' . $this->h((string) $order->mrc_email) . '</p></div></section>'
            . '<section class="mrc-status-card"><h2>Items</h2><table><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>' . $rows . '</tbody><tfoot>'
            . '<tr><td colspan="3">' . $this->h($fulfilmentLabel) . '</td><td>' . ($shippingTotal > 0 ? $this->h($this->formatPrice($shippingTotal)) : 'Free') . '</td></tr>'
            . ($discountTotal > 0 ? '<tr><td colspan="3">Discount</td><td>-' . $this->h($this->formatPrice($discountTotal)) . '</td></tr>' : '')
            . ($refund['refunded'] > 0 ? '<tr><td colspan="3">Refunded</td><td>-' . $this->h($this->formatPrice((float) $refund['refunded'])) . '</td></tr>' : '')
            . ($refund['pending'] > 0 ? '<tr><td colspan="3">Pending refund</td><td>-' . $this->h($this->formatPrice((float) $refund['pending'])) . '</td></tr>' : '')
            . '<tr><td colspan="3"><strong>Total</strong></td><td><strong>' . $this->h($this->formatPrice($total)) . '</strong></td></tr>'
            . ($refund['has_refund'] ? '<tr><td colspan="3"><strong>Net paid</strong></td><td><strong>' . $this->h($this->formatPrice((float) $refund['net_paid'])) . '</strong></td></tr>' : '')
            . '</tfoot></table></section>' . $policyHtml
            . '</main></body></html>';
    }

    protected function renderPublicOrderStatusStyles(): string {
        return '<style>
            @import url("https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap");
            :root { --mrc-ink:#1b2e29; --mrc-paper:#f3f2f0; --mrc-ivory:#ece9e4; --mrc-cream:#fffaf2; --mrc-line:#d6cbbb; --mrc-muted:#746858; --mrc-gold:#a5917c; --mrc-rust:#7d3a31; }
            body { margin: 0; background: var(--mrc-paper); color: var(--mrc-ink); font-family: Inter, Avenir, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            .mrc-public-status { width: min(1120px, calc(100% - 32px)); margin: clamp(28px, 6vw, 74px) auto; display: grid; gap: 18px; }
            .mrc-status-card { background: var(--mrc-cream); border: 1px solid var(--mrc-line); padding: clamp(22px, 4vw, 44px); box-shadow: 0 28px 80px rgba(27,46,41,.1); }
            .mrc-status-hero { display: grid; gap: 12px; }
            .mrc-kicker { margin: 0; color: var(--mrc-gold); font-size: 11px; font-weight: 800; letter-spacing: .24em; text-transform: uppercase; }
            h1, h2, p { margin-top: 0; }
            h1 { font-family: "Cormorant Garamond", Georgia, serif; font-size: clamp(44px, 7vw, 86px); font-weight: 600; line-height: .92; margin-bottom: 0; }
            h2 { font-family: "Cormorant Garamond", Georgia, serif; font-size: clamp(28px, 3vw, 40px); font-weight: 600; }
            .mrc-status-pills { display: flex; flex-wrap: wrap; gap: 10px; }
            .mrc-status-pills span { display: inline-flex; border: 1px solid var(--mrc-line); padding: 7px 12px; }
            .mrc-status-actions { margin: 18px 0 0; }
            .mrc-primary-action { background: var(--mrc-ink); color: var(--mrc-ivory); display: inline-flex; font-size: 12px; font-weight: 800; letter-spacing: .18em; padding: 13px 20px; text-decoration: none; text-transform: uppercase; }
            .mrc-status-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr); gap: 18px; }
            dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 14px; margin: 0 0 16px; }
            dt { color: var(--mrc-muted); }
            dd { margin: 0; font-weight: 650; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border-bottom: 1px solid var(--mrc-line); padding: 12px; text-align: left; }
            th { color: var(--mrc-gold); font-size: 12px; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
            tfoot td { background: rgba(236,233,228,.45); }
            pre { white-space: pre-wrap; font-family: inherit; margin: 0; }
            a { color: var(--mrc-ink); }
            .mrc-public-form { display: grid; gap: 14px; max-width: 520px; }
            .mrc-public-form label { display: grid; gap: 6px; font-weight: 650; }
            .mrc-public-form input { background: #fbf6ed; border: 1px solid var(--mrc-line); color: var(--mrc-ink); padding: 12px; font: inherit; }
            .mrc-public-form button { width: max-content; border: 1px solid var(--mrc-ink); background: var(--mrc-ink); color: var(--mrc-ivory); cursor: pointer; font: inherit; font-size: 12px; font-weight: 800; letter-spacing: .18em; padding: 13px 20px; text-transform: uppercase; }
            .mrc-status-alert { border: 1px solid var(--mrc-rust); background: #fff1ee; color: var(--mrc-rust); padding: 12px; }
            @media (max-width: 720px) { .mrc-status-grid { grid-template-columns: 1fr; } th, td { padding: 10px 6px; } dl { grid-template-columns: 1fr; } }
        </style>';
    }

    protected function formatAddressSnapshot(Page $order, string $fieldName): string {
        if (!$order->hasField($fieldName)) {
            return '';
        }
        $decoded = json_decode((string) $order->get($fieldName), true);
        if (!is_array($decoded)) {
            return '';
        }
        if (($decoded['type'] ?? '') === 'pickup') {
            return trim(implode("\n", array_filter([
                (string) ($decoded['pickup_code'] ?? ''),
                (string) ($decoded['pickup_address'] ?? ''),
                (string) ($decoded['pickup_instructions'] ?? ''),
            ])));
        }
        return trim(implode("\n", array_filter([
            trim((string) ($decoded['first_name'] ?? '') . ' ' . (string) ($decoded['last_name'] ?? '')),
            (string) ($decoded['company'] ?? ''),
            trim((string) ($decoded['tax_number'] ?? '')) !== '' ? 'Tax/VAT: ' . trim((string) $decoded['tax_number']) : '',
            trim((string) ($decoded['purchase_order_number'] ?? '')) !== '' ? 'PO: ' . trim((string) $decoded['purchase_order_number']) : '',
            (string) ($decoded['address'] ?? ''),
            trim(implode(' ', array_filter([(string) ($decoded['zip'] ?? ''), (string) ($decoded['city'] ?? '')]))),
            (string) ($decoded['region'] ?? ''),
            (string) ($decoded['country'] ?? ''),
            (string) ($decoded['delivery_window'] ?? ''),
            (string) ($decoded['delivery_note'] ?? ''),
        ])));
    }

    protected function humanizeStatus(string $status): string {
        $status = trim(str_replace(['_', '-'], ' ', $status));
        return $status === '' ? '-' : ucwords($status);
    }

    protected function h(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Stripe webhook endpoint handler.
     * URL: /api/mercato/stripe-webhook
     */
}
