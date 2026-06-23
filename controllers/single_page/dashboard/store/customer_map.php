<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Controller\SinglePage\Dashboard\Store;

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Validation\CSRF\Token;
use Concrete\Package\CommunityStoreCustomerMap\Service\CustomerMapService;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

class CustomerMap extends DashboardPageController
{
    public function view(): void
    {
        $this->requireAsset('community-store-customer-map');
        $service = $this->app->make(CustomerMapService::class);
        $this->set('service', $service);
        $this->set('stats', $service->getStats());
        $this->set('settings', $service->getSettings());
        $this->set('defaultMetric', (string) $this->request->query->get('metric', 'orders'));
        $this->set('defaultMapLevel', 'postal');
        $this->set('defaultDisplayMode', (string) $this->request->query->get('display', 'heatmap'));
        $this->set('defaultIncludeUnpaid', (bool) $this->request->query->get('include_unpaid', false));
    }

    public function points(): JsonResponse
    {
        $service = $this->app->make(CustomerMapService::class);
        $includeUnpaid = $this->request->query->get('include_unpaid') === '1';
        $metric = $this->request->query->get('metric') === 'value' ? 'value' : 'orders';
        $level = 'postal';

        return new JsonResponse($service->getMapPoints($includeUnpaid, $metric, $level));
    }

    public function refresh()
    {
        $token = $this->app->make(Token::class);
        if (!$token->validate('community_store_customer_map_refresh')) {
            $this->error->add($token->getErrorMessage());
            return $this->view();
        }

        $service = $this->app->make(CustomerMapService::class);
        $settings = $service->getSettings();
        $includeUnpaid = (bool) $this->request->request->get('include_unpaid', false);
        $fromDate = trim((string) $this->request->request->get('from_date', '')) ?: null;
        $toDate = trim((string) $this->request->request->get('to_date', '')) ?: null;
        $retryFailed = (bool) $this->request->request->get('retry_failed', false);
        $configuredMax = max(0, (int) ($settings['max_per_refresh'] ?? 25));
        $webMax = max(0, (int) ($settings['web_max_per_refresh'] ?? 0));
        $max = min($configuredMax, $webMax);

        try {
            $result = $service->refresh($includeUnpaid, $fromDate, $toDate, $max, $retryFailed);
            $this->flash('success', t('Customer map refreshed safely from the dashboard. Postal regions: %s, geocoded this request: %s, failed this request: %s, pending: %s, failed total: %s, mappable: %s.', $result['addresses'], $result['geocoded'], $result['failed'], $result['pending'], $result['failedTotal'], $result['mappable']));
            if ($configuredMax > $max) {
                $this->flash('info', t('Dashboard refresh is capped at %s external geocoding request(s). Use the CLI task for larger initial imports; the CLI task still uses the configured task limit of %s.', $max, $configuredMax));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect('/dashboard/store/customer_map');
    }

    public function save_settings()
    {
        $token = $this->app->make(Token::class);
        if (!$token->validate('community_store_customer_map_settings')) {
            $this->flash('error', $token->getErrorMessage());
            return $this->buildRedirect('/dashboard/store/customer_map');
        }

        $service = $this->app->make(CustomerMapService::class);
        $service->saveSettings($this->request->request->all());
        $this->flash('success', t('Customer map settings saved.'));

        return $this->buildRedirect('/dashboard/store/customer_map');
    }
}
