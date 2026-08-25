<?php
namespace ProcessWire;

trait ProcessMercatoQuotePanels {
    protected function getQuotes(Mercato $commerce, int $limit = 100): PageArray {
        $template = $this->wire('sanitizer')->selectorValue((string) $commerce->quote_template);
        return $this->wire('pages')->find("template=$template, include=all, sort=-created, limit=" . max(1, min(500, $limit)));
    }

    protected function handleQuoteUpdate(Mercato $commerce, Page $quote): ?array {
        if ((string) $this->wire('input')->post('mrc_action') !== 'update_quote') return null;
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_QUOTES)) {
            return $this->permissionError(self::PERMISSION_MANAGE_QUOTES, $this->_('Quote request was not updated.'));
        }
        if (!$this->validateCsrf()) {
            return ['summary' => $this->_('Quote request was not updated.'), 'errors' => [$this->_('Invalid CSRF token.')]];
        }
        try {
            $status = (string) $this->wire('input')->post->text('quote_status');
            $note = (string) $this->wire('input')->post->textarea('quote_note');
            $rawAmount = trim((string) $this->wire('input')->post('quote_amount'));
            $amount = $rawAmount === '' ? null : (float) $rawAmount;
            $commerce->updateQuoteStatus($quote, $status, $note, $amount);
            return ['summary' => $this->_('Quote request updated.'), 'errors' => []];
        } catch (\Throwable $e) {
            return ['summary' => $this->_('Quote request was not updated.'), 'errors' => [$e->getMessage()]];
        }
    }

    protected function renderQuotes(PageArray $quotes, Mercato $commerce): string {
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="ds-section-label">' . $this->e($this->_('Sales')) . '</div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Quote requests')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Dedicated non-order records. They do not count as revenue, reserve stock, or enter fulfilment queues.')) . '</p>';
        if (!$quotes->count()) return $out . '<p>' . $this->e($this->_('No quote requests yet.')) . '</p></section>';
        $out .= '<div class="uk-overflow-auto"><table class="uk-table uk-table-small uk-table-divider"><thead><tr>';
        foreach ([$this->_('Quote'), $this->_('Customer'), $this->_('Status'), $this->_('Requested'), $this->_('Quoted'), $this->_('Created')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($quotes as $quote) {
            $detail = $this->adminUrl('quote-detail/?id=' . (int) $quote->id);
            $customer = trim((string) $quote->mrc_first_name . ' ' . (string) $quote->mrc_last_name);
            $out .= '<tr><td><a href="' . $this->e($detail) . '">' . $this->e((string) ($quote->mrc_quote_number ?: $quote->title)) . '</a></td>';
            $out .= '<td>' . $this->e($customer ?: (string) $quote->mrc_email) . '<br><small>' . $this->e((string) $quote->mrc_email) . '</small></td>';
            $out .= '<td><span class="uk-label">' . $this->e((string) $quote->mrc_quote_status) . '</span></td>';
            $out .= '<td>' . $this->e($commerce->formatPrice((float) $quote->mrc_total_amount)) . '</td>';
            $out .= '<td>' . ((float) $quote->mrc_quote_amount > 0 ? $this->e($commerce->formatPrice((float) $quote->mrc_quote_amount)) : '-') . '</td>';
            $out .= '<td>' . $this->e(date('Y-m-d H:i', (int) $quote->created)) . '</td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    protected function renderQuoteDetail(Page $quote, Mercato $commerce, ?array $result = null): string {
        $items = json_decode((string) $quote->mrc_items, true) ?: [];
        $details = json_decode((string) $quote->mrc_quote_details, true) ?: [];
        $out = '<section class="pw-wrap mrc-admin-panel"><p><a href="' . $this->e($this->adminUrl('quotes/')) . '">&larr; ' . $this->e($this->_('Quote requests')) . '</a></p>';
        $out .= '<h2 class="uk-h3">' . $this->e((string) $quote->mrc_quote_number) . '</h2>';
        if ($result) {
            $class = empty($result['errors']) ? 'uk-alert-success' : 'uk-alert-danger';
            $out .= '<div class="uk-alert ' . $class . '">' . $this->e((string) $result['summary']);
            foreach ((array) ($result['errors'] ?? []) as $error) $out .= '<br>' . $this->e((string) $error);
            $out .= '</div>';
        }
        $out .= '<dl class="uk-description-list"><dt>' . $this->e($this->_('Customer')) . '</dt><dd>' . $this->e(trim((string) $quote->mrc_first_name . ' ' . (string) $quote->mrc_last_name)) . ' — ' . $this->e((string) $quote->mrc_email) . '</dd>';
        $out .= '<dt>' . $this->e($this->_('Requested total')) . '</dt><dd>' . $this->e($commerce->formatPrice((float) $quote->mrc_total_amount)) . '</dd>';
        $out .= '<dt>' . $this->e($this->_('Fulfilment')) . '</dt><dd>' . $this->e((string) $quote->mrc_fulfilment_label) . '</dd>';
        $out .= '<dt>' . $this->e($this->_('Notes')) . '</dt><dd>' . nl2br($this->e((string) $quote->mrc_notes)) . '</dd></dl>';
        $out .= '<table class="uk-table uk-table-small uk-table-divider"><thead><tr><th>' . $this->e($this->_('Item')) . '</th><th>' . $this->e($this->_('Qty')) . '</th><th>' . $this->e($this->_('Snapshot price')) . '</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $out .= '<tr><td>' . $this->e((string) ($item['title'] ?? $item['id'] ?? '')) . '</td><td>' . $this->e((string) ($item['quantity'] ?? 1)) . '</td><td>' . $this->e($commerce->formatPrice((float) ($item['sum'] ?? 0))) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        if ($this->hasCommercePermission(self::PERMISSION_MANAGE_QUOTES)) {
            $out .= '<form method="post" class="uk-form-stacked"><input type="hidden" name="mrc_action" value="update_quote">' . $this->renderCsrfInput();
            $currentStatus = (string) $quote->mrc_quote_status;
            $out .= '<div class="uk-grid-small" uk-grid><div class="uk-width-1-3@m"><label class="uk-form-label" for="mrc-quote-status">' . $this->e($this->_('Status')) . '</label><select class="uk-select" id="mrc-quote-status" name="quote_status">';
            foreach (MercatoQuoteStatus::all() as $status) {
                if ($status !== $currentStatus && !MercatoQuoteStatus::canTransition($currentStatus, $status)) continue;
                $out .= '<option value="' . $this->e($status) . '"' . ($currentStatus === $status ? ' selected' : '') . '>' . $this->e($status) . '</option>';
            }
            $out .= '</select></div><div class="uk-width-1-3@m"><label class="uk-form-label" for="mrc-quote-amount">' . $this->e($this->_('Quoted amount')) . '</label><input class="uk-input" id="mrc-quote-amount" type="number" min="0" step="0.01" name="quote_amount" value="' . $this->e((string) $quote->mrc_quote_amount) . '"></div>';
            $out .= '<div class="uk-width-1-3@m"><label class="uk-form-label" for="mrc-quote-note">' . $this->e($this->_('Customer note')) . '</label><input class="uk-input" id="mrc-quote-note" name="quote_note"></div></div><p><button class="uk-button uk-button-primary" type="submit">' . $this->e($this->_('Update quote')) . '</button></p></form>';
        }
        $out .= '<h3 class="uk-h4">' . $this->e($this->_('History')) . '</h3><ul class="uk-list uk-list-divider">';
        foreach (array_reverse((array) ($details['history'] ?? [])) as $event) {
            $out .= '<li><strong>' . $this->e((string) ($event['to'] ?? '')) . '</strong> ' . $this->e((string) ($event['at'] ?? '')) . '<br><small>' . $this->e((string) ($event['note'] ?? '')) . '</small></li>';
        }
        return $out . '</ul></section>';
    }
}
