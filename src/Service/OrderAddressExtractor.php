<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service;

use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStoreCustomerMap\Value\CustomerAddress;

defined('C5_EXECUTE') or die('Access Denied.');

class OrderAddressExtractor
{
    /**
     * @return array<string, CustomerAddress>
     */
    public function collectAggregatedAddresses(bool $includeUnpaid = false, ?string $fromDate = null, ?string $toDate = null, int $limit = 0): array
    {
        $list = new OrderList();
        $list->setPaid($includeUnpaid ? null : true);
        $list->setCancelled(false);
        $list->setRefunded(null);
        if ($fromDate) {
            $list->setFromDate($fromDate);
        }
        if ($toDate) {
            $list->setToDate($toDate);
        }
        if ($limit > 0 && method_exists($list, 'setLimit')) {
            $list->setLimit($limit);
        }

        $addresses = [];
        foreach ($list->getResults() as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $address = $this->extractBillingAddress($order);
            if (!$address) {
                continue;
            }
            $hash = $address->getAddressHash();
            if (!isset($addresses[$hash])) {
                $addresses[$hash] = $address;
            }
            $addresses[$hash]->addOrder(
                (int) $order->getOrderID(),
                $order->getCustomerID() ? (int) $order->getCustomerID() : null,
                $this->isPaid($order),
                (float) $order->getTotal(),
                $this->normalizeDate($order->getOrderDate())
            );
        }

        return $addresses;
    }

    private function extractBillingAddress(Order $order): ?CustomerAddress
    {
        $parts = [
            'address1' => $this->getAddressValue($order, 'address1'),
            'address2' => $this->getAddressValue($order, 'address2'),
            'city' => $this->getAddressValue($order, 'city'),
            'state_province' => $this->getAddressValue($order, 'state_province'),
            'postal_code' => $this->getAddressValue($order, 'postal_code'),
            'country' => $this->getAddressValue($order, 'country'),
        ];

        $normalized = $this->normalizeAddress($parts);
        if ($normalized === '') {
            return null;
        }

        $display = $this->formatDisplayAddress($parts);

        return new CustomerAddress($normalized, $display ?: $normalized, $parts);
    }

    private function getAddressValue(Order $order, string $valueName): string
    {
        if (method_exists($order, 'getAddressValue')) {
            $value = $order->getAddressValue('billing_address', $valueName);
            return trim((string) $value);
        }

        $address = $order->getAttribute('billing_address');
        if (is_object($address) && method_exists($address, 'getValue')) {
            return trim((string) $address->getValue($valueName));
        }

        return '';
    }

    private function normalizeAddress(array $parts): string
    {
        $postalCode = $this->normalizePart($parts['postal_code'] ?? '');
        $country = $this->normalizePart($parts['country'] ?? '');

        if ($postalCode === '' || $country === '') {
            return '';
        }

        return mb_strtolower('postalcode:' . mb_strtoupper($postalCode) . '|country:' . mb_strtoupper($country));
    }

    private function formatDisplayAddress(array $parts): string
    {
        $postalCode = mb_strtoupper($this->normalizePart($parts['postal_code'] ?? ''));
        $country = $this->normalizePart($parts['country'] ?? '');

        if ($postalCode === '' || $country === '') {
            return '';
        }

        return trim($postalCode . ', ' . $country);
    }

    private function normalizePart(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return $value ?: '';
    }

    private function isPaid(Order $order): bool
    {
        return $order->getPaid() !== null && $order->getRefunded() === null;
    }

    private function normalizeDate($date): ?\DateTimeInterface
    {
        if ($date instanceof \DateTimeInterface) {
            return $date;
        }
        if ($date) {
            try {
                return new \DateTimeImmutable((string) $date);
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }
}
