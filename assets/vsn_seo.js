/* global uPlot, jQuery */
(function ($) {
    'use strict';

    var charts = [];
    var showUpdates = true;
    var locale = document.documentElement.lang || 'de';
    var nf = new Intl.NumberFormat(locale);

    function cssVar(el, name) {
        return getComputedStyle(el).getPropertyValue(name).trim();
    }

    function fmt(value, decimals) {
        if (value === null || value === undefined) {
            return '–';
        }
        return decimals ? value.toLocaleString(locale, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) : nf.format(value);
    }

    function fmtDate(ts) {
        return new Date(ts * 1000).toLocaleDateString(locale, {day: '2-digit', month: '2-digit', year: 'numeric'});
    }

    // senkrechte Linien für Google-Updates
    function updatesPlugin(updates, el) {
        return {
            hooks: {
                draw: [function (u) {
                    if (!showUpdates || !updates.length) {
                        return;
                    }
                    var ctx = u.ctx;
                    var top = u.bbox.top;
                    var height = u.bbox.height;
                    var color = cssVar(el, '--vsn-seo-update');
                    ctx.save();
                    ctx.strokeStyle = color;
                    ctx.fillStyle = color;
                    ctx.lineWidth = Math.max(1, devicePixelRatio);
                    ctx.setLineDash([4 * devicePixelRatio, 4 * devicePixelRatio]);
                    ctx.font = (10 * devicePixelRatio) + 'px sans-serif';
                    updates.forEach(function (upd) {
                        if (upd.x < u.scales.x.min || upd.x > u.scales.x.max) {
                            return;
                        }
                        var x = Math.round(u.valToPos(upd.x, 'x', true));
                        ctx.beginPath();
                        ctx.moveTo(x, top);
                        ctx.lineTo(x, top + height);
                        ctx.stroke();
                        ctx.save();
                        ctx.translate(x + 3 * devicePixelRatio, top + 4 * devicePixelRatio);
                        ctx.rotate(Math.PI / 2);
                        ctx.fillText(upd.name, 0, 0);
                        ctx.restore();
                    });
                    ctx.restore();
                }]
            }
        };
    }

    function build(el) {
        var cfg = JSON.parse(el.getAttribute('data-chart'));
        var axisColor = cssVar(el, '--vsn-seo-axis');
        var grid = {stroke: cssVar(el, '--vsn-seo-grid'), width: 1};
        var hasY2 = cfg.series.some(function (s) { return s.scale === 'y2'; });
        var scales = {x: {time: true}, y: {dir: cfg.invert ? -1 : 1}};
        // Beschriftung der x-Achse in der Legende kommt übersetzt aus PHP (sonst zeigt uPlot "Time")
        var series = [{label: cfg.xLabel || '', value: function (u, v) { return v === null ? '–' : fmtDate(v); }}];
        var data = [cfg.x];

        cfg.series.forEach(function (s) {
            var color = cssVar(el, '--vsn-seo-c' + s.color);
            series.push({
                label: s.label,
                scale: s.scale || 'y',
                stroke: color,
                width: 2,
                points: {show: cfg.x.length < 60},
                value: function (u, v) { return fmt(v, s.decimals); }
            });
            data.push(s.values);
        });

        var axes = [
            {stroke: axisColor, grid: grid, ticks: grid, values: function (u, splits) { return splits.map(function (v) { return new Date(v * 1000).toLocaleDateString(locale, {month: 'short', year: '2-digit'}); }); }},
            {stroke: cssVar(el, '--vsn-seo-c' + cfg.series[0].color), grid: grid, ticks: grid, size: 60, values: function (u, splits) { return splits.map(function (v) { return fmt(v, cfg.series[0].decimals); }); }}
        ];
        if (hasY2) {
            var s2 = cfg.series.filter(function (s) { return s.scale === 'y2'; })[0];
            scales.y2 = {};
            axes.push({scale: 'y2', side: 1, size: 70, grid: {show: false}, stroke: cssVar(el, '--vsn-seo-c' + s2.color), values: function (u, splits) { return splits.map(function (v) { return fmt(v); }); }});
        }

        var opts = {
            width: el.clientWidth,
            height: cfg.height || 260,
            scales: scales,
            series: series,
            axes: axes,
            cursor: {drag: {x: false, y: false}},
            plugins: [updatesPlugin(cfg.updates || [], el)]
        };
        el.innerHTML = '';
        charts.push({el: el, plot: new uPlot(opts, data, el)});
    }

    function init(root) {
        if (typeof uPlot === 'undefined') {
            return;
        }
        $(root).find('.vsn-seo-chart[data-chart]').each(function () {
            if (!this.dataset.built) {
                this.dataset.built = '1';
                build(this);
            }
        });
    }

    var resizeTimer;
    $(window).on('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            charts.forEach(function (c) {
                c.plot.setSize({width: c.el.clientWidth, height: c.plot.height});
            });
        }, 150);
    });

    $(document).on('change', '[data-vsn-seo-updates]', function () {
        showUpdates = this.checked;
        charts.forEach(function (c) { c.plot.redraw(false); });
    });

    $(document).on('rex:ready', function (e, container) {
        charts = charts.filter(function (c) { return document.body.contains(c.el); });
        init(container || document);
    });
})(jQuery);
