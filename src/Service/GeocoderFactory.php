<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service;

use Concrete\Core\Application\Application;
use Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder\GeocoderInterface;
use Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder\NominatimGeocoder;
use Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder\NullGeocoder;

defined('C5_EXECUTE') or die('Access Denied.');

class GeocoderFactory
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function create(): GeocoderInterface
    {
        $config = $this->app->make('config');
        $provider = (string) $config->get('community_store_customer_map.geocoder.provider', 'nominatim');

        if ($provider === 'none') {
            return new NullGeocoder();
        }

        $email = (string) $config->get('community_store_customer_map.geocoder.nominatim_email', '');
        $endpoint = (string) $config->get('community_store_customer_map.geocoder.nominatim_endpoint', 'https://nominatim.openstreetmap.org/search');
        $timeout = (int) $config->get('community_store_customer_map.geocoder.timeout_seconds', 15);
        $siteName = (string) $config->get('concrete.site', 'ConcreteCMS');
        $userAgent = trim($siteName . ' Community Store Customer Map');

        return new NominatimGeocoder($endpoint, $email ?: null, $timeout, $userAgent);
    }
}
