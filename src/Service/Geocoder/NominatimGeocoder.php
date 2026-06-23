<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder;

use Concrete\Package\CommunityStoreCustomerMap\Value\CustomerAddress;

defined('C5_EXECUTE') or die('Access Denied.');

class NominatimGeocoder implements GeocoderInterface
{
    private string $endpoint;
    private string $userAgent;
    private int $timeoutSeconds;
    private ?string $email;

    public function __construct(string $endpoint = 'https://nominatim.openstreetmap.org/search', ?string $email = null, int $timeoutSeconds = 15, string $userAgent = '')
    {
        $this->endpoint = $endpoint;
        $this->email = $email ? trim($email) : null;
        $this->timeoutSeconds = max(3, $timeoutSeconds);
        $this->userAgent = $userAgent ?: 'ConcreteCMS Community Store Customer Map';
    }

    public function geocode(CustomerAddress $address): ?GeocodeResult
    {
        $params = $this->buildQueryParameters($address);
        if (!$params) {
            return null;
        }

        $url = $this->endpoint . (strpos($this->endpoint, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = $this->request($url);
        if ($response === '') {
            return null;
        }

        $json = json_decode($response, true);
        if (!is_array($json) || empty($json[0]['lat']) || empty($json[0]['lon'])) {
            return null;
        }

        $first = $json[0];
        $confidence = 'address';
        if (!empty($first['type'])) {
            $confidence = (string) $first['type'];
        } elseif (!empty($first['class'])) {
            $confidence = (string) $first['class'];
        }

        return new GeocodeResult((float) $first['lat'], (float) $first['lon'], 'nominatim', $confidence, $first);
    }

    /**
     * Prefer Nominatim's structured address search when Community Store has split address parts.
     * Do not combine structured parameters with q=. Nominatim treats that as invalid/undefined.
     *
     * @return array<string, string|int>
     */
    private function buildQueryParameters(CustomerAddress $address): array
    {
        $street = trim(implode(' ', array_filter([
            $address->getPart('address1'),
            $address->getPart('address2'),
        ])));
        $city = $address->getPart('city');
        $state = $address->getPart('state_province');
        $postalCode = $address->getPart('postal_code');
        $country = $address->getPart('country');

        $base = [
            'format' => 'jsonv2',
            'limit' => 1,
            'addressdetails' => 1,
            'layer' => 'address',
        ];
        if ($this->email) {
            $base['email'] = $this->email;
        }

        $structured = array_filter([
            'street' => $street,
            'city' => $city,
            'state' => $state,
            'postalcode' => $postalCode,
        ], static function ($value) {
            return trim((string) $value) !== '';
        });

        if ($country !== '') {
            $country = trim($country);
            if (preg_match('/^[A-Za-z]{2}$/', $country)) {
                $base['countrycodes'] = strtolower($country);
            } else {
                $structured['country'] = $country;
            }
        }

        if ($structured) {
            return $base + $structured;
        }

        $query = $address->getNormalizedAddress();
        if ($query === '') {
            return [];
        }

        return $base + ['q' => $query];
    }

    private function request(string $url): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if (!$curl) {
                return '';
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: ' . $this->userAgent,
                ],
            ]);
            $body = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            return $code >= 200 && $code < 300 && is_string($body) ? $body : '';
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\nUser-Agent: {$this->userAgent}\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : '';
    }
}
