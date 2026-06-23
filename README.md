# Community Store Customer Map

**Community Store Customer Map** is a concreteCMS dashboard package for visualizing customer hotspots from Community Store orders.

The package reads existing Community Store orders, extracts the billing address information, geocodes the addresses, stores the coordinates in a local cache, and displays the results on an interactive Leaflet map in the concreteCMS dashboard.

The goal is to help store owners understand where their customers are located, which regions generate the most orders or revenue, and where marketing or operational opportunities may exist.

## Requirements

* concreteCMS 9.4+
* Community Store package installed
* PHP CLI access recommended for larger stores
* Internet access for geocoding requests, unless all addresses are already cached
* OpenStreetMap/Nominatim or another configured geocoding provider

## Main Features

* Dashboard map for Community Store customer locations
* Address-based geocoding from Community Store billing addresses
* Local geocode cache to avoid repeated external requests
* Postal-code-level abstraction for privacy-friendly regional analysis
* Heatmap view for visual hotspot detection
* Marker and cluster view for detailed exploration
* Dynamic cluster coloring based on order count or order value
* Top postal regions ranking
* Opportunity analysis for promising regions
* Optional inclusion of unpaid/refunded orders
* Metric switch between order count and order value
* Local Leaflet and MarkerCluster assets
* Web-safe dashboard refresh
* CLI task for larger geocoding imports

## Dashboard Location

After installation, the dashboard page is available at:

```text
Dashboard > Store > Customer Map
```

The page displays the customer map, summary cards, geocoding progress, top regions, and opportunity indicators.

## Map Modes

The dashboard supports different display modes:

### Heatmap

Shows customer activity as a colored overlay on the map.

This is the recommended default view for marketing analysis because it avoids showing exact individual addresses and makes regional demand easier to see.

The heatmap uses a high-contrast color spectrum designed to stand out against OpenStreetMap tiles:

```text
transparent → blue → violet → magenta → gold
```

### Markers / Clusters

Shows individual address points or postal-code region points as Leaflet markers.

When many points are close together, they are grouped into clusters. Cluster color and size are based on the selected metric, such as number of orders or total order value.

### Heatmap + Markers

Shows both the heatmap and markers/clusters at the same time.

This is useful when you want to see the overall regional pattern and still inspect individual map points.

## Map Levels

The package supports address and postal-code-based views.

### Individual Addresses

Uses each unique billing address as a map point.

This is more precise but may expose more personal customer location data. Use this carefully and only for authorized admin users.

### Postal Code Regions

Aggregates customer data by postal code.

This is the recommended mode for most marketing use cases. It is less invasive, easier to understand, and more useful for identifying regional customer hotspots.

## Metrics

The map can be based on different metrics.

### Orders

Uses the number of orders per address or postal-code region.

This helps identify where customer activity is frequent.

### Value

Uses the total order value per address or postal-code region.

This helps identify high-value regions, even if they have fewer orders.

## Top Regions Ranking

Below the map, the plugin shows a ranking of the strongest postal-code regions.

The ranking helps answer questions such as:

* Which regions generate the most orders?
* Which regions generate the highest revenue?
* Which regions have the most customers?
* Which regions should be considered for targeted marketing?

## Opportunities

The Opportunities section highlights regions that may deserve attention.

Examples of opportunity signals include:

* Strong order activity
* High total order value
* High average basket value
* Regions with repeat customer potential
* Regions that may be suitable for targeted campaigns

These indicators are intended as practical marketing hints, not as fixed business rules. They can be refined later based on the store’s real strategy.

## Geocoding Cache

The package does not geocode the same address repeatedly.

Each normalized billing address is stored using a local cache. Once an address has been successfully geocoded, future refreshes reuse the cached coordinates.

This is important because public geocoding services such as Nominatim have strict usage limits and should not be used for repeated bulk lookups.

## Dashboard Refresh vs CLI Task

The package has two different ways to refresh map data.

They are intentionally different.

## Dashboard Refresh

The dashboard refresh is designed to be safe for normal web requests.

It updates the map data and can queue or process only a very small number of new geocoding requests, depending on the package settings.

By default, dashboard refresh should not be used for large geocoding imports.

Recommended dashboard setting:

```text
Max new geocodes per dashboard refresh: 0
```

This means the dashboard can refresh existing cached data without making external geocoding requests that could block PHP-FPM or cause a web timeout.

Use the dashboard refresh for:

* refreshing already cached map data
* updating rankings after orders changed
* checking geocoding progress
* small manual updates

Do not use the dashboard refresh for thousands of new addresses.

## CLI Task

The CLI task is the recommended method for initial imports and larger geocoding jobs.

It runs outside the web server request cycle, so it is not affected by Nginx gateway timeouts in the same way as a dashboard request.

Run it from the concreteCMS project root:

```bash
php concrete/bin/concrete task:refresh-customer-map
```

Depending on your installation, this may also work:

```bash
concrete/bin/concrete task:refresh-customer-map
```

Use the CLI task for:

* initial geocoding of many existing orders
* overnight imports
* scheduled incremental refreshes
* processing new addresses safely in chunks

Recommended CLI settings for public Nominatim usage:

```text
Max new geocodes per CLI task run: 50–100
Rate limit seconds: 2
Max new geocodes per dashboard refresh: 0
```

## Suggested Cron Setup

For larger stores, run the CLI task periodically at night.

Example cron job, running every 15 minutes between midnight and 06:59:

```bash
*/15 0-6 * * * cd /path/to/concrete && /usr/bin/php concrete/bin/concrete task:refresh-customer-map >> application/files/logs/customer_map_refresh.log 2>&1
```

If your web files are owned by a specific web user, run the command as that user.

Example:

```bash
*/15 0-6 * * * cd /path/to/concrete && sudo -u openlitespeed /usr/bin/php concrete/bin/concrete task:refresh-customer-map >> application/files/logs/customer_map_refresh.log 2>&1
```

Adjust the user and PHP path to match your server.

## Why CLI Is Better for Large Imports

Geocoding can be slow because external services usually require rate limiting.

For example, if the package waits two seconds between geocoding requests and processes 100 new addresses, that single run may take more than three minutes.

A web request may time out before that finishes.

A CLI task is better suited for this because:

* it does not run through Nginx as a browser request
* it is less likely to hit gateway timeouts
* it can be scheduled overnight
* it can process addresses in chunks
* it keeps the dashboard responsive

## Initial Import Strategy

For a store with many existing orders, do not try to geocode everything from the dashboard.

Recommended process:

1. Install the package.
2. Open the Customer Map dashboard page once.
3. Set dashboard geocoding limit to `0`.
4. Set CLI geocoding limit to `50–100`.
5. Set geocoding delay to around `2` seconds when using public Nominatim.
6. Run the CLI task manually once to test it.
7. Schedule the CLI task overnight.
8. Let the package gradually build the geocode cache.
9. Use the dashboard to monitor progress.

Once the initial backlog is processed, daily operation is usually light because only new addresses need geocoding.

## Nominatim Usage Notes

When using the public Nominatim service, avoid heavy bulk usage.

Important rules:

* geocode only unique addresses
* cache all successful results
* do not repeat the same query unnecessarily
* use a valid identifying User-Agent
* rate-limit requests
* avoid long-running bulk imports against the public service

For larger stores or frequent imports, consider using a private Nominatim instance or a commercial geocoding provider.

## Privacy Notes

Billing addresses are personal data.

For most marketing use cases, the postal-code region view and heatmap view are recommended because they avoid exposing exact customer address points.

Recommended privacy-friendly setup:

```text
Map level: Postal code regions
Display mode: Heatmap
Metric: Orders or Value
Dashboard geocoding limit: 0
```

Only authorized administrators should have access to this dashboard.

## Troubleshooting

### The dashboard request times out

This usually means too many geocoding requests were triggered from the browser.

Set:

```text
Max new geocodes per dashboard refresh: 0
```

Then run the refresh through CLI instead:

```bash
php concrete/bin/concrete task:refresh-customer-map
```

### Nginx shows a gateway timeout

This means the web server waited too long for PHP to respond.

Use the CLI task for geocoding and keep dashboard refresh web-safe.

### The whole site becomes slow or hangs

A long PHP web request may be blocking PHP workers.

Restart the PHP upstream, for example:

```bash
sudo systemctl restart php8.2-fpm
```

or:

```bash
sudo systemctl restart php8.3-fpm
```

Then reduce the dashboard geocoding limit to `0`.

### Some addresses are missing

Possible reasons:

* the address could not be geocoded
* the geocoding backlog is not finished
* the order has no usable billing address
* unpaid/refunded orders are excluded
* the address is waiting for retry after a failed lookup

Check the geocoding progress in the dashboard and continue processing through the CLI task.

## Recommended Production Settings

For a normal production site using public Nominatim:

```text
Map level: Postal code regions
Display mode: Heatmap
Metric: Orders or Value
Include unpaid orders: off, unless needed
Max new geocodes per dashboard refresh: 0
Max new geocodes per CLI task run: 50–100
Rate limit seconds: 2
```

For a private or commercial geocoding provider, the CLI limit and rate limit can be adjusted according to that provider’s policy.

## Summary

Community Store Customer Map helps store owners identify customer hotspots from existing Community Store orders.

The dashboard is intended for analysis and visualization.

The CLI task is intended for heavier geocoding work.

Use the dashboard for viewing and safe refreshes.
Use the CLI task for initial imports, scheduled updates, and larger geocoding backlogs.
