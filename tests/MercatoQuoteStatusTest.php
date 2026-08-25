<?php
namespace ProcessWire;

require dirname(__DIR__) . '/src/Quote/MercatoQuoteStatus.php';

function quoteCheck(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
}

quoteCheck(MercatoQuoteStatus::canTransition('submitted', 'under-review'), 'Submitted quote should enter review.');
quoteCheck(MercatoQuoteStatus::canTransition('under-review', 'quoted'), 'Reviewed quote should become quoted.');
quoteCheck(MercatoQuoteStatus::canTransition('quoted', 'accepted'), 'Quoted request should be accepted.');
quoteCheck(MercatoQuoteStatus::canTransition('accepted', 'converted'), 'Accepted quote should convert.');
quoteCheck(!MercatoQuoteStatus::canTransition('submitted', 'converted'), 'Submitted quote must not skip to converted.');
quoteCheck(!MercatoQuoteStatus::canTransition('declined', 'quoted'), 'Declined quote must remain terminal.');
quoteCheck(count(MercatoQuoteStatus::all()) === 7, 'Quote lifecycle should expose seven statuses.');

echo "Mercato quote status tests passed.\n";
