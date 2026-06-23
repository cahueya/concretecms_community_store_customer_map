<?php defined('C5_EXECUTE') or die('Access Denied.');
/** @var Concrete\Core\View\View $view */
/** @var Concrete\Core\Validation\CSRF\Token $token */
/** @var array $stats */
/** @var array $settings */
/** @var string $defaultMetric */
/** @var string $defaultMapLevel */
/** @var string $defaultDisplayMode */
/** @var bool $defaultIncludeUnpaid */

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$token = $app->make(\Concrete\Core\Validation\CSRF\Token::class);
$form = $app->make('helper/form');
$stats = $stats ?? [];
$settings = $settings ?? [];
$defaultMetric = in_array($defaultMetric ?? 'orders', ['orders', 'value'], true) ? $defaultMetric : 'orders';
$defaultMapLevel = 'postal';
$defaultDisplayMode = in_array($defaultDisplayMode ?? 'heatmap', ['markers', 'heatmap', 'both'], true) ? $defaultDisplayMode : 'heatmap';
$defaultIncludeUnpaid = !empty($defaultIncludeUnpaid);
?>

<div class="community-store-customer-map ccm-dashboard-content-inner text-start">
    <p class="text-muted mb-4">
        <?= t('Visualize Community Store customer hotspots with a privacy-friendly postal-code heatmap. Geocoding uses only postal code and country, not full billing addresses. Large initial imports should be processed through the CLI task.'); ?>
    </p>

    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Addresses'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= (int) ($stats['addresses'] ?? 0); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Mappable'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= (int) ($stats['mappable'] ?? 0); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Postal Regions'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= (int) ($stats['postalRegions'] ?? 0); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Orders'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= (int) ($stats['orders'] ?? 0); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Paid Orders'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= (int) ($stats['paidOrders'] ?? 0); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Value'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= h(number_format((float) ($stats['totalValue'] ?? 0), 2)); ?></div>
            </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card rounded-0 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Paid Value'); ?></div>
                <div class="customer-map-stat-value fw-semibold"><?= h(number_format((float) ($stats['paidTotalValue'] ?? 0), 2)); ?></div>
            </div></div>
        </div>
    </div>

    <div class="card rounded-0 border-0 shadow-sm mb-4 text-start">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <div class="customer-map-stat-label text-muted small fw-semibold"><?= t('Geocoding progress'); ?></div>
                    <div class="small text-muted">
                        <?= t('%s cached, %s pending, %s failed.', (int) ($stats['geocodeOk'] ?? 0), (int) ($stats['geocodePending'] ?? 0), (int) ($stats['geocodeFailed'] ?? 0)); ?>
                    </div>
                </div>
                <div class="small fw-semibold"><?= (int) ($stats['geocodeCompletePercent'] ?? 0); ?>%</div>
            </div>
            <div class="progress rounded-0 community-store-customer-map-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) ($stats['geocodeCompletePercent'] ?? 0); ?>">
                <div class="progress-bar" style="width: <?= (int) ($stats['geocodeCompletePercent'] ?? 0); ?>%"></div>
            </div>
            <?php if ((int) ($stats['geocodePendingOrFailed'] ?? 0) > 0) { ?>
                <div class="small text-muted mt-2">
                    <?= t('The task is chunk-safe: each run processes only the configured number of new geocodes, so a large initial backlog can be caught up overnight by repeated task runs.'); ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card rounded-0 border-0 shadow-sm mb-4 text-start">
        <div class="card-body p-4">
            <div class="row g-3 align-items-end mb-3">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold" for="customer-map-level"><?= t('Map level'); ?></label>
                    <select id="customer-map-level" class="form-select rounded-0">
                        <option value="postal" selected><?= t('Postal code regions'); ?></option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold" for="customer-map-display-mode"><?= t('Display'); ?></label>
                    <select id="customer-map-display-mode" class="form-select rounded-0">
                        <option value="heatmap"<?= $defaultDisplayMode === 'heatmap' ? ' selected' : ''; ?>><?= t('Heatmap'); ?></option>
                        <option value="markers"<?= $defaultDisplayMode === 'markers' ? ' selected' : ''; ?>><?= t('Markers / Clusters'); ?></option>
                        <option value="both"<?= $defaultDisplayMode === 'both' ? ' selected' : ''; ?>><?= t('Heatmap + Markers'); ?></option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold" for="customer-map-metric"><?= t('Metric'); ?></label>
                    <select id="customer-map-metric" class="form-select rounded-0">
                        <option value="orders"<?= $defaultMetric === 'orders' ? ' selected' : ''; ?>><?= t('Order count'); ?></option>
                        <option value="value"<?= $defaultMetric === 'value' ? ' selected' : ''; ?>><?= t('Order value'); ?></option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="customer-map-include-unpaid"<?= $defaultIncludeUnpaid ? ' checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="customer-map-include-unpaid"><?= t('Include unpaid/refunded orders in display'); ?></label>
                    </div>
                </div>
                <div class="col-lg-2 col-md-12 text-lg-end">
                    <div class="community-store-customer-map-legend d-inline-flex align-items-center gap-2">
                        <span class="small text-muted"><?= t('Low'); ?></span>
                        <span class="community-store-customer-map-gradient" aria-hidden="true"></span>
                        <span class="small text-muted"><?= t('High'); ?></span>
                    </div>
                </div>
            </div>

            <div
                id="community-store-customer-map-canvas"
                data-points-url="<?= h($view->action('points')); ?>"
                data-users-base-url="<?= h(\URL::to('/dashboard/users/search/view')); ?>"
                data-empty-label="<?= h(t('No geocoded customer addresses found yet. Refresh the geo index first.')); ?>"
            ></div>
            <div id="community-store-customer-map-status" class="small text-muted mt-2"></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card rounded-0 border-0 shadow-sm h-100 text-start">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3"><?= t('Top Postal Regions'); ?></h2>
                    <p class="text-muted small mb-3"><?= t('The ranking uses privacy-friendly postal-code aggregation.'); ?></p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle community-store-customer-map-table">
                            <thead>
                                <tr>
                                    <th><?= t('Region'); ?></th>
                                    <th class="text-end"><?= t('Orders'); ?></th>
                                    <th class="text-end"><?= t('Value'); ?></th>
                                    <th class="text-end"><?= t('Customers'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="community-store-customer-map-top-regions">
                                <tr><td colspan="4" class="text-muted small"><?= t('Load map data to see the ranking.'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card rounded-0 border-0 shadow-sm h-100 text-start">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3"><?= t('Opportunities'); ?></h2>
                    <p class="text-muted small mb-3"><?= t('Simple postal-region signals for marketing actions: retention, high-value, premium baskets or active growth.'); ?></p>
                    <div id="community-store-customer-map-opportunities" class="community-store-customer-map-opportunities">
                        <div class="text-muted small"><?= t('Load map data to see opportunities.'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card rounded-0 border-0 shadow-sm h-100 text-start">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3"><?= t('Refresh Geo Index'); ?></h2>
                    <p class="text-muted small"><?= t('This reads Community Store orders, rebuilds postal-code aggregates and only runs the small web-safe number of external geocoding requests configured below. Use the CLI task for larger overnight imports.'); ?></p>
                    <form method="post" action="<?= h($view->action('refresh')); ?>" class="row g-3">
                        <?php $token->output('community_store_customer_map_refresh'); ?>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="from_date"><?= t('From date'); ?></label>
                            <input type="date" class="form-control rounded-0" name="from_date" id="from_date">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="to_date"><?= t('To date'); ?></label>
                            <input type="date" class="form-control rounded-0" name="to_date" id="to_date">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_unpaid" value="1" id="refresh_include_unpaid">
                                <label class="form-check-label" for="refresh_include_unpaid"><?= t('Include unpaid/refunded orders while rebuilding aggregates'); ?></label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary rounded-0 fw-semibold" type="submit"><?= t('Refresh Safely'); ?></button>
                            <button class="btn btn-outline-secondary rounded-0 fw-semibold" type="submit" name="retry_failed" value="1"><?= t('Retry Failed + Refresh'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card rounded-0 border-0 shadow-sm h-100 text-start">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3"><?= t('Geocoding Settings'); ?></h2>
                    <form method="post" action="<?= h($view->action('save_settings')); ?>" class="row g-3">
                        <?php $token->output('community_store_customer_map_settings'); ?>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="provider"><?= t('Provider'); ?></label>
                            <select class="form-select rounded-0" name="provider" id="provider">
                                <option value="nominatim"<?= ($settings['provider'] ?? 'nominatim') === 'nominatim' ? ' selected' : ''; ?>><?= t('Nominatim / OpenStreetMap'); ?></option>
                                <option value="none"<?= ($settings['provider'] ?? '') === 'none' ? ' selected' : ''; ?>><?= t('None'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="max_per_refresh"><?= t('Max new geocodes per CLI task run'); ?></label>
                            <input type="number" min="0" step="1" class="form-control rounded-0" name="max_per_refresh" id="max_per_refresh" value="<?= h((string) ($settings['max_per_refresh'] ?? 25)); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="web_max_per_refresh"><?= t('Max new geocodes per dashboard refresh'); ?></label>
                            <input type="number" min="0" step="1" class="form-control rounded-0" name="web_max_per_refresh" id="web_max_per_refresh" value="<?= h((string) ($settings['web_max_per_refresh'] ?? 0)); ?>">
                            <div class="form-text"><?= t('Use 0 for queue/rebuild only. This prevents long web requests and PHP worker exhaustion.'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="rate_limit_seconds"><?= t('Rate limit seconds'); ?></label>
                            <input type="number" min="1" step="1" class="form-control rounded-0" name="rate_limit_seconds" id="rate_limit_seconds" value="<?= h((string) ($settings['rate_limit_seconds'] ?? 1)); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="nominatim_email"><?= t('Nominatim email'); ?></label>
                            <input type="email" class="form-control rounded-0" name="nominatim_email" id="nominatim_email" value="<?= h((string) ($settings['nominatim_email'] ?? '')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="nominatim_endpoint"><?= t('Nominatim endpoint'); ?></label>
                            <input type="url" class="form-control rounded-0" name="nominatim_endpoint" id="nominatim_endpoint" value="<?= h((string) ($settings['nominatim_endpoint'] ?? 'https://nominatim.openstreetmap.org/search')); ?>">
                        </div>
                        <div class="col-12">
                            <div class="alert alert-info rounded-0 small mb-0">
                                <?= t('Privacy mode is active: geocoding queries contain only postal code and country. Existing full-address geocodes from earlier versions are cleared once during the upgrade to this version.'); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-warning rounded-0 small mb-0">
                                <?= t('Respect your provider terms. This package sends only postal code and country to the geocoder. The public Nominatim service should still be used sparingly; cached results and low CLI limits are strongly recommended. Large imports should run through the CLI task, not through the dashboard.'); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-secondary rounded-0 fw-semibold" type="submit"><?= t('Save Settings'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
