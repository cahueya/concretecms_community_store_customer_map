<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Service\Geocoder;

use Concrete\Package\CommunityStoreCustomerMap\Value\CustomerAddress;

defined('C5_EXECUTE') or die('Access Denied.');

interface GeocoderInterface
{
    public function geocode(CustomerAddress $address): ?GeocodeResult;
}
