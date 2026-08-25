<?php
namespace ProcessWire;

if (!$user->isSuperuser() && !$user->hasPermission('mercato-view-quotes')) {
    $session->redirect($config->urls->root);
}
echo '<!-- Mercato quote requests parent -->';
