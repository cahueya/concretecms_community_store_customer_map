(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState !== 'loading') {
            callback();
        } else {
            document.addEventListener('DOMContentLoaded', callback);
        }
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatNumber(value) {
        return new Intl.NumberFormat().format(Number(value || 0));
    }

    function formatMoney(value, formatted) {
        if (formatted) {
            return String(formatted);
        }
        return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
    }

    function colorForLevel(level) {
        level = Math.max(1, Math.min(100, Number(level || 1)));

        // Blue → teal → positive green. Keep marker colors calm and non-warning.
        var hue = Math.round(215 - ((level - 1) / 99) * 73);
        var lightness = Math.round(45 - ((level - 1) / 99) * 7);

        return 'hsl(' + hue + ', 72%, ' + lightness + '%)';
    }

    function clusterLabelValue(value, metric) {
        // Cluster sums are calculated client-side after Leaflet groups arbitrary points.
        // To avoid showing unformatted money without the Community Store currency, value-mode
        // clusters show the metric label while color/size carry the intensity.
        return metric === 'value' ? 'Value' : formatNumber(value);
    }

    function createMarkerIcon(point) {
        var size = 18 + Math.round((Number(point.level || 1) / 100) * 20);
        return L.divIcon({
            className: 'community-store-customer-map-marker' + (point.type === 'postal' ? ' is-postal' : ''),
            html: '<span style="background:' + escapeHtml(point.color) + ';width:' + size + 'px;height:' + size + 'px"></span>',
            iconSize: [size, size],
            iconAnchor: [Math.round(size / 2), Math.round(size / 2)],
            popupAnchor: [0, -Math.round(size / 2)]
        });
    }

    function buildPopup(point, usersBaseUrl) {
        var isPostal = point.type === 'postal';
        var html = '<div class="community-store-customer-map-popup">';
        html += '<strong>' + escapeHtml(point.label || point.address) + '</strong>';
        if (isPostal) {
            html += '<div class="small text-muted mt-1">Postal-code region</div>';
        }
        html += '<dl class="mb-2 mt-2">';
        html += '<dt>Orders</dt><dd>' + formatNumber(point.orderCount) + ' (' + formatNumber(point.paidOrderCount) + ' paid)</dd>';
        html += '<dt>Value</dt><dd>' + formatMoney(point.totalValue, point.totalValueFormatted) + ' (' + formatMoney(point.paidTotalValue, point.paidTotalValueFormatted) + ' paid)</dd>';
        html += '<dt>Customers</dt><dd>' + formatNumber(point.customerCount) + '</dd>';
        if (point.lastOrderDate) {
            html += '<dt>Last order</dt><dd>' + escapeHtml(point.lastOrderDate) + '</dd>';
        }
        if (point.opportunity && point.opportunity.label && point.opportunity.type !== 'monitor') {
            html += '<dt>Signal</dt><dd>' + escapeHtml(point.opportunity.label) + '</dd>';
        }
        html += '</dl>';

        if (Array.isArray(point.customerIDs) && point.customerIDs.length) {
            html += '<div class="small">';
            html += '<strong>Customer profiles:</strong> ';
            html += point.customerIDs.slice(0, 5).map(function (id) {
                return '<a href="' + escapeHtml(usersBaseUrl + '/' + id) + '">#' + escapeHtml(id) + '</a>';
            }).join(', ');
            if (point.customerIDs.length > 5) {
                html += ' +' + formatNumber(point.customerIDs.length - 5);
            }
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    function renderTopRegions(rows) {
        var tbody = document.getElementById('community-store-customer-map-top-regions');
        if (!tbody) {
            return;
        }
        if (!Array.isArray(rows) || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted small">No postal regions available yet.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (row) {
            return '<tr>' +
                '<td><strong class="d-block">' + escapeHtml(row.label || row.address) + '</strong><span class="text-muted small">Postal-code geocode group</span></td>' +
                '<td class="text-end">' + formatNumber(row.paidOrderCount || row.orderCount || 0) + '</td>' +
                '<td class="text-end">' + formatMoney(row.paidTotalValue || row.totalValue || 0, row.paidTotalValueFormatted || row.totalValueFormatted || '') + '</td>' +
                '<td class="text-end">' + formatNumber(row.customerCount || 0) + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderOpportunities(rows) {
        var el = document.getElementById('community-store-customer-map-opportunities');
        if (!el) {
            return;
        }
        if (!Array.isArray(rows) || !rows.length) {
            el.innerHTML = '<div class="text-muted small">No opportunity signals available yet.</div>';
            return;
        }
        el.innerHTML = rows.map(function (row) {
            var opportunity = row.opportunity || {};
            return '<div class="community-store-customer-map-opportunity border-bottom pb-3 mb-3">' +
                '<div class="d-flex justify-content-between gap-3">' +
                    '<div><strong class="d-block">' + escapeHtml(row.label || row.address) + '</strong>' +
                    '<span class="badge rounded-0 text-bg-light border">' + escapeHtml(opportunity.label || 'Opportunity') + '</span></div>' +
                    '<div class="text-end small"><div>' + formatNumber(row.paidOrderCount || 0) + ' orders</div><div>' + formatMoney(row.paidTotalValue || 0, row.paidTotalValueFormatted || '') + '</div></div>' +
                '</div>' +
                '<div class="text-muted small mt-2">' + escapeHtml(opportunity.description || '') + '</div>' +
                '</div>';
        }).join('');
    }

    function createHeatGradient() {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        var gradient;
        canvas.width = 1;
        canvas.height = 256;
        gradient = ctx.createLinearGradient(0, 0, 0, 256);
        gradient.addColorStop(0.00, 'rgba(255,255,255,0)');
        gradient.addColorStop(0.14, 'rgb(30, 90, 210)');
        gradient.addColorStop(0.38, 'rgb(91, 70, 210)');
        gradient.addColorStop(0.66, 'rgb(188, 58, 182)');
        gradient.addColorStop(1.00, 'rgb(245, 183, 65)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 1, 256);
        return ctx.getImageData(0, 0, 1, 256).data;
    }

    function CustomerMapHeatLayer(options) {
        this.options = Object.assign({
            radius: 46,
            blur: 30,
            minOpacity: 0.20,
            maxOpacity: 0.88
        }, options || {});
        this._points = [];
        this._gradient = createHeatGradient();
    }

    CustomerMapHeatLayer.prototype = Object.create(L.Layer.prototype);
    CustomerMapHeatLayer.prototype.constructor = CustomerMapHeatLayer;

    CustomerMapHeatLayer.prototype.onAdd = function (map) {
        this._map = map;
        this._canvas = L.DomUtil.create('canvas', 'community-store-customer-map-heat-layer leaflet-zoom-animated');
        this._ctx = this._canvas.getContext('2d', { willReadFrequently: true });
        map.getPanes().overlayPane.appendChild(this._canvas);
        map.on('moveend zoomend resize', this._reset, this);
        this._reset();
    };

    CustomerMapHeatLayer.prototype.onRemove = function (map) {
        map.off('moveend zoomend resize', this._reset, this);
        if (this._canvas && this._canvas.parentNode) {
            this._canvas.parentNode.removeChild(this._canvas);
        }
        this._map = null;
        this._canvas = null;
        this._ctx = null;
    };

    CustomerMapHeatLayer.prototype.setPoints = function (points, maxValue) {
        maxValue = Math.max(1, Number(maxValue || 0));
        this._points = (Array.isArray(points) ? points : []).map(function (point) {
            return {
                lat: Number(point.lat),
                lng: Number(point.lng),
                intensity: Math.max(0, Math.min(1, Number(point.metricValue || 0) / maxValue))
            };
        }).filter(function (point) {
            return isFinite(point.lat) && isFinite(point.lng) && point.intensity > 0;
        });
        this._reset();
    };

    CustomerMapHeatLayer.prototype._reset = function () {
        if (!this._map || !this._canvas) {
            return;
        }
        var size = this._map.getSize();
        var topLeft = this._map.containerPointToLayerPoint([0, 0]);
        L.DomUtil.setPosition(this._canvas, topLeft);
        this._canvas.width = size.x;
        this._canvas.height = size.y;
        this._redraw();
    };

    CustomerMapHeatLayer.prototype._redraw = function () {
        if (!this._ctx || !this._map) {
            return;
        }
        var ctx = this._ctx;
        var radius = this.options.radius;
        var blur = this.options.blur;
        var circle = this._circleCanvas(radius, blur);
        var size = this._map.getSize();
        var maxAlpha = this.options.maxOpacity;
        var minAlpha = this.options.minOpacity;

        ctx.clearRect(0, 0, size.x, size.y);
        this._points.forEach(function (point) {
            var pixel = this._map.latLngToContainerPoint([point.lat, point.lng]);
            var alpha = Math.max(minAlpha, Math.min(maxAlpha, point.intensity * maxAlpha));
            ctx.globalAlpha = alpha;
            ctx.drawImage(circle, pixel.x - radius - blur, pixel.y - radius - blur);
        }, this);
        ctx.globalAlpha = 1;
        this._colorize();
    };

    CustomerMapHeatLayer.prototype._circleCanvas = function (radius, blur) {
        if (this._cachedCircle && this._cachedCircleRadius === radius && this._cachedCircleBlur === blur) {
            return this._cachedCircle;
        }
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        var total = radius + blur;
        var gradient;
        canvas.width = canvas.height = total * 2;
        gradient = ctx.createRadialGradient(total, total, radius * 0.15, total, total, total);
        gradient.addColorStop(0, 'rgba(0,0,0,1)');
        gradient.addColorStop(0.55, 'rgba(0,0,0,.72)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        this._cachedCircle = canvas;
        this._cachedCircleRadius = radius;
        this._cachedCircleBlur = blur;
        return canvas;
    };

    CustomerMapHeatLayer.prototype._colorize = function () {
        var image = this._ctx.getImageData(0, 0, this._canvas.width, this._canvas.height);
        var pixels = image.data;
        var gradient = this._gradient;
        var alpha;
        var offset;
        var i;
        for (i = 0; i < pixels.length; i += 4) {
            alpha = pixels[i + 3];
            if (!alpha) {
                continue;
            }
            offset = alpha * 4;
            pixels[i] = gradient[offset];
            pixels[i + 1] = gradient[offset + 1];
            pixels[i + 2] = gradient[offset + 2];
            pixels[i + 3] = Math.min(225, alpha);
        }
        this._ctx.putImageData(image, 0, 0);
    };

    ready(function () {
        var el = document.getElementById('community-store-customer-map-canvas');
        if (!el || typeof L === 'undefined') {
            return;
        }

        var metricEl = document.getElementById('customer-map-metric');
        var levelEl = document.getElementById('customer-map-level');
        var displayModeEl = document.getElementById('customer-map-display-mode');
        var unpaidEl = document.getElementById('customer-map-include-unpaid');
        var statusEl = document.getElementById('community-store-customer-map-status');
        var pointsUrl = el.getAttribute('data-points-url');
        var usersBaseUrl = el.getAttribute('data-users-base-url') || '';
        var emptyLabel = el.getAttribute('data-empty-label') || 'No points found.';

        var map = L.map(el, { scrollWheelZoom: true }).setView([20, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var heatLayer = new CustomerMapHeatLayer();
        var currentPoints = [];
        var currentMaxValue = 0;

        var markers = L.markerClusterGroup({
            maxClusterRadius: 80,
            chunkedLoading: true,
            iconCreateFunction: function (cluster) {
                var children = cluster.getAllChildMarkers();
                var total = children.reduce(function (sum, marker) {
                    return sum + Number(marker.options.metricValue || 0);
                }, 0);
                var count = cluster.getChildCount();
                var metric = metricEl ? metricEl.value : 'orders';
                var level = currentMaxValue > 0 ? Math.ceil((total / currentMaxValue) * 100) : 1;
                level = Math.max(1, Math.min(100, level));
                var size = 42 + Math.round((level / 100) * 18);
                var color = colorForLevel(level);

                return L.divIcon({
                    html: '<div style="background:' + escapeHtml(color) + ';width:' + size + 'px;height:' + size + 'px"><span>' + formatNumber(count) + '</span><small>' + escapeHtml(clusterLabelValue(total, metric)) + '</small></div>',
                    className: 'community-store-customer-map-cluster',
                    iconSize: L.point(size, size),
                    iconAnchor: L.point(Math.round(size / 2), Math.round(size / 2))
                });
            }
        });

        function setStatus(message) {
            if (statusEl) {
                statusEl.textContent = message || '';
            }
        }

        function syncLayers() {
            var mode = displayModeEl ? displayModeEl.value : 'heatmap';
            var showMarkers = mode === 'markers' || mode === 'both';
            var showHeatmap = mode === 'heatmap' || mode === 'both';

            if (showHeatmap) {
                if (!map.hasLayer(heatLayer)) {
                    map.addLayer(heatLayer);
                }
                heatLayer.setPoints(currentPoints, currentMaxValue);
            } else if (map.hasLayer(heatLayer)) {
                map.removeLayer(heatLayer);
            }

            if (showMarkers) {
                if (!map.hasLayer(markers)) {
                    map.addLayer(markers);
                }
            } else if (map.hasLayer(markers)) {
                map.removeLayer(markers);
            }
        }

        function loadPoints() {
            var url = new URL(pointsUrl, window.location.origin);
            var currentLevel = levelEl ? levelEl.value : 'postal';
            var currentDisplayMode = displayModeEl ? displayModeEl.value : 'heatmap';
            url.searchParams.set('metric', metricEl ? metricEl.value : 'orders');
            url.searchParams.set('level', currentLevel);
            url.searchParams.set('display', currentDisplayMode);
            url.searchParams.set('include_unpaid', unpaidEl && unpaidEl.checked ? '1' : '0');
            setStatus('Loading map data...');

            fetch(url.toString(), { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load map data.');
                    }
                    return response.json();
                })
                .then(function (data) {
                    var bounds = [];
                    var points = Array.isArray(data.points) ? data.points : [];
                    markers.clearLayers();
                    currentPoints = points;
                    currentMaxValue = Number(data.maxValue || 0);

                    points.forEach(function (point) {
                        var marker = L.marker([point.lat, point.lng], {
                            icon: createMarkerIcon(point),
                            metricValue: point.metricValue,
                            metricLevel: point.level,
                            markerColor: point.color
                        });
                        marker.bindPopup(buildPopup(point, usersBaseUrl));
                        markers.addLayer(marker);
                        bounds.push([point.lat, point.lng]);
                    });
                    syncLayers();
                    renderTopRegions(data.topRegions || []);
                    renderOpportunities(data.opportunities || []);
                    if (bounds.length) {
                        map.fitBounds(bounds, { padding: [30, 30], maxZoom: currentLevel === 'postal' ? 10 : 12 });
                        setStatus(formatNumber(points.length) + ' postal regions' + ' loaded. Max value: ' + (data.metric === 'value' && data.maxValueFormatted ? data.maxValueFormatted : formatNumber(data.maxValue || 0)));
                    } else {
                        setStatus(emptyLabel);
                    }
                })
                .catch(function (error) {
                    setStatus(error.message || 'Unable to load map data.');
                });
        }

        if (metricEl) {
            metricEl.addEventListener('change', loadPoints);
        }
        if (levelEl) {
            levelEl.addEventListener('change', loadPoints);
        }
        if (displayModeEl) {
            displayModeEl.addEventListener('change', syncLayers);
        }
        if (unpaidEl) {
            unpaidEl.addEventListener('change', loadPoints);
        }
        loadPoints();
    });
}());
