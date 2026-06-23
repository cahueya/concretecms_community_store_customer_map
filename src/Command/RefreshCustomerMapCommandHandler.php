<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Command;

use Concrete\Core\Support\Facade\Application;
use Concrete\Package\CommunityStoreCustomerMap\Service\CustomerMapService;

defined('C5_EXECUTE') or die('Access Denied.');

class RefreshCustomerMapCommandHandler
{
    public function __invoke(RefreshCustomerMapCommand $command): void
    {
        $app = Application::getFacadeApplication();
        $service = $app->make(CustomerMapService::class);
        $settings = $service->getSettings();
        $service->refresh(false, null, null, max(0, (int) ($settings['max_per_refresh'] ?? 25)), false);
    }
}
