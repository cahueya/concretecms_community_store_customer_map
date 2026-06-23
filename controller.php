<?php

namespace Concrete\Package\CommunityStoreCustomerMap;

use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Command\Task\Manager as TaskManager;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Package\CommunityStoreCustomerMap\Command\Task\Controller\RefreshCustomerMapController;

class Controller extends Package
{
    protected $pkgHandle = 'community_store_customer_map';
    protected $appVersionRequired = '9.4.0';
    protected $pkgVersion = '0.1.8';
    protected $packageDependencies = [
        'community_store' => '2.4.3',
    ];

    protected $pkgAutoloaderRegistries = [
        'src' => '\\Concrete\\Package\\CommunityStoreCustomerMap',
    ];

    public function getPackageName()
    {
        return t('Community Store Customer Map');
    }

    public function getPackageDescription()
    {
        return t('Shows privacy-friendly Community Store customer hotspots, postal-code opportunities and heatmaps on a Leaflet dashboard map.');
    }

    public function on_start()
    {
        $this->registerAssets();
        $this->registerTasks();
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installDatabase();
        $this->installDashboardPage($pkg);
        $this->installContentFile('tasks.xml');
        $this->ensurePrivacyGeocodingScope();

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $pkg = $this->getPackageEntity();
        $this->installDatabase();
        $this->installDashboardPage($pkg);
        $this->installContentFile('tasks.xml');
        $this->ensurePrivacyGeocodingScope();
    }

    public function registerAssets(): void
    {
        $al = AssetList::getInstance();

        $al->register(
            'css',
            'community-store-customer-map/leaflet',
            'css/vendor/leaflet/leaflet.css',
            ['version' => '1.9.4'],
            $this
        );
        $al->register(
            'javascript',
            'community-store-customer-map/leaflet',
            'js/vendor/leaflet/leaflet.js',
            ['version' => '1.9.4', 'position' => Asset::ASSET_POSITION_FOOTER],
            $this
        );
        $al->register(
            'css',
            'community-store-customer-map/markercluster',
            'css/vendor/leaflet-markercluster/MarkerCluster.css',
            ['version' => '1.5.3'],
            $this
        );
        $al->register(
            'css',
            'community-store-customer-map/markercluster-default',
            'css/vendor/leaflet-markercluster/MarkerCluster.Default.css',
            ['version' => '1.5.3'],
            $this
        );
        $al->register(
            'javascript',
            'community-store-customer-map/markercluster',
            'js/vendor/leaflet-markercluster/leaflet.markercluster.js',
            ['version' => '1.5.3', 'position' => Asset::ASSET_POSITION_FOOTER],
            $this
        );
        $al->register(
            'css',
            'community-store-customer-map/dashboard',
            'css/customer_map.css',
            ['version' => $this->pkgVersion],
            $this
        );
        $al->register(
            'javascript',
            'community-store-customer-map/dashboard',
            'js/customer_map.js',
            ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER],
            $this
        );

        $al->registerGroup('community-store-customer-map', [
            ['css', 'community-store-customer-map/leaflet'],
            ['css', 'community-store-customer-map/markercluster'],
            ['css', 'community-store-customer-map/markercluster-default'],
            ['css', 'community-store-customer-map/dashboard'],
            ['javascript', 'community-store-customer-map/leaflet'],
            ['javascript', 'community-store-customer-map/markercluster'],
            ['javascript', 'community-store-customer-map/dashboard'],
        ]);
    }

    public function registerTasks(): void
    {
        $manager = $this->app->make(TaskManager::class);
        $manager->extend('refresh_customer_map', function () {
            return new RefreshCustomerMapController();
        });
    }

    public function installDashboardPage($pkg): void
    {
        $page = Page::getByPath('/dashboard/store/customer_map');
        if (!$page || $page->isError()) {
            $page = SinglePage::add('/dashboard/store/customer_map', $pkg);
        }

        if ($page && !$page->isError()) {
            $page->update([
                'cName' => t('Customer Map'),
                'cDescription' => t('View privacy-friendly customer hotspots based on Community Store billing postal codes.'),
            ]);
        }
    }

    public function installDatabase(): void
    {
        $db = $this->app->make('database')->connection();

        $db->executeStatement(
            "CREATE TABLE IF NOT EXISTS CommunityStoreCustomerMapGeocodes (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                addressHash CHAR(64) NOT NULL,
                normalizedAddress LONGTEXT NOT NULL,
                addressDisplay LONGTEXT DEFAULT NULL,
                latitude DECIMAL(10,7) DEFAULT NULL,
                longitude DECIMAL(10,7) DEFAULT NULL,
                provider VARCHAR(64) DEFAULT NULL,
                confidence VARCHAR(64) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                failureCount INT UNSIGNED NOT NULL DEFAULT 0,
                nextRetryAt DATETIME DEFAULT NULL,
                resultRaw LONGTEXT DEFAULT NULL,
                lastError LONGTEXT DEFAULT NULL,
                lastGeocoded DATETIME DEFAULT NULL,
                createdAt DATETIME NOT NULL,
                updatedAt DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY UNIQ_CSCM_ADDRESS_HASH (addressHash),
                KEY IDX_CSCM_GEO_STATUS (status),
                KEY IDX_CSCM_GEO_RETRY (status, nextRetryAt)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $db->executeStatement(
            "CREATE TABLE IF NOT EXISTS CommunityStoreCustomerMapAggregates (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                addressHash CHAR(64) NOT NULL,
                addressDisplay LONGTEXT DEFAULT NULL,
                postalCode VARCHAR(64) DEFAULT NULL,
                city VARCHAR(255) DEFAULT NULL,
                country VARCHAR(255) DEFAULT NULL,
                latitude DECIMAL(10,7) DEFAULT NULL,
                longitude DECIMAL(10,7) DEFAULT NULL,
                orderCount INT UNSIGNED NOT NULL DEFAULT 0,
                paidOrderCount INT UNSIGNED NOT NULL DEFAULT 0,
                unpaidOrderCount INT UNSIGNED NOT NULL DEFAULT 0,
                totalValue DECIMAL(14,2) NOT NULL DEFAULT 0,
                paidTotalValue DECIMAL(14,2) NOT NULL DEFAULT 0,
                customerCount INT UNSIGNED NOT NULL DEFAULT 0,
                customerIDsJson LONGTEXT DEFAULT NULL,
                orderIDsJson LONGTEXT DEFAULT NULL,
                lastOrderDate DATETIME DEFAULT NULL,
                updatedAt DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY UNIQ_CSCM_AGG_ADDRESS_HASH (addressHash),
                KEY IDX_CSCM_AGG_COORDS (latitude, longitude),
                KEY IDX_CSCM_AGG_POSTAL (country, postalCode)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $this->ensureColumn('CommunityStoreCustomerMapGeocodes', 'failureCount', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER status');
        $this->ensureColumn('CommunityStoreCustomerMapGeocodes', 'nextRetryAt', 'DATETIME DEFAULT NULL AFTER failureCount');
        $this->ensureIndex('CommunityStoreCustomerMapGeocodes', 'IDX_CSCM_GEO_RETRY', 'CREATE INDEX IDX_CSCM_GEO_RETRY ON CommunityStoreCustomerMapGeocodes (status, nextRetryAt)');
        $this->ensureColumn('CommunityStoreCustomerMapAggregates', 'postalCode', 'VARCHAR(64) DEFAULT NULL AFTER addressDisplay');
        $this->ensureColumn('CommunityStoreCustomerMapAggregates', 'city', 'VARCHAR(255) DEFAULT NULL AFTER postalCode');
        $this->ensureColumn('CommunityStoreCustomerMapAggregates', 'country', 'VARCHAR(255) DEFAULT NULL AFTER city');
        $this->ensureIndex('CommunityStoreCustomerMapAggregates', 'IDX_CSCM_AGG_POSTAL', 'CREATE INDEX IDX_CSCM_AGG_POSTAL ON CommunityStoreCustomerMapAggregates (country, postalCode)');
    }


    public function ensurePrivacyGeocodingScope(): void
    {
        $config = $this->app->make('config');
        $scope = (string) $config->get('community_store_customer_map.geocoder.scope', '');
        if ($scope !== 'postal_country') {
            $db = $this->app->make('database')->connection();
            if ($this->tableExists('CommunityStoreCustomerMapGeocodes')) {
                $db->executeStatement('DELETE FROM CommunityStoreCustomerMapGeocodes');
            }
            if ($this->tableExists('CommunityStoreCustomerMapAggregates')) {
                $db->executeStatement('DELETE FROM CommunityStoreCustomerMapAggregates');
            }
            $config->save('community_store_customer_map.geocoder.scope', 'postal_country');
        }
    }

    public function tableExists(string $table): bool
    {
        $db = $this->app->make('database')->connection();
        return (bool) $db->fetchOne('SHOW TABLES LIKE ?', [$table]);
    }

    public function ensureColumn(string $table, string $column, string $definition): void
    {
        $db = $this->app->make('database')->connection();
        $exists = $db->fetchOne('SHOW COLUMNS FROM `' . $table . '` LIKE ?', [$column]);
        if (!$exists) {
            $db->executeStatement('ALTER TABLE `' . $table . '` ADD `' . $column . '` ' . $definition);
        }
    }

    public function ensureIndex(string $table, string $index, string $statement): void
    {
        $db = $this->app->make('database')->connection();
        $exists = $db->fetchOne('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);
        if (!$exists) {
            $db->executeStatement($statement);
        }
    }
}
