<?php
namespace ProcessWire;

/**
 * Request-for-quote workflow. Quotes are dedicated records and never enter
 * payment, revenue, inventory reservation, or fulfilment queues.
 */
final class MercatoQuoteService extends Wire {
    protected string $logName = 'mercato-quotes';

    public function __construct(protected Mercato $commerce) {
        parent::__construct();
    }

    public function submit(array $data, ?array $items = null): Page {
        if (empty($this->commerce->quote_requests_enabled)) {
            throw new WireException($this->commerce->_('Quote requests are not enabled.'), 403);
        }

        $data = $this->commerce->sanitizeFormData($this->commerce->normalizeOrderData($data));
        $email = (string) $this->wire('sanitizer')->email((string) ($data['email'] ?? ''));
        $errors = [];
        foreach (['first_name', 'last_name'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[] = sprintf($this->commerce->_('Field "%s" is required.'), $field);
            }
        }
        if ($email === '') $errors[] = $this->commerce->_('A valid customer email is required.');

        $policyPages = $this->commerce->getPolicyPages();
        if ($policyPages->count() > 0 && (int) ($data['mrc_policy_accepted'] ?? 0) !== 1) {
            $errors[] = $this->commerce->_('Store policies must be accepted before submitting a quote request.');
        }

        $sourceItems = $items ?? $this->commerce->cart()->toArray();
        $safeItems = $this->normalizeRequestedItems($sourceItems);
        if (!$safeItems) $errors[] = $this->commerce->_('Cart is empty.');
        if ($errors) throw new WireException(implode(' ', $errors), 422);

        $customerData = $data + ['email' => $email];
        $options = [
            'discount_code' => (string) ($data['discount_code'] ?? ''),
            'fulfilment_method' => (string) ($data['fulfilment_method'] ?? ''),
        ];
        $quote = $this->commerce->getHeadlessCheckoutQuote($safeItems, $customerData, $options);
        if (empty($quote['fulfilment_methods'])) {
            throw new WireException($this->commerce->_('No fulfilment method is available for this quote request.'), 422);
        }

        $parent = $this->wire('pages')->get('/' . trim((string) $this->commerce->quotes_parent, '/') . '/');
        $template = $this->wire('templates')->get((string) $this->commerce->quote_template);
        if (!$parent || !$parent->id || !$template) {
            throw new WireException($this->commerce->_('Quote storage is not installed. Run the Mercato installer/repair.'), 503);
        }

        $wire = $this->wire();
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');
        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot save quote request.'));
        }

        $wire->users->setCurrentUser($superuser);
        try {
            $page = new Page($template);
            $page->parent = $parent;
            $page->title = 'Quote request';
            $page->addStatus(Page::statusHidden);
            $page->mrc_quote_status = MercatoQuoteStatus::SUBMITTED;
            $page->mrc_quote_token_seed = bin2hex(random_bytes(24));
            $page->mrc_quote_expires = date('Y-m-d H:i:s', time() + max(1, (int) $this->commerce->quote_expiry_days) * 86400);
            $page->mrc_quote_customer_user_id = (int) ($savedUser->isGuest() ? 0 : $savedUser->id);
            $page->mrc_first_name = (string) ($data['first_name'] ?? '');
            $page->mrc_last_name = (string) ($data['last_name'] ?? '');
            $page->mrc_email = $email;
            $page->mrc_phone = (string) ($data['phone'] ?? '');
            $page->mrc_address = (string) ($data['address'] ?? '');
            $page->mrc_city = (string) ($data['city'] ?? '');
            $page->mrc_zip = (string) ($data['zip'] ?? '');
            $page->mrc_country = (string) ($data['country'] ?? '');
            $page->mrc_notes = (string) ($data['notes'] ?? '');
            $page->mrc_items = json_encode($quote['items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $page->mrc_currency = (string) $quote['currency'];
            $page->mrc_subtotal_amount = (float) $quote['subtotal'];
            $page->mrc_shipping_amount = (float) $quote['shipping'];
            $page->mrc_discount_total = (float) $quote['discount'];
            $page->mrc_total_amount = (float) $quote['total'];
            $page->mrc_discount_code = (string) $quote['discount_code'];
            $page->mrc_fulfilment_method = (string) $quote['fulfilment_method'];
            $page->mrc_fulfilment_label = (string) $quote['fulfilment_label'];
            $page->mrc_fulfilment_details = json_encode($this->selectedFulfilment($quote), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $page->mrc_quote_details = json_encode([
                'submitted_at' => date(DATE_ATOM),
                'customer_user_id' => (int) ($savedUser->isGuest() ? 0 : $savedUser->id),
                'inventory_reservation' => (string) $this->commerce->quote_inventory_policy,
                'price_kind' => 'request_snapshot',
                'history' => [[
                    'from' => '',
                    'to' => MercatoQuoteStatus::SUBMITTED,
                    'at' => date(DATE_ATOM),
                    'actor' => $savedUser->isGuest() ? 'customer' : (string) $savedUser->name,
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->wire('pages')->save($page);
            $number = 'Q-' . str_pad((string) $page->id, 6, '0', STR_PAD_LEFT);
            $page->title = $number;
            $page->mrc_quote_number = $number;
            $this->wire('pages')->save($page);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }

        $this->record('quote_submitted', $page, ['total' => (float) $quote['total']]);
        $this->notifySubmission($page);
        $this->commerce->quoteSubmitted($page, $quote);
        if ($items === null) $this->commerce->cart()->delete();
        return $page;
    }

    public function updateStatus(Page $quote, string $status, string $note = '', ?float $amount = null): Page {
        $this->assertQuote($quote);
        $status = strtolower(trim($status));
        $from = strtolower(trim((string) $quote->mrc_quote_status)) ?: MercatoQuoteStatus::SUBMITTED;
        if (!in_array($status, MercatoQuoteStatus::all(), true) || !MercatoQuoteStatus::canTransition($from, $status)) {
            throw new WireException(sprintf($this->commerce->_('Quote status cannot change from %s to %s.'), $from, $status), 409);
        }
        $details = json_decode((string) $quote->mrc_quote_details, true);
        $details = is_array($details) ? $details : [];
        $details['history'] = is_array($details['history'] ?? null) ? $details['history'] : [];
        $details['history'][] = [
            'from' => $from,
            'to' => $status,
            'note' => trim($note),
            'at' => date(DATE_ATOM),
            'actor' => (string) ($this->wire('user')->name ?? 'system'),
        ];
        if ($status === MercatoQuoteStatus::ACCEPTED && (string) $this->commerce->quote_inventory_policy === 'on_acceptance') {
            $this->reserveAcceptedQuote($quote);
        } elseif (in_array($status, [MercatoQuoteStatus::DECLINED, MercatoQuoteStatus::EXPIRED, MercatoQuoteStatus::CONVERTED], true)) {
            $this->releaseQuoteReservation($quote);
        }
        $quote->of(false);
        $quote->mrc_quote_status = $status;
        if ($amount !== null) $quote->mrc_quote_amount = round(max(0, $amount), 2);
        $quote->mrc_quote_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('pages')->save($quote);
        $this->record('quote_status_changed', $quote, ['from' => $from, 'to' => $status]);
        $this->notifyStatus($quote, $note);
        $this->commerce->quoteStatusChanged($quote, $from, $status, ['note' => $note]);
        return $quote;
    }

    public function findForCustomer(User $user, int $limit = 100): PageArray {
        if (!$user->id || $user->isGuest()) return new PageArray();
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->quote_template);
        return $this->wire('pages')->find(
            "template=$template, include=all, mrc_quote_customer_user_id=" . (int) $user->id
            . ", sort=-created, limit=" . max(1, min(500, $limit))
        );
    }

    public function expireDueQuotes(): int {
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->quote_template);
        $quotes = $this->wire('pages')->find("template=$template, include=all, mrc_quote_status!=" . MercatoQuoteStatus::EXPIRED . ", mrc_quote_status!=" . MercatoQuoteStatus::DECLINED . ", mrc_quote_status!=" . MercatoQuoteStatus::CONVERTED);
        $count = 0;
        foreach ($quotes as $quote) {
            $expires = strtotime((string) $quote->mrc_quote_expires);
            if ($expires === false || $expires > time()) continue;
            try {
                $this->updateStatus($quote, MercatoQuoteStatus::EXPIRED, 'Quote validity period ended.');
                $count++;
            } catch (WireException) {
                // Terminal/invalid transitions are left untouched and remain auditable.
            }
        }
        return $count;
    }

    public function getPublicUrl(Page $quote): string {
        $this->assertQuote($quote);
        return rtrim($this->commerce->getHttpRoot(), '/') . '/api/mercato/quote-status?' . http_build_query([
            'quote' => (int) $quote->id,
            'token' => $this->getToken($quote),
        ]);
    }

    public function getToken(Page $quote): string {
        $this->assertQuote($quote);
        $secret = (string) ($this->wire('config')->userAuthSalt ?: __FILE__);
        return hash_hmac('sha256', implode('|', [
            (int) $quote->id,
            (string) $quote->mrc_quote_number,
            strtolower((string) $quote->mrc_email),
            (string) $quote->mrc_quote_token_seed,
        ]), $secret);
    }

    public function verifyToken(Page $quote, string $token): bool {
        try {
            $expires = strtotime((string) $quote->mrc_quote_expires);
            return ($expires === false || $expires >= time()) && $token !== '' && hash_equals($this->getToken($quote), $token);
        } catch (\Throwable) {
            return false;
        }
    }

    public function serializePublic(Page $quote): array {
        $this->assertQuote($quote);
        return [
            'number' => (string) $quote->mrc_quote_number,
            'status' => (string) $quote->mrc_quote_status,
            'created' => date(DATE_ATOM, (int) $quote->created),
            'expires' => (string) $quote->mrc_quote_expires,
            'currency' => (string) $quote->mrc_currency,
            'requested_total' => round((float) $quote->mrc_total_amount, 2),
            'quoted_amount' => round((float) $quote->mrc_quote_amount, 2),
            'items' => json_decode((string) $quote->mrc_items, true) ?: [],
            'fulfilment' => [
                'method' => (string) $quote->mrc_fulfilment_method,
                'label' => (string) $quote->mrc_fulfilment_label,
            ],
        ];
    }

    protected function normalizeRequestedItems(array $items): array {
        $safe = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
            $quantity = max(0, (int) ($item['quantity'] ?? 1));
            if ($id > 0 && $quantity > 0) {
                $safe[] = [
                    'id' => (string) $id,
                    'quantity' => $quantity,
                    'variant_id' => (string) ($item['variant_id'] ?? ''),
                    'variant_options' => (array) ($item['variant_options'] ?? []),
                ];
            }
        }
        return $safe;
    }

    protected function selectedFulfilment(array $quote): array {
        foreach ((array) ($quote['fulfilment_methods'] ?? []) as $method) {
            if ((string) ($method['type'] ?? '') === (string) ($quote['fulfilment_method'] ?? '')) return $method;
        }
        return [];
    }

    protected function reserveAcceptedQuote(Page $quote): void {
        if (!$quote->hasField('mrc_inventory_reserved') || (int) $quote->mrc_inventory_reserved === 1) return;
        $items = json_decode((string) $quote->mrc_items, true) ?: [];
        $cart = $this->commerce->productList($items);
        $this->commerce->orderRepository()->assertStockAvailable($cart);
        $quote->of(false);
        $quote->mrc_inventory_reserved = 1;
        $quote->mrc_inventory_reserved_until = (string) $quote->mrc_quote_expires;
        $this->wire('pages')->save($quote);
        $this->record('quote_inventory_reserved', $quote, ['until' => (string) $quote->mrc_inventory_reserved_until]);
    }

    protected function releaseQuoteReservation(Page $quote): void {
        if (!$quote->hasField('mrc_inventory_reserved') || (int) $quote->mrc_inventory_reserved !== 1) return;
        $quote->of(false);
        $quote->mrc_inventory_reserved = 0;
        $quote->mrc_inventory_reserved_until = '';
        $this->wire('pages')->save($quote);
        $this->record('quote_inventory_released', $quote);
    }

    protected function assertQuote(Page $quote): void {
        if (!$quote->id || !$quote->template || $quote->template->name !== (string) $this->commerce->quote_template) {
            throw new WireException($this->commerce->_('Quote request not found.'), 404);
        }
    }

    protected function notifySubmission(Page $quote): void {
        $this->sendMail(
            (string) $quote->mrc_email,
            strtr((string) $this->commerce->quote_customer_email_subject, ['{quote}' => (string) $quote->mrc_quote_number]),
            strtr((string) $this->commerce->quote_customer_email_body, $this->mailValues($quote))
        );
        $merchant = (string) $this->wire('sanitizer')->email((string) $this->commerce->quote_merchant_email);
        if ($merchant !== '') {
            $this->sendMail($merchant, 'New quote request ' . (string) $quote->mrc_quote_number, strtr(
                "Quote: {quote}\nCustomer: {customer}\nEmail: {email}\nRequested total: {total}\nStatus: {status}\n\n{status_link}",
                $this->mailValues($quote)
            ));
        }
    }

    protected function notifyStatus(Page $quote, string $note): void {
        $values = $this->mailValues($quote) + ['{note}' => trim($note)];
        $this->sendMail(
            (string) $quote->mrc_email,
            strtr('Quote request {quote}: {status}', $values),
            strtr("Hello {customer},\n\nYour quote request {quote} is now {status}.\n{note}\n\nView quote:\n{status_link}", $values)
        );
    }

    protected function mailValues(Page $quote): array {
        return [
            '{quote}' => (string) $quote->mrc_quote_number,
            '{customer}' => trim((string) $quote->mrc_first_name . ' ' . (string) $quote->mrc_last_name),
            '{email}' => (string) $quote->mrc_email,
            '{status}' => (string) $quote->mrc_quote_status,
            '{total}' => $this->commerce->formatPrice((float) $quote->mrc_total_amount),
            '{quoted_amount}' => $this->commerce->formatPrice((float) $quote->mrc_quote_amount),
            '{status_link}' => $this->getPublicUrl($quote),
        ];
    }

    protected function sendMail(string $recipient, string $subject, string $body): void {
        $recipient = (string) $this->wire('sanitizer')->email($recipient);
        $sender = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email);
        if ($recipient === '' || $sender === '') {
            $this->record('quote_notification_skipped', null, ['recipient' => $recipient, 'reason' => 'missing_recipient_or_sender']);
            return;
        }
        try {
            $mail = wireMail();
            $mail->to($recipient)->from($sender, (string) $this->commerce->notification_sender_name)->subject($subject)->body($body);
            $reply = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_reply_to);
            if ($reply !== '') $mail->header('Reply-To', $reply);
            $sent = (int) $mail->send();
            $this->record($sent > 0 ? 'quote_notification_sent' : 'quote_notification_failed', null, ['recipient' => $recipient]);
        } catch (\Throwable $e) {
            $this->record('quote_notification_failed', null, ['recipient' => $recipient, 'message' => $e->getMessage()]);
        }
    }

    protected function record(string $event, ?Page $quote, array $context = []): void {
        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record([
            'event' => $event,
            'quote_id' => (int) ($quote?->id ?? 0),
            'quote' => (string) ($quote?->mrc_quote_number ?? ''),
        ] + $context, $event);
    }
}
