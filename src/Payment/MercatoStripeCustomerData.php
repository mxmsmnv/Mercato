<?php
namespace ProcessWire;

/**
 * Builds the customer-owned billing details sent to Stripe.
 *
 * Keep these values out of metadata: Stripe exposes metadata broadly in its
 * dashboard and webhook payloads, while billing details already have a
 * provider-defined privacy and retention boundary.
 */
final class MercatoStripeCustomerData {

    public static function fromPendingOrder(array $pendingOrder): array {
        $firstName = self::value($pendingOrder, ['first_name', 'mrc_first_name']);
        $lastName = self::value($pendingOrder, ['last_name', 'mrc_last_name']);
        $name = trim($firstName . ' ' . $lastName);
        $email = self::value($pendingOrder, ['email', 'mrc_email']);
        $phone = self::value($pendingOrder, ['phone', 'mrc_phone']);
        $details = [];

        if ($name !== '') {
            $details['name'] = $name;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $details['email'] = $email;
        }
        if ($phone !== '') {
            $details['phone'] = $phone;
        }

        $country = strtoupper(self::value($pendingOrder, ['country', 'mrc_country']));
        $address = array_filter([
            'line1' => self::value($pendingOrder, ['address', 'address_line_1', 'mrc_address']),
            'line2' => self::value($pendingOrder, ['address_2', 'address_line_2', 'mrc_address_2']),
            'city' => self::value($pendingOrder, ['city', 'mrc_city']),
            'state' => self::value($pendingOrder, ['region', 'state', 'mrc_region']),
            'postal_code' => self::value($pendingOrder, ['zip', 'postal_code', 'mrc_zip']),
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        ], static fn(string $value): bool => $value !== '');

        if ($address !== []) {
            $details['address'] = $address;
        }

        return $details;
    }

    private static function value(array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || !is_scalar($source[$key])) {
                continue;
            }
            $value = preg_replace('/\s+/u', ' ', trim((string) $source[$key]));
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
