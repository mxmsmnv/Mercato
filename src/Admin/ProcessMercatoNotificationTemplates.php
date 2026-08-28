<?php
namespace ProcessWire;

trait ProcessMercatoNotificationTemplates {

    public function ___executeNotifications(): string {
        if (!$this->hasCommercePermission(self::PERMISSION_ADMIN)) {
            return $this->renderAccessDenied(self::PERMISSION_ADMIN, 'notifications');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $input = $this->wire('input');
        if ($input->requestMethod('POST')) {
            $action = '';
            $event = (string) $this->wire('sanitizer')->name((string) $input->post('template_key'));
            try {
                if (!$this->validateCsrf()) throw new WireException('The request could not be verified. Please reload and try again.');
                $action = (string) $this->wire('sanitizer')->name((string) $input->post('notification_action'));
                if ($action === 'save_template') {
                    $commerce->saveNotificationTemplate(
                        $event,
                        (string) $input->post('subject'),
                        (string) $input->post('text_body'),
                        (string) $input->post('html_body_' . $event),
                        $this->wire('user')
                    );
                    $this->message($this->_('Transactional email template saved.'));
                } elseif ($action === 'reset_template') {
                    $commerce->resetNotificationTemplate($event, $this->wire('user'));
                    $this->message($this->_('Template restored to its configured default.'));
                } elseif ($action === 'save_layout') {
                    $commerce->saveNotificationMailLayout(
                        (string) $input->post('notification_header_html'),
                        (string) $input->post('notification_footer_html'),
                        $this->wire('user')
                    );
                    $this->message($this->_('Shared email layout saved.'));
                }
            } catch (\Throwable $error) {
                $this->error($error->getMessage());
            }
            $suffix = $action === 'save_layout' ? '#shared-layout' : '?template=' . rawurlencode($event) . '#notification-editor';
            $this->wire('session')->redirect($this->adminUrl('notifications/') . $suffix);
        }

        $templates = $commerce->notificationTemplates();
        $requested = (string) $this->wire('sanitizer')->name((string) $input->get('template'));
        $selected = $templates[$requested] ?? (array) reset($templates);
        $selectedKey = (string) ($selected['template_key'] ?? '');
        $layout = $commerce->notificationMailLayout();
        $samples = MercatoEmailEventCatalog::samples();
        $samples['store_name'] = trim((string) $commerce->notification_sender_name) ?: 'Mercato Store';
        $sampleJson = json_encode($samples, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $customCount = count(array_filter($templates, static fn(array $template): bool => !empty($template['customized'])));
        $enabledCount = count((array) $commerce->enabled_notification_events);
        $delivery = $commerce->notificationDeliveryService()->getSetupStatus();
        $deliveryLabel = !empty($delivery['ready']) ? $this->_('Ready') : $this->_('Needs configuration');

        $this->headline($this->_('Mercato Notifications'));
        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard mrc-notification-designer">' . $this->renderAdminNav('notifications');
        $out .= '<section class="pw-wrap mrc-admin-header mrc-notification-intro"><div><div class="ds-section-label">' . $this->e($this->_('Transactional email')) . '</div><h2 class="uk-h2">' . $this->e($this->_('Notification designer')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Edit customer messages visually, keep a plain-text fallback, insert safe order variables, and review the complete email before saving.')) . '</p></div><div class="mrc-notification-delivery"><span>' . $this->e($this->_('Delivery')) . '</span><strong>' . $this->e($deliveryLabel) . '</strong><small>' . $this->e((string) ($delivery['transport'] ?? 'wiremail')) . '</small></div></section>';
        $out .= '<section class="mrc-notification-stats" aria-label="' . $this->e($this->_('Notification template summary')) . '">'
            . '<article><span>' . $this->e($this->_('Templates')) . '</span><strong>' . count($templates) . '</strong><small>' . $this->e($this->_('Commerce events')) . '</small></article>'
            . '<article><span>' . $this->e($this->_('Enabled')) . '</span><strong>' . $enabledCount . '</strong><small>' . $this->e($this->_('Delivery events')) . '</small></article>'
            . '<article><span>' . $this->e($this->_('Customized')) . '</span><strong>' . $customCount . '</strong><small>' . $this->e($this->_('Saved visual designs')) . '</small></article>'
            . '<article><span>' . $this->e($this->_('Shared layout')) . '</span><strong>' . (((string) ($layout['header'] ?? '') !== '' || (string) ($layout['footer'] ?? '') !== '') ? $this->e($this->_('Custom')) : $this->e($this->_('Default'))) . '</strong><small>' . $this->e($this->_('Header and footer')) . '</small></article></section>';

        $out .= '<nav class="mrc-notification-picker" aria-label="' . $this->e($this->_('Choose notification template')) . '"><ul class="uk-subnav uk-subnav-pill">';
        foreach ($templates as $template) {
            $key = (string) $template['template_key'];
            $active = $key === $selectedKey ? ' class="uk-active"' : '';
            $custom = !empty($template['customized']) ? '<em>' . $this->e($this->_('Custom')) . '</em>' : '';
            $out .= '<li' . $active . '><a href="?template=' . rawurlencode($key) . '#notification-editor"><span>' . $this->e((string) $template['label']) . '</span><small>' . $this->e((string) $template['recipient']) . $custom . '</small></a></li>';
        }
        $out .= '</ul></nav>';

        if ($selectedKey !== '') {
            $editorId = 'mrc-notification-editor-' . str_replace('_', '-', $selectedKey);
            $out .= '<section class="mrc-notification-workspace pw-wrap" id="notification-editor"><header><div><span class="uk-label">' . $this->e((string) $selected['recipient']) . '</span><h2>' . $this->e((string) $selected['label']) . '</h2><p>' . $this->e((string) $selected['purpose']) . '</p></div><code>' . $this->e($selectedKey) . '</code></header>';
            $out .= '<form method="post" data-mrc-notification-form data-mrc-mail-header="' . $this->e((string) ($layout['header'] ?? '')) . '" data-mrc-mail-footer="' . $this->e((string) ($layout['footer'] ?? '')) . '" data-mrc-samples="' . $this->e($sampleJson) . '">' . $this->renderCsrfInput()
                . '<input type="hidden" name="notification_action" value="save_template"><input type="hidden" name="template_key" value="' . $this->e($selectedKey) . '"><div class="mrc-notification-grid"><div class="mrc-notification-editor">'
                . '<label class="uk-form-label" for="mrc-notification-subject">' . $this->e($this->_('Email subject')) . '</label><input class="uk-input" id="mrc-notification-subject" name="subject" maxlength="240" required value="' . $this->e((string) $selected['subject']) . '" data-mrc-notification-subject>'
                . '<div class="mrc-notification-variables"><span>' . $this->e($this->_('Insert variable')) . '</span>';
            foreach ((array) $selected['variables'] as $variable) {
                $placeholder = '{' . $variable . '}';
                $out .= '<button class="uk-button uk-button-default uk-button-small" type="button" data-mrc-variable="' . $this->e($placeholder) . '" data-mrc-editor="' . $this->e($editorId) . '">' . $this->e($placeholder) . '</button>';
            }
            $out .= '</div><label class="uk-form-label uk-display-block" for="' . $this->e($editorId) . '">' . $this->e($this->_('HTML message')) . '</label>'
                . $this->renderNotificationEditor('html_body_' . $selectedKey, $editorId, (string) $selected['html'], 400)
                . '<label class="uk-form-label uk-display-block uk-margin-top" for="mrc-notification-text">' . $this->e($this->_('Plain-text fallback')) . '</label><textarea class="uk-textarea uk-font-monospace" id="mrc-notification-text" name="text_body" rows="10" required data-mrc-notification-text>' . $this->e((string) $selected['text']) . '</textarea>'
                . '<div class="mrc-notification-actions"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-save uk-margin-small-right"></i>' . $this->e($this->_('Save template')) . '</button><span class="uk-text-meta">' . $this->e($this->_('The preview uses sample data only.')) . '</span></div></div>'
                . '<aside class="mrc-notification-preview"><div class="mrc-notification-preview-head"><span><i class="fa fa-envelope-o" aria-hidden="true"></i> ' . $this->e($this->_('Email preview')) . '</span><span class="uk-label">' . $this->e((string) $selected['recipient']) . '</span></div><div class="mrc-notification-preview-subject" data-mrc-preview-subject></div><iframe title="' . $this->e((string) $selected['label']) . ' ' . $this->e($this->_('preview')) . '" sandbox="" data-mrc-preview-frame></iframe><p class="uk-text-meta"><i class="fa fa-info-circle uk-margin-small-right"></i>' . $this->e($this->_('Variables are replaced only inside this isolated preview.')) . '</p></aside></div></form>';
            if (!empty($selected['customized'])) {
                $out .= '<form class="mrc-notification-reset" method="post" onsubmit="return confirm(\'' . $this->e($this->_('Restore this template to its configured default?')) . '\')">' . $this->renderCsrfInput() . '<input type="hidden" name="notification_action" value="reset_template"><input type="hidden" name="template_key" value="' . $this->e($selectedKey) . '"><button class="uk-button uk-button-text uk-text-danger" type="submit">' . $this->e($this->_('Restore default template')) . '</button></form>';
            }
            $out .= '</section>';
        }

        $headerId = 'mrc-notification-layout-header';
        $footerId = 'mrc-notification-layout-footer';
        $out .= '<section class="mrc-notification-layout pw-wrap" id="shared-layout"><header><div><p class="ds-section-label">' . $this->e($this->_('Shared design')) . '</p><h2>' . $this->e($this->_('Email header and footer')) . '</h2><p>' . $this->e($this->_('These self-contained blocks wrap every transactional message. Use inline styles for reliable email-client rendering.')) . '</p></div></header>'
            . '<form method="post" data-mrc-layout-form data-mrc-samples="' . $this->e($sampleJson) . '">' . $this->renderCsrfInput() . '<input type="hidden" name="notification_action" value="save_layout"><div class="mrc-notification-layout-grid"><div><label class="uk-form-label" for="' . $headerId . '">' . $this->e($this->_('Shared header')) . '</label>' . $this->renderNotificationEditor('notification_header_html', $headerId, (string) ($layout['header'] ?? ''), 280) . '</div><div><label class="uk-form-label" for="' . $footerId . '">' . $this->e($this->_('Shared footer')) . '</label>' . $this->renderNotificationEditor('notification_footer_html', $footerId, (string) ($layout['footer'] ?? ''), 280) . '</div></div><div class="mrc-notification-layout-preview"><div><strong>' . $this->e($this->_('Shared layout preview')) . '</strong><small>' . $this->e($this->_('Sample message appears between the two blocks.')) . '</small></div><iframe title="' . $this->e($this->_('Shared email layout preview')) . '" sandbox="" data-mrc-layout-preview></iframe></div><div class="mrc-notification-actions"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-save uk-margin-small-right"></i>' . $this->e($this->_('Save shared layout')) . '</button></div></form></section>';

        return $out . '</div>';
    }

    protected function renderNotificationEditor(string $name, string $id, string $value, int $height): string {
        $editor = $this->wire('modules')->get('InputfieldTinyMCE');
        if (!$editor) return '<textarea class="uk-textarea uk-font-monospace" id="' . $this->e($id) . '" name="' . $this->e($name) . '" rows="14" required>' . $this->e($value) . '</textarea>';
        $settings = [
            'height' => $height,
            'resize' => true,
            'plugins' => 'anchor code link lists table',
            'toolbar' => 'undo redo | blocks | bold italic | link blockquote | bullist numlist | table hr | removeformat | code',
            'menubar' => false,
            'contextmenu' => 'link unlink lists table removeformat',
        ];
        $editor->attr('name', $name);
        $editor->attr('id', $id);
        $editor->val($value);
        $editor->height = $height;
        $editor->features = ['toolbar', 'statusbar', 'stickybars', 'purifier', 'pasteFilter'];
        $editor->settingsJSON = json_encode($settings);
        $editor->renderReady();
        $rendered = $editor->render();
        $wrapAttributes = '';
        foreach ($editor->wrapAttr() as $attribute => $attributeValue) {
            if (!in_array($attribute, ['data-settings', 'data-features', 'data-configName'], true) && !str_starts_with($attribute, 'data-upload')) continue;
            $wrapAttributes .= ' ' . $attribute . '="' . $this->e($attributeValue) . '"';
        }
        return '<div id="wrap_' . $this->e($id) . '" class="Inputfield InputfieldTinyMCE mrc-notification-tinymce"' . $wrapAttributes . '>' . $rendered . '</div>';
    }
}
