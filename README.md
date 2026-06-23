# Community Store Customer Map

Community Store Customer Map is a concreteCMS 9.4+ dashboard package for visualizing Community Store customer hotspots on a Leaflet map.

The package reads Community Store orders, extracts the billing postal code and country, geocodes only that postal-code/country pair, caches the coordinates locally, and displays postal-code regions as a heatmap, marker clusters, rankings and marketing opportunities.

## Privacy-friendly geocoding

Since version 0.1.8 the package does not send full billing addresses to the geocoder. Geocoding requests contain only:

```text
postalcode + country
```

Street, house number, customer name, phone, email and full free-text address are not sent. This is less precise than full-address geocoding, but it is usually precise enough for customer hotspot analysis and better aligned with privacy-friendly marketing analytics.

When upgrading from an older version, previously cached full-address geocodes and aggregates are cleared once. Run the refresh task again to rebuild the cache with postal-code/country geocodes.

## Features

- concreteCMS dashboard page under `Dashboard > Store > Customer Map`
- Community Store order analysis
- postal-code/country geocoding cache
- Leaflet map with local Leaflet and MarkerCluster assets
- heatmap view
- marker/cluster view
- heatmap + marker view
- order count or order value metric
- top postal regions ranking
- opportunity signals for marketing actions
- optional inclusion of unpaid/refunded orders
- web-safe dashboard refresh
- CLI task for larger initial imports

## Dashboard refresh vs CLI task

The dashboard refresh is designed for safe web requests. It can rebuild aggregates and optionally process only a very small number of new geocoding requests. For production use, keep this value at `0` so the dashboard never blocks PHP workers with external geocoding calls.

Recommended dashboard setting:

```text
Max new geocodes per dashboard refresh: 0
```

The CLI task is the correct method for larger initial imports and overnight processing. It runs outside the browser request cycle and avoids Nginx gateway timeouts.

Run it from the concreteCMS project root:

```bash
php concrete/bin/concrete task:refresh-customer-map
```

Recommended public Nominatim settings:

```text
Max new geocodes per CLI task run: 50-100
Rate limit seconds: 2
Max new geocodes per dashboard refresh: 0
```

Example cron, every 15 minutes at night:

```bash
*/15 0-6 * * * cd /path/to/concrete && /usr/bin/php concrete/bin/concrete task:refresh-customer-map >> application/files/logs/customer_map_refresh.log 2>&1
```

## Nominatim notes

Even with postal-code-only queries, respect the geocoder provider terms. Use low limits, rate limiting and caching. For large stores or strict compliance requirements, consider a private Nominatim instance or a commercial provider with an appropriate data processing agreement.
