<?php
require_once __DIR__ . '/../src/Payment/MercatoPaymentStatus.php';

use ProcessWire\MercatoPaymentStatus;

foreach ([MercatoPaymentStatus::PROCESSING, MercatoPaymentStatus::PENDING, MercatoPaymentStatus::FAILED, MercatoPaymentStatus::CANCELED, MercatoPaymentStatus::EXPIRED] as $lateStatus) {
    if (!MercatoPaymentStatus::wouldRegressSettled(MercatoPaymentStatus::PAID, $lateStatus)) {
        throw new RuntimeException('Paid payment accepted a regressive status: ' . $lateStatus);
    }
}
if (MercatoPaymentStatus::wouldRegressSettled(MercatoPaymentStatus::FAILED, MercatoPaymentStatus::PAID)) {
    throw new RuntimeException('Delayed successful payment must be allowed after a failure status.');
}
if (MercatoPaymentStatus::wouldRegressSettled(MercatoPaymentStatus::PAID, MercatoPaymentStatus::PAID)) {
    throw new RuntimeException('Repeated paid webhook must remain idempotent.');
}

echo "Mercato payment status regression tests passed.\n";
