<?php
$appFaviconPath = __DIR__ . '/../assets/img/logoInside.png';
$appFaviconVersion = file_exists($appFaviconPath) ? (string) filemtime($appFaviconPath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERNI PMS</title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/logoInside.png?v=<?php echo $appFaviconVersion; ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/logoInside.png?v=<?php echo $appFaviconVersion; ?>">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>assets/img/logoInside.png?v=<?php echo $appFaviconVersion; ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/dark-mode.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dark-mode.css'); ?>">
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        if (window.Chart?.defaults?.plugins?.legend) {
            const defaultLegendClick = Chart.defaults.plugins.legend.onClick;
            const interactiveLegendTooltip = document.createElement('div');
            interactiveLegendTooltip.className = 'chart-legend-interaction-tooltip';
            interactiveLegendTooltip.setAttribute('role', 'tooltip');
            document.addEventListener('DOMContentLoaded', () => document.body.appendChild(interactiveLegendTooltip), { once: true });

            const hideInteractiveLegendTooltip = () => {
                interactiveLegendTooltip.classList.remove('is-visible');
            };

            const showInteractiveLegendTooltip = (event, label) => {
                const nativeEvent = event?.native || event;
                const clientX = nativeEvent?.clientX;
                const clientY = nativeEvent?.clientY;
                if (!Number.isFinite(clientX) || !Number.isFinite(clientY)) return;

                interactiveLegendTooltip.textContent = `Show or hide ${label || 'this series'}`;
                interactiveLegendTooltip.style.left = `${clientX}px`;
                interactiveLegendTooltip.style.top = `${clientY - 14}px`;
                interactiveLegendTooltip.classList.add('is-visible');
            };
            window.showChartLegendInteraction = showInteractiveLegendTooltip;
            window.hideChartLegendInteraction = hideInteractiveLegendTooltip;

            Chart.register({
                id: 'interactiveLegendPill',
                afterEvent(chart, args) {
                    const legend = chart.legend;
                    const nativeEvent = args.event?.native;
                    const canvas = chart.canvas;
                    if (!legend || !canvas || !nativeEvent) return;

                    const x = args.event.x;
                    const y = args.event.y;
                    const activeIndex = legend.legendHitBoxes?.findIndex(hitBox =>
                        x >= hitBox.left
                        && x <= hitBox.left + hitBox.width
                        && y >= hitBox.top
                        && y <= hitBox.top + hitBox.height
                    ) ?? -1;

                    const previousIndex = chart.$interactiveLegendIndex;
                    chart.$interactiveLegendIndex = activeIndex >= 0 ? activeIndex : null;
                    const legendItem = activeIndex >= 0 ? legend.legendItems?.[activeIndex] : null;
                    canvas.style.cursor = legendItem ? 'pointer' : 'default';

                    if (legendItem) {
                        chart.$interactiveLegendBox = legend.legendHitBoxes?.[activeIndex] || null;
                        showInteractiveLegendTooltip(nativeEvent, legendItem.text);
                    } else {
                        hideInteractiveLegendTooltip();
                    }

                    if (previousIndex !== chart.$interactiveLegendIndex) {
                        const startedAt = performance.now();
                        const startProgress = Number(chart.$interactiveLegendProgress || 0);
                        const targetProgress = legendItem ? 1 : 0;
                        cancelAnimationFrame(chart.$interactiveLegendFrame || 0);

                        const animateLegendPill = now => {
                            const elapsed = Math.min(1, (now - startedAt) / 170);
                            const eased = 1 - Math.pow(1 - elapsed, 3);
                            chart.$interactiveLegendProgress = startProgress + ((targetProgress - startProgress) * eased);
                            chart.draw();

                            if (elapsed < 1) {
                                chart.$interactiveLegendFrame = requestAnimationFrame(animateLegendPill);
                            } else if (targetProgress === 0) {
                                chart.$interactiveLegendBox = null;
                            }
                        };
                        chart.$interactiveLegendFrame = requestAnimationFrame(animateLegendPill);
                    }
                },
                afterDraw(chart) {
                    const legend = chart.legend;
                    const hitBox = chart.$interactiveLegendBox;
                    const progress = Number(chart.$interactiveLegendProgress || 0);
                    if (!legend) return;

                    const ctx = chart.ctx;
                    if (hitBox && progress > 0) {
                        const radius = Math.min(18, hitBox.height / 2 + 6);
                        const fullWidth = hitBox.width + 20;
                        const fullHeight = hitBox.height + 12;
                        const width = fullWidth * (0.94 + (0.06 * progress));
                        const height = fullHeight * (0.9 + (0.1 * progress));
                        const x = hitBox.left - 10 + ((fullWidth - width) / 2);
                        const y = hitBox.top - 6 + ((fullHeight - height) / 2);

                        ctx.save();
                        ctx.globalAlpha = progress;
                        ctx.beginPath();
                        ctx.roundRect(x, y, width, height, radius);
                        ctx.fillStyle = 'rgba(31, 99, 146, 0.06)';
                        ctx.strokeStyle = 'rgba(31, 99, 146, 0.22)';
                        ctx.lineWidth = 1;
                        ctx.fill();
                        ctx.stroke();
                        ctx.restore();
                        legend.draw();
                    }

                    legend.legendItems?.forEach((item, index) => {
                        const hiddenBox = item.hidden ? legend.legendHitBoxes?.[index] : null;
                        if (!hiddenBox) return;

                        ctx.save();
                        ctx.globalCompositeOperation = 'destination-out';
                        ctx.fillStyle = 'rgba(0, 0, 0, 0.48)';
                        ctx.fillRect(hiddenBox.left - 3, hiddenBox.top - 3, hiddenBox.width + 6, hiddenBox.height + 6);
                        ctx.restore();
                    });
                }
            });
            Chart.defaults.plugins.legend.labels = {
                ...Chart.defaults.plugins.legend.labels,
                usePointStyle: true,
                pointStyle: 'circle',
                padding: 18
            };
            Chart.defaults.plugins.legend.onHover = function (event, legendItem, legend) {
                if (event?.native?.target) {
                    event.native.target.style.cursor = 'pointer';
                }
                showInteractiveLegendTooltip(event, legendItem?.text);
            };
            Chart.defaults.plugins.legend.onLeave = function (event, legendItem, legend) {
                if (event?.native?.target) {
                    event.native.target.style.cursor = 'default';
                }
                hideInteractiveLegendTooltip();
            };
            Chart.defaults.plugins.legend.onClick = function (event, legendItem, legend) {
                defaultLegendClick.call(this, event, legendItem, legend);
                const chart = legend.chart;
                if (chart?.options) {
                    chart.options.animation = {
                        ...(typeof chart.options.animation === 'object' ? chart.options.animation : {}),
                        duration: 500,
                        easing: 'easeOutQuart'
                    };
                    chart.update();
                }
            };
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.3.0/dist/frappe-gantt.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>" defer></script>
</head>
<body>
    <div class="d-flex" id="wrapper">
