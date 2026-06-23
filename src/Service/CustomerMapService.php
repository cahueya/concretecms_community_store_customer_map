<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service;

use Concrete\Core\Application\Application;
use Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder\GeocoderInterface;
use Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder\GeocodeResult;
use Concrete\Package\CommunityStoreCustomerMap\Value\CustomerAddress;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price as StorePrice;
use Doctrine\DBAL\Connection;

defined('C5_EXECUTE') or die('Access Denied.');

class CustomerMapService
{
    private Application $app;
    private Connection $db;
    private OrderAddressExtractor $extractor;
    private GeocoderFactory $geocoderFactory;

    public function __construct(Application $app, OrderAddressExtractor $extractor, GeocoderFactory $geocoderFactory)
    {
        $this->app = $app;
        $this->db = $app->make('database')->connection();
        $this->extractor = $extractor;
        $this->geocoderFactory = $geocoderFactory;
    }

    public function refresh(bool $includeUnpaid = false, ?string $fromDate = null, ?string $toDate = null, int $maxGeocodes = 25, bool $retryFailed = false): array
    {
        $addresses = $this->extractor->collectAggregatedAddresses($includeUnpaid, $fromDate, $toDate);
        $geocoder = $this->geocoderFactory->create();
        $geocodedThisRun = 0;
        $failedThisRun = 0;
        $skippedThisRun = 0;
        $createdPendingThisRun = 0;

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('DELETE FROM CommunityStoreCustomerMapAggregates');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($addresses as $address) {
            $geocode = $this->getGeocodeRow($address->getAddressHash());

            if (!$this->hasCoordinates($geocode)) {
                $limitReached = $maxGeocodes <= 0 || ($geocodedThisRun + $failedThisRun) >= $maxGeocodes;

                if ($limitReached) {
                    ++$skippedThisRun;
                    if ($this->ensurePendingGeocode($address)) {
                        ++$createdPendingThisRun;
                        $geocode = $this->getGeocodeRow($address->getAddressHash());
                    }
                } elseif ($this->shouldAttemptGeocode($geocode, $retryFailed)) {
                    $result = $this->attemptGeocode($geocoder, $address);
                    if ($result) {
                        ++$geocodedThisRun;
                    } else {
                        ++$failedThisRun;
                    }
                    $geocode = $this->getGeocodeRow($address->getAddressHash());
                    $this->respectRateLimit();
                } else {
                    ++$skippedThisRun;
                    if (!$geocode && $this->ensurePendingGeocode($address)) {
                        ++$createdPendingThisRun;
                        $geocode = $this->getGeocodeRow($address->getAddressHash());
                    }
                }
            }

            $this->saveAggregate($address, $geocode ?: []);
        }

        $progress = $this->getGeocodeProgress();

        return [
            'addresses' => count($addresses),
            'geocoded' => $geocodedThisRun,
            'failed' => $failedThisRun,
            'skipped' => $skippedThisRun,
            'createdPending' => $createdPendingThisRun,
            'mappable' => $this->getMappableAddressCount(),
            'pending' => $progress['pending'],
            'failedTotal' => $progress['failed'],
            'completePercent' => $progress['completePercent'],
        ];
    }

    public function getMapPoints(bool $includeUnpaid = false, string $metric = 'orders', string $level = 'address'): array
    {
        $metric = $metric === 'value' ? 'value' : 'orders';
        $level = 'postal';
        $addressRows = $this->fetchMappableRows();
        $rows = $level === 'postal' ? $this->buildPostalRegions($addressRows, $metric, $includeUnpaid) : $this->buildAddressPoints($addressRows, $metric, $includeUnpaid);
        $maxValue = $this->maxMetricValue($rows);
        $points = [];

        foreach ($rows as $row) {
            $value = (float) ($row['metricValue'] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $levelValue = $maxValue > 0 ? (int) ceil(($value / $maxValue) * 100) : 1;
            $levelValue = max(1, min(100, $levelValue));
            $row['level'] = $levelValue;
            $row['color'] = $this->colorForLevel($levelValue);
            $points[] = $row;
        }

        $topRegions = $this->getTopRegions($includeUnpaid, $metric, 10);
        $opportunities = $this->getOpportunities($includeUnpaid, $metric, 8);

        return [
            'maxValue' => $maxValue,
            'maxValueFormatted' => $metric === 'value' ? $this->formatMoney($maxValue) : number_format($maxValue),
            'metric' => $metric,
            'level' => $level,
            'includeUnpaid' => $includeUnpaid,
            'points' => $points,
            'topRegions' => $topRegions,
            'opportunities' => $opportunities,
            'stats' => $this->getStats(),
        ];
    }

    public function getTopRegions(bool $includeUnpaid = false, string $metric = 'orders', int $limit = 10): array
    {
        $regions = $this->buildPostalRegions($this->fetchMappableRows(), $metric, $includeUnpaid);
        usort($regions, static function (array $a, array $b): int {
            return ($b['metricValue'] <=> $a['metricValue']) ?: ($b['paidTotalValue'] <=> $a['paidTotalValue']);
        });

        return array_slice(array_map(function (array $region): array {
            $region['opportunity'] = $this->opportunityForRegion($region);
            return $region;
        }, $regions), 0, $limit);
    }

    public function getOpportunities(bool $includeUnpaid = false, string $metric = 'orders', int $limit = 8): array
    {
        $regions = $this->buildPostalRegions($this->fetchMappableRows(), $metric, $includeUnpaid);
        $globalAverageOrderValue = $this->globalAverageOrderValue($regions);
        $maxPaidValue = $this->maxFieldValue($regions, 'paidTotalValue');

        foreach ($regions as &$region) {
            $region['opportunity'] = $this->opportunityForRegion($region, $globalAverageOrderValue, $maxPaidValue);
            $region['opportunityScore'] = (float) ($region['opportunity']['score'] ?? 0);
        }
        unset($region);

        $regions = array_values(array_filter($regions, static function (array $region): bool {
            return ($region['opportunityScore'] ?? 0) > 0;
        }));

        usort($regions, static function (array $a, array $b): int {
            return ($b['opportunityScore'] <=> $a['opportunityScore']) ?: ($b['metricValue'] <=> $a['metricValue']);
        });

        return array_slice($regions, 0, $limit);
    }

    public function getStats(): array
    {
        $aggregateStats = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS addresses,
                    SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) AS mappable,
                    COUNT(DISTINCT CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL AND postalCode IS NOT NULL AND postalCode != \'\' THEN CONCAT(LOWER(country), \'|\', LOWER(postalCode)) ELSE NULL END) AS postalRegions,
                    COALESCE(SUM(orderCount), 0) AS orders,
                    COALESCE(SUM(paidOrderCount), 0) AS paidOrders,
                    COALESCE(SUM(totalValue), 0) AS totalValue,
                    COALESCE(SUM(paidTotalValue), 0) AS paidTotalValue
             FROM CommunityStoreCustomerMapAggregates'
        );
        $progress = $this->getGeocodeProgress();

        return [
            'addresses' => (int) ($aggregateStats['addresses'] ?? 0),
            'mappable' => (int) ($aggregateStats['mappable'] ?? 0),
            'postalRegions' => (int) ($aggregateStats['postalRegions'] ?? 0),
            'orders' => (int) ($aggregateStats['orders'] ?? 0),
            'paidOrders' => (int) ($aggregateStats['paidOrders'] ?? 0),
            'totalValue' => (float) ($aggregateStats['totalValue'] ?? 0),
            'paidTotalValue' => (float) ($aggregateStats['paidTotalValue'] ?? 0),
            'totalValueFormatted' => $this->formatMoney((float) ($aggregateStats['totalValue'] ?? 0)),
            'paidTotalValueFormatted' => $this->formatMoney((float) ($aggregateStats['paidTotalValue'] ?? 0)),
            'geocodeTotal' => $progress['total'],
            'geocodeOk' => $progress['ok'],
            'geocodePending' => $progress['pending'],
            'geocodeFailed' => $progress['failed'],
            'geocodePendingOrFailed' => $progress['pendingOrFailed'],
            'geocodeCompletePercent' => $progress['completePercent'],
        ];
    }

    public function getGeocodeProgress(): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'ok' AND latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status = 'failed' AND (nextRetryAt IS NULL OR nextRetryAt <= NOW()) THEN 1 ELSE 0 END) AS failedDue
             FROM CommunityStoreCustomerMapGeocodes"
        );

        $total = (int) ($row['total'] ?? 0);
        $ok = (int) ($row['ok'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $failedDue = (int) ($row['failedDue'] ?? 0);
        $completePercent = $total > 0 ? (int) floor(($ok / $total) * 100) : 0;

        return [
            'total' => $total,
            'ok' => $ok,
            'pending' => $pending,
            'failed' => $failed,
            'failedDue' => $failedDue,
            'pendingOrFailed' => $pending + $failed,
            'completePercent' => $completePercent,
        ];
    }

    public function saveSettings(array $data): void
    {
        $config = $this->app->make('config');
        $provider = in_array(($data['provider'] ?? 'nominatim'), ['nominatim', 'none'], true) ? $data['provider'] : 'nominatim';
        $config->save('community_store_customer_map.geocoder.provider', $provider);
        $config->save('community_store_customer_map.geocoder.nominatim_email', trim((string) ($data['nominatim_email'] ?? '')));
        $config->save('community_store_customer_map.geocoder.nominatim_endpoint', trim((string) ($data['nominatim_endpoint'] ?? 'https://nominatim.openstreetmap.org/search')) ?: 'https://nominatim.openstreetmap.org/search');
        $config->save('community_store_customer_map.geocoder.max_per_refresh', max(0, (int) ($data['max_per_refresh'] ?? 25)));
        $config->save('community_store_customer_map.geocoder.web_max_per_refresh', max(0, (int) ($data['web_max_per_refresh'] ?? 0)));
        $config->save('community_store_customer_map.geocoder.rate_limit_seconds', max(1, (int) ($data['rate_limit_seconds'] ?? 1)));
    }

    public function getSettings(): array
    {
        $config = $this->app->make('config');

        return [
            'provider' => (string) $config->get('community_store_customer_map.geocoder.provider', 'nominatim'),
            'nominatim_email' => (string) $config->get('community_store_customer_map.geocoder.nominatim_email', ''),
            'nominatim_endpoint' => (string) $config->get('community_store_customer_map.geocoder.nominatim_endpoint', 'https://nominatim.openstreetmap.org/search'),
            'max_per_refresh' => (int) $config->get('community_store_customer_map.geocoder.max_per_refresh', 25),
            'web_max_per_refresh' => (int) $config->get('community_store_customer_map.geocoder.web_max_per_refresh', 0),
            'rate_limit_seconds' => (int) $config->get('community_store_customer_map.geocoder.rate_limit_seconds', 1),
            'scope' => (string) $config->get('community_store_customer_map.geocoder.scope', 'postal_country'),
        ];
    }

    public function formatMoney(float $amount, ?string $currency = null): string
    {
        try {
            return StorePrice::format($amount);
        } catch (\Throwable $e) {
            // Fall through to a safe plain-text fallback. The package requires Community Store,
            // but this keeps the dashboard usable if the StorePrice utility is unavailable
            // during early installation or a broken upgrade.
        }

        $formatted = number_format($amount, 2);
        $currency = trim((string) ($currency ?: ''));

        return $currency !== '' ? $formatted . ' ' . $currency : $formatted;
    }

    private function fetchMappableRows(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM CommunityStoreCustomerMapAggregates WHERE latitude IS NOT NULL AND longitude IS NOT NULL'
        );
    }

    private function buildAddressPoints(array $rows, string $metric, bool $includeUnpaid): array
    {
        $points = [];
        foreach ($rows as $row) {
            $value = $this->metricValue($row, $metric, $includeUnpaid);
            if ($value <= 0) {
                continue;
            }
            $customerIDs = $this->decodeIntList($row['customerIDsJson'] ?? '[]');
            $orderIDs = $this->decodeIntList($row['orderIDsJson'] ?? '[]');
            $points[] = [
                'type' => 'address',
                'hash' => (string) $row['addressHash'],
                'lat' => (float) $row['latitude'],
                'lng' => (float) $row['longitude'],
                'address' => (string) $row['addressDisplay'],
                'label' => (string) $row['addressDisplay'],
                'postalCode' => (string) ($row['postalCode'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'addressCount' => 1,
                'orderCount' => (int) $row['orderCount'],
                'paidOrderCount' => (int) $row['paidOrderCount'],
                'unpaidOrderCount' => (int) $row['unpaidOrderCount'],
                'totalValue' => (float) $row['totalValue'],
                'paidTotalValue' => (float) $row['paidTotalValue'],
                'totalValueFormatted' => $this->formatMoney((float) $row['totalValue']),
                'paidTotalValueFormatted' => $this->formatMoney((float) $row['paidTotalValue']),
                'metricValueFormatted' => $metric === 'value' ? $this->formatMoney($value) : number_format($value),
                'customerCount' => (int) $row['customerCount'],
                'customerIDs' => $customerIDs,
                'orderIDs' => $orderIDs,
                'lastOrderDate' => $row['lastOrderDate'] ? (string) $row['lastOrderDate'] : null,
                'metricValue' => $value,
            ];
        }

        return $points;
    }

    private function buildPostalRegions(array $rows, string $metric, bool $includeUnpaid): array
    {
        $regions = [];
        foreach ($rows as $row) {
            $value = $this->metricValue($row, $metric, $includeUnpaid);
            if ($value <= 0) {
                continue;
            }

            $postalCode = $this->normalizePostalCode((string) ($row['postalCode'] ?? ''));
            $city = trim((string) ($row['city'] ?? ''));
            $country = trim((string) ($row['country'] ?? ''));
            $key = $postalCode !== '' ? mb_strtolower($country . '|' . $postalCode) : mb_strtolower($country . '|' . $city . '|no-postal');
            if ($key === '|no-postal') {
                $key = 'unknown|' . (string) $row['addressHash'];
            }

            if (!isset($regions[$key])) {
                $regions[$key] = [
                    'type' => 'postal',
                    'hash' => hash('sha256', $key),
                    'postalCode' => $postalCode,
                    'city' => $city,
                    'country' => $country,
                    'label' => $this->formatRegionLabel($postalCode, $city, $country),
                    'address' => $this->formatRegionLabel($postalCode, $city, $country),
                    'latWeighted' => 0.0,
                    'lngWeighted' => 0.0,
                    'weight' => 0.0,
                    'addressCount' => 0,
                    'orderCount' => 0,
                    'paidOrderCount' => 0,
                    'unpaidOrderCount' => 0,
                    'totalValue' => 0.0,
                    'paidTotalValue' => 0.0,
                    'customerIDs' => [],
                    'orderIDs' => [],
                    'lastOrderDate' => null,
                    'metricValue' => 0.0,
                ];
            }

            $weight = max(1.0, $this->metricValue($row, 'orders', true));
            $regions[$key]['latWeighted'] += ((float) $row['latitude']) * $weight;
            $regions[$key]['lngWeighted'] += ((float) $row['longitude']) * $weight;
            $regions[$key]['weight'] += $weight;
            ++$regions[$key]['addressCount'];
            $regions[$key]['orderCount'] += (int) $row['orderCount'];
            $regions[$key]['paidOrderCount'] += (int) $row['paidOrderCount'];
            $regions[$key]['unpaidOrderCount'] += (int) $row['unpaidOrderCount'];
            $regions[$key]['totalValue'] += (float) $row['totalValue'];
            $regions[$key]['paidTotalValue'] += (float) $row['paidTotalValue'];
            $regions[$key]['metricValue'] += $value;

            foreach ($this->decodeIntList($row['customerIDsJson'] ?? '[]') as $id) {
                $regions[$key]['customerIDs'][$id] = $id;
            }
            foreach ($this->decodeIntList($row['orderIDsJson'] ?? '[]') as $id) {
                $regions[$key]['orderIDs'][$id] = $id;
            }
            if (!empty($row['lastOrderDate']) && (!$regions[$key]['lastOrderDate'] || (string) $row['lastOrderDate'] > $regions[$key]['lastOrderDate'])) {
                $regions[$key]['lastOrderDate'] = (string) $row['lastOrderDate'];
            }
        }

        foreach ($regions as &$region) {
            $weight = max(1.0, (float) $region['weight']);
            $region['lat'] = $region['latWeighted'] / $weight;
            $region['lng'] = $region['lngWeighted'] / $weight;
            $region['customerIDs'] = array_values($region['customerIDs']);
            $region['orderIDs'] = array_values($region['orderIDs']);
            $region['customerCount'] = count($region['customerIDs']);
            $region['totalValueFormatted'] = $this->formatMoney((float) $region['totalValue']);
            $region['paidTotalValueFormatted'] = $this->formatMoney((float) $region['paidTotalValue']);
            $region['metricValueFormatted'] = $metric === 'value' ? $this->formatMoney((float) $region['metricValue']) : number_format((float) $region['metricValue']);
            unset($region['latWeighted'], $region['lngWeighted'], $region['weight']);
        }
        unset($region);

        return array_values($regions);
    }

    private function opportunityForRegion(array $region, float $globalAverageOrderValue = 0.0, float $maxPaidValue = 0.0): array
    {
        $customerCount = max(0, (int) ($region['customerCount'] ?? 0));
        $paidOrders = max(0, (int) ($region['paidOrderCount'] ?? 0));
        $paidValue = max(0.0, (float) ($region['paidTotalValue'] ?? 0));
        $addressCount = max(1, (int) ($region['addressCount'] ?? 1));
        $avgOrdersPerCustomer = $customerCount > 0 ? $paidOrders / $customerCount : 0.0;
        $avgOrderValue = $paidOrders > 0 ? $paidValue / $paidOrders : 0.0;

        if ($customerCount >= 3 && $paidOrders >= 3 && $avgOrdersPerCustomer <= 1.35) {
            return [
                'type' => 'retention',
                'label' => t('Retention opportunity'),
                'description' => t('Many customers in this postal region ordered only once or rarely. A follow-up campaign may create repeat purchases.'),
                'score' => round(($customerCount * 12) + ($paidValue / 100), 2),
            ];
        }

        if ($maxPaidValue > 0 && $paidValue >= ($maxPaidValue * 0.7) && $customerCount >= 1) {
            return [
                'type' => 'hotspot',
                'label' => t('High-value hotspot'),
                'description' => t('This postal region is close to the strongest revenue region and should be protected or expanded.'),
                'score' => round(90 + ($paidValue / max(1, $maxPaidValue)) * 100, 2),
            ];
        }

        if ($globalAverageOrderValue > 0 && $avgOrderValue >= ($globalAverageOrderValue * 1.35) && $paidOrders >= 2) {
            return [
                'type' => 'premium',
                'label' => t('Premium basket region'),
                'description' => t('Average order value is clearly above the map average. Consider premium offers or bundles.'),
                'score' => round(50 + ($avgOrderValue / max(1, $globalAverageOrderValue)) * 20 + $addressCount, 2),
            ];
        }

        if ($this->isRecent((string) ($region['lastOrderDate'] ?? ''), 120) && $paidOrders >= 2) {
            return [
                'type' => 'growth',
                'label' => t('Active growth region'),
                'description' => t('Recent orders suggest current demand. This region may be worth testing with local content or ads.'),
                'score' => round(40 + ($paidOrders * 4) + ($paidValue / 200), 2),
            ];
        }

        return [
            'type' => 'monitor',
            'label' => t('Monitor'),
            'description' => t('Keep watching this postal region as more order data accumulates.'),
            'score' => 0,
        ];
    }

    private function attemptGeocode(GeocoderInterface $geocoder, CustomerAddress $address): bool
    {
        try {
            $result = $geocoder->geocode($address);
            if ($result instanceof GeocodeResult) {
                $this->saveGeocodeSuccess($address, $result);
                return true;
            }
            $this->saveGeocodeFailure($address, t('No result found.'));
        } catch (\Throwable $e) {
            $this->saveGeocodeFailure($address, $e->getMessage());
        }

        return false;
    }

    private function shouldAttemptGeocode(?array $geocode, bool $retryFailed): bool
    {
        if (!$geocode) {
            return true;
        }
        if (($geocode['status'] ?? '') === 'pending') {
            return true;
        }
        if (($geocode['status'] ?? '') === 'failed') {
            return $retryFailed;
        }

        return false;
    }

    private function ensurePendingGeocode(CustomerAddress $address): bool
    {
        if ($this->getGeocodeRow($address->getAddressHash())) {
            return false;
        }
        $now = $this->now();
        $this->db->executeStatement(
            'INSERT INTO CommunityStoreCustomerMapGeocodes
             (addressHash, normalizedAddress, addressDisplay, status, failureCount, nextRetryAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, 0, NULL, ?, ?)',
            [$address->getAddressHash(), $address->getNormalizedAddress(), $address->getDisplayAddress(), 'pending', $now, $now]
        );

        return true;
    }

    private function saveGeocodeSuccess(CustomerAddress $address, GeocodeResult $result): void
    {
        $now = $this->now();
        $this->db->executeStatement(
            'INSERT INTO CommunityStoreCustomerMapGeocodes
             (addressHash, normalizedAddress, addressDisplay, latitude, longitude, provider, confidence, status, failureCount, nextRetryAt, resultRaw, lastError, lastGeocoded, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, NULL, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                normalizedAddress = VALUES(normalizedAddress),
                addressDisplay = VALUES(addressDisplay),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                provider = VALUES(provider),
                confidence = VALUES(confidence),
                status = VALUES(status),
                failureCount = 0,
                nextRetryAt = NULL,
                resultRaw = VALUES(resultRaw),
                lastError = NULL,
                lastGeocoded = VALUES(lastGeocoded),
                updatedAt = VALUES(updatedAt)',
            [
                $address->getAddressHash(),
                $address->getNormalizedAddress(),
                $address->getDisplayAddress(),
                $result->getLatitude(),
                $result->getLongitude(),
                $result->getProvider(),
                $result->getConfidence(),
                'ok',
                json_encode($result->getRaw(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $now,
                $now,
                $now,
            ]
        );
    }

    private function saveGeocodeFailure(CustomerAddress $address, string $message): void
    {
        $now = $this->now();
        $nextRetryAt = $this->calculateNextRetryAt($address->getAddressHash());
        $this->db->executeStatement(
            'INSERT INTO CommunityStoreCustomerMapGeocodes
             (addressHash, normalizedAddress, addressDisplay, status, failureCount, nextRetryAt, lastError, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                normalizedAddress = VALUES(normalizedAddress),
                addressDisplay = VALUES(addressDisplay),
                status = VALUES(status),
                failureCount = failureCount + 1,
                nextRetryAt = VALUES(nextRetryAt),
                lastError = VALUES(lastError),
                updatedAt = VALUES(updatedAt)',
            [$address->getAddressHash(), $address->getNormalizedAddress(), $address->getDisplayAddress(), 'failed', $nextRetryAt, $message, $now, $now]
        );
    }

    private function saveAggregate(CustomerAddress $address, array $geocode): void
    {
        $now = $this->now();
        $lastOrder = $address->getLastOrderDate();
        $this->db->executeStatement(
            'INSERT INTO CommunityStoreCustomerMapAggregates
             (addressHash, addressDisplay, postalCode, city, country, latitude, longitude, orderCount, paidOrderCount, unpaidOrderCount, totalValue, paidTotalValue, customerCount, customerIDsJson, orderIDsJson, lastOrderDate, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                addressDisplay = VALUES(addressDisplay),
                postalCode = VALUES(postalCode),
                city = VALUES(city),
                country = VALUES(country),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                orderCount = VALUES(orderCount),
                paidOrderCount = VALUES(paidOrderCount),
                unpaidOrderCount = VALUES(unpaidOrderCount),
                totalValue = VALUES(totalValue),
                paidTotalValue = VALUES(paidTotalValue),
                customerCount = VALUES(customerCount),
                customerIDsJson = VALUES(customerIDsJson),
                orderIDsJson = VALUES(orderIDsJson),
                lastOrderDate = VALUES(lastOrderDate),
                updatedAt = VALUES(updatedAt)',
            [
                $address->getAddressHash(),
                $address->getDisplayAddress(),
                $this->normalizePostalCode($address->getPart('postal_code')),
                $address->getPart('city'),
                $address->getPart('country'),
                $this->hasCoordinates($geocode) ? (float) $geocode['latitude'] : null,
                $this->hasCoordinates($geocode) ? (float) $geocode['longitude'] : null,
                $address->getOrderCount(),
                $address->getPaidOrderCount(),
                $address->getUnpaidOrderCount(),
                $address->getTotalValue(),
                $address->getPaidTotalValue(),
                $address->getCustomerCount(),
                json_encode($address->getCustomerIDs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($address->getOrderIDs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $lastOrder ? $lastOrder->format('Y-m-d H:i:s') : null,
                $now,
            ]
        );
    }

    private function getGeocodeRow(string $addressHash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM CommunityStoreCustomerMapGeocodes WHERE addressHash = ?',
            [$addressHash]
        );

        return is_array($row) ? $row : null;
    }

    private function hasCoordinates(?array $geocode): bool
    {
        return is_array($geocode) && $geocode['latitude'] !== null && $geocode['longitude'] !== null;
    }

    private function getMappableAddressCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM CommunityStoreCustomerMapAggregates WHERE latitude IS NOT NULL AND longitude IS NOT NULL');
    }

    private function metricValue(array $row, string $metric, bool $includeUnpaid): float
    {
        if ($metric === 'value') {
            return $includeUnpaid ? (float) $row['totalValue'] : (float) $row['paidTotalValue'];
        }

        return $includeUnpaid ? (float) $row['orderCount'] : (float) $row['paidOrderCount'];
    }

    private function maxMetricValue(array $rows): float
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $value = (float) ($row['metricValue'] ?? 0);
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    private function maxFieldValue(array $rows, string $field): float
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $value = (float) ($row[$field] ?? 0);
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    private function globalAverageOrderValue(array $regions): float
    {
        $paidValue = 0.0;
        $paidOrders = 0;
        foreach ($regions as $region) {
            $paidValue += (float) ($region['paidTotalValue'] ?? 0);
            $paidOrders += (int) ($region['paidOrderCount'] ?? 0);
        }

        return $paidOrders > 0 ? $paidValue / $paidOrders : 0.0;
    }

    private function decodeIntList($value): array
    {
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $decoded), static function (int $id): bool {
            return $id > 0;
        }));
    }

    private function normalizePostalCode(string $postalCode): string
    {
        $postalCode = preg_replace('/\s+/u', ' ', trim($postalCode));
        return $postalCode ? mb_strtoupper($postalCode) : '';
    }

    private function formatRegionLabel(string $postalCode, string $city, string $country): string
    {
        $label = $postalCode !== '' ? $postalCode : t('No postal code');
        if ($country !== '') {
            $label .= ', ' . $country;
        }

        return $label;
    }

    private function isRecent(string $date, int $days): bool
    {
        if ($date === '') {
            return false;
        }
        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return false;
        }

        return $dt >= (new \DateTimeImmutable())->modify('-' . $days . ' days');
    }

    private function colorForLevel(int $level): string
    {
        $level = max(1, min(100, $level));

        // Blue → teal → positive green. Avoid red/orange/yellow so hotspots don't look like warnings.
        $hue = (int) round(215 - (($level - 1) / 99) * 73);
        $lightness = (int) round(45 - (($level - 1) / 99) * 7);

        return sprintf('hsl(%d, 72%%, %d%%)', $hue, $lightness);
    }

    private function calculateNextRetryAt(string $addressHash): string
    {
        $failureCount = (int) $this->db->fetchOne(
            'SELECT failureCount FROM CommunityStoreCustomerMapGeocodes WHERE addressHash = ?',
            [$addressHash]
        );
        $hours = min(168, max(1, 2 ** min(6, $failureCount)));

        return (new \DateTimeImmutable())->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
    }

    private function respectRateLimit(): void
    {
        $config = $this->app->make('config');
        $seconds = max(1, (int) $config->get('community_store_customer_map.geocoder.rate_limit_seconds', 1));
        sleep($seconds);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
