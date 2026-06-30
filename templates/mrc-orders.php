<?php
namespace ProcessWire;
/**
 * mrc-orders.php
 *
 * Template for the /orders/ parent page.
 * Hidden from public. Superusers can access it in the admin.
 */

if (!$user->isSuperuser()) {
    $session->redirect($config->urls->root);
}

echo "<!-- Mercato orders parent -->";
