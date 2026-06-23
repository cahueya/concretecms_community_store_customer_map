<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Value;

defined('C5_EXECUTE') or die('Access Denied.');

class CustomerAddress
{
    private string $addressHash;
    private string $normalizedAddress;
    private string $displayAddress;
    /** @var array<string, string> */
    private array $parts;
    /** @var int[] */
    private array $orderIDs = [];
    /** @var int[] */
    private array $customerIDs = [];
    private int $orderCount = 0;
    private int $paidOrderCount = 0;
    private int $unpaidOrderCount = 0;
    private float $totalValue = 0.0;
    private float $paidTotalValue = 0.0;
    private ?\DateTimeInterface $lastOrderDate = null;

    /**
     * @param array<string, string> $parts
     */
    public function __construct(string $normalizedAddress, string $displayAddress, array $parts = [])
    {
        $this->normalizedAddress = $normalizedAddress;
        $this->displayAddress = $displayAddress;
        $this->parts = array_map(static function ($value) {
            return trim((string) $value);
        }, $parts);
        $this->addressHash = hash('sha256', mb_strtolower($normalizedAddress));
    }

    public function addOrder(int $orderID, ?int $customerID, bool $paid, float $total, ?\DateTimeInterface $date): void
    {
        $this->orderIDs[$orderID] = $orderID;
        if ($customerID !== null && $customerID > 0) {
            $this->customerIDs[$customerID] = $customerID;
        }
        ++$this->orderCount;
        $this->totalValue += $total;
        if ($paid) {
            ++$this->paidOrderCount;
            $this->paidTotalValue += $total;
        } else {
            ++$this->unpaidOrderCount;
        }
        if ($date && (!$this->lastOrderDate || $date > $this->lastOrderDate)) {
            $this->lastOrderDate = $date;
        }
    }

    public function getAddressHash(): string { return $this->addressHash; }
    public function getNormalizedAddress(): string { return $this->normalizedAddress; }
    public function getDisplayAddress(): string { return $this->displayAddress; }
    /** @return array<string, string> */
    public function getParts(): array { return $this->parts; }
    public function getPart(string $key): string { return $this->parts[$key] ?? ''; }
    public function getOrderIDs(): array { return array_values($this->orderIDs); }
    public function getCustomerIDs(): array { return array_values($this->customerIDs); }
    public function getOrderCount(): int { return $this->orderCount; }
    public function getPaidOrderCount(): int { return $this->paidOrderCount; }
    public function getUnpaidOrderCount(): int { return $this->unpaidOrderCount; }
    public function getTotalValue(): float { return $this->totalValue; }
    public function getPaidTotalValue(): float { return $this->paidTotalValue; }
    public function getCustomerCount(): int { return count($this->customerIDs); }
    public function getLastOrderDate(): ?\DateTimeInterface { return $this->lastOrderDate; }
}
