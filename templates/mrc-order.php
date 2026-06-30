<?php
namespace ProcessWire;
/**
 * mrc-order.php
 *
 * Template for individual order pages (stored under /orders/).
 * These pages are not meant to be publicly accessible.
 * Redirect non-admins away.
 */

if (!$user->isSuperuser() && !$user->hasPermission('mrc-orders')) {
    $session->redirect($config->urls->root);
}

// This template is intentionally minimal.
// If you want a public order confirmation page, use mrc-success.php instead.
echo '<!-- Mercato order page: ' . $sanitizer->entities($page->title) . ' -->';
