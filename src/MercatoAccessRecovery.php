<?php
namespace ProcessWire;

trait MercatoAccessRecovery {

    public function getOrderAccessRecoveryUrl(Page $order): string {
        if (empty($this->access_recovery_enabled) || !$this->isOrderReceiptAvailable($order)) return '';
        return $this->getHttpRoot() . '/api/mercato/access-recovery?' . http_build_query([
            'order' => (int) $order->id,
            'token' => $this->getOrderAccessRecoveryToken($order),
        ]);
    }

    public function getOrderAccessRecoveryToken(Page $order): string {
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        $seed = $order->hasField('mrc_status_token_seed') ? trim((string) $order->mrc_status_token_seed) : '';
        return hash_hmac('sha256', implode('|', [
            'mercato-order-access-recovery',
            (int) $order->id,
            (string) ($order->mrc_invoice_number ?: $order->title),
            strtolower((string) $order->mrc_email),
            (int) $order->created,
            $seed,
        ]), $secret);
    }

    public function verifyOrderAccessRecoveryToken(Page $order, string $token): bool {
        return !empty($this->access_recovery_enabled)
            && $order->id
            && $order->template->name === (string) $this->order_template
            && !$this->areOrderSignedLinksExpired($order)
            && $this->isOrderReceiptAvailable($order)
            && hash_equals($this->getOrderAccessRecoveryToken($order), trim($token));
    }

    public function ___orderAccessRecoveryState(Page $order): array {
        return [
            'enabled' => false,
            'status' => 'unavailable',
            'title' => $this->_('Access recovery unavailable'),
            'message' => $this->_('This order does not provide a recoverable access credential.'),
        ];
    }

    public function ___replaceOrderAccessCredential(Page $order): array {
        return ['ok' => false, 'error' => $this->_('A replacement access credential could not be created.')];
    }

    protected function normalizeOrderAccessRecoveryState(array $state): array {
        $status = strtolower(trim((string) ($state['status'] ?? 'unavailable')));
        if (!in_array($status, ['recoverable', 'complete', 'unavailable'], true)) $status = 'unavailable';
        return [
            'enabled' => !empty($state['enabled']),
            'status' => $status,
            'title' => trim((string) ($state['title'] ?? '')),
            'message' => trim((string) ($state['message'] ?? '')),
            'action_label' => trim((string) ($state['action_label'] ?? $this->_('Create replacement credential'))),
            'credential_label' => trim((string) ($state['credential_label'] ?? $this->_('Replacement access credential'))),
            'copy_label' => trim((string) ($state['copy_label'] ?? $this->_('Copy credential'))),
            'continue_label' => trim((string) ($state['continue_label'] ?? $this->_('Continue'))),
            'back_label' => trim((string) ($state['back_label'] ?? $this->_('View receipt'))),
            'view' => is_array($state['view'] ?? null) ? $state['view'] : [],
        ];
    }

    protected function normalizeOrderAccessRecoveryResult(array $result): array {
        $credential = trim((string) ($result['credential'] ?? ''));
        $credentialValid = $credential !== '' && strlen($credential) <= 512 && preg_match('/[\x00-\x1F\x7F]/', $credential) !== 1;
        $field = preg_replace('/[^a-z0-9_]+/', '', strtolower((string) ($result['credential_field'] ?? 'access_credential'))) ?: 'access_credential';
        $continueUrl = trim((string) ($result['continue_url'] ?? ''));
        if ($continueUrl !== '' && !str_starts_with($continueUrl, '/')) {
            $scheme = strtolower((string) parse_url($continueUrl, PHP_URL_SCHEME));
            if (filter_var($continueUrl, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) $continueUrl = '';
        }
        return [
            'ok' => !empty($result['ok']) && $credentialValid,
            'credential' => $credentialValid ? $credential : '',
            'credential_field' => $field,
            'expires_at' => trim((string) ($result['expires_at'] ?? '')),
            'continue_url' => $continueUrl,
            'error' => trim((string) ($result['error'] ?? '')),
        ];
    }

    protected function renderCustomOrderAccessRecovery(Page $order, array $context): string {
        $path = $this->getStorefrontTemplateOverridePath('mrc-access-recovery');
        if ($path === '') return '';
        $commerce = $this;
        $level = ob_get_level();
        try {
            ob_start();
            extract($context, EXTR_SKIP);
            include $path;
            return trim((string) ob_get_clean());
        } catch (\Throwable $error) {
            if (ob_get_level() > $level) ob_end_clean();
            $this->wire('log')->save('mercato', 'Access recovery template failed: ' . $error->getMessage());
            return '';
        }
    }

    protected function renderPublicOrderAccessRecovery(Page $order, array $state, ?array $result, string $error, string $csrfInput, string $recoveryUrl): string {
        $receiptUrl = $this->getOrderReceiptUrl($order);
        $custom = $this->renderCustomOrderAccessRecovery($order, compact('state', 'result', 'error', 'csrfInput', 'recoveryUrl', 'receiptUrl'));
        if ($custom !== '') return $custom;

        $invoice = $this->h((string) ($order->mrc_invoice_number ?: $order->title));
        $content = '<p>' . $this->h($state['message']) . '</p>';
        if ($result && !empty($result['ok'])) {
            $field = $this->h((string) $result['credential_field']);
            $content .= '<label><strong>' . $this->h($state['credential_label']) . '</strong><input readonly value="' . $this->h($result['credential']) . '" onclick="this.select()"></label>';
            if ($result['continue_url'] !== '') $content .= '<form method="post" action="' . $this->h($result['continue_url']) . '">' . $csrfInput . '<input type="hidden" name="' . $field . '" value="' . $this->h($result['credential']) . '"><button>' . $this->h($state['continue_label']) . '</button></form>';
        } elseif ($state['enabled'] && $state['status'] === 'recoverable') {
            if ($error !== '') $content .= '<p role="alert">' . $this->h($error) . '</p>';
            $content .= '<form method="post" action="' . $this->h($recoveryUrl) . '">' . $csrfInput . '<button>' . $this->h($state['action_label']) . '</button></form>';
        }
        $content .= '<p><a href="' . $this->h($receiptUrl) . '">' . $this->h($state['back_label']) . '</a></p>';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>' . $this->h($state['title']) . '</title>' . $this->renderPublicOrderStatusStyles() . '</head><body><main class="mrc-public-status"><section class="mrc-status-card mrc-status-hero"><p class="mrc-kicker">Order ' . $invoice . '</p><h1>' . $this->h($state['title']) . '</h1></section><section class="mrc-status-card">' . $content . '</section></main></body></html>';
    }
}
