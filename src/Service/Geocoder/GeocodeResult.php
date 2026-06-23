<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder;

defined('C5_EXECUTE') or die('Access Denied.');

class GeocodeResult
{
    private float $latitude;
    private float $longitude;
    private string $provider;
    private string $confidence;
    private array $raw;

    public function __construct(float $latitude, float $longitude, string $provider, string $confidence = 'address', array $raw = [])
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->provider = $provider;
        $this->confidence = $confidence;
        $this->raw = $raw;
    }

    public function getLatitude(): float { return $this->latitude; }
    public function getLongitude(): float { return $this->longitude; }
    public function getProvider(): string { return $this->provider; }
    public function getConfidence(): string { return $this->confidence; }
    public function getRaw(): array { return $this->raw; }
}
