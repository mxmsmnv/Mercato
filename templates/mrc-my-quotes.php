<?php
namespace ProcessWire;

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-my-quotes')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';
$ui = $commerce->getFrontendUiClasses();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$quotes = $user->isGuest() ? new PageArray() : $commerce->quoteService()->findForCustomer($user);
$seoHead = $commerce->seoService()->render($page, ['private' => true]);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $seoHead ?>
    <?= mrc_storefront_assets($isVanilla) ?>
    <?= $commerce->renderFrontendFrameworkAssets() ?>
</head>
<body class="<?= $ui['body'] ?>">
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer) ?>
<main class="<?= $ui['shell'] ?>">
    <section class="<?= $ui['panel'] ?>">
        <span class="<?= $ui['kicker'] ?>">Account</span>
        <h1>My quote requests</h1>
        <?php if ($user->isGuest()): ?>
            <p>Sign in to view quote requests attached to your customer account. Guest requests remain available through their signed email links.</p>
        <?php elseif (!$quotes->count()): ?>
            <p>No quote requests are attached to this account.</p>
        <?php else: ?>
            <div class="mrc-table-wrap"><table class="mrc-table"><thead><tr><th>Quote</th><th>Status</th><th>Requested</th><th>Quoted</th><th>Created</th></tr></thead><tbody>
            <?php foreach ($quotes as $quote): ?>
                <tr>
                    <td><a href="<?= $sanitizer->entities($commerce->quoteService()->getPublicUrl($quote)) ?>"><?= $sanitizer->entities((string) $quote->mrc_quote_number) ?></a></td>
                    <td><?= $sanitizer->entities((string) $quote->mrc_quote_status) ?></td>
                    <td><?= $sanitizer->entities($commerce->formatPrice((float) $quote->mrc_total_amount)) ?></td>
                    <td><?= (float) $quote->mrc_quote_amount > 0 ? $sanitizer->entities($commerce->formatPrice((float) $quote->mrc_quote_amount)) : '—' ?></td>
                    <td><?= $sanitizer->entities(date('Y-m-d', (int) $quote->created)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</main>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
</body>
</html>
