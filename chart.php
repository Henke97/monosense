<?php
// chart.php (fallback: använd befintlig canvas om finns, annars skapa egen container+canvas)
// Förväntar: $chart_id och $json_7d, $json_7d_humi, $json_24h, $json_24h_humi, $json_30d_range

if (!isset($chart_id)) $chart_id = 'chart_unknown';

function _safe_js_json($payload) {
    if (is_string($payload)) {
        $dec = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE) return json_encode($dec);
        return json_encode([]);
    } elseif (is_array($payload)) {
        return json_encode($payload);
    }
    return json_encode([]);
}

$js_7d       = _safe_js_json($json_7d ?? '[]');
$js_7d_humi  = _safe_js_json($json_7d_humi ?? '[]');
$js_24h      = _safe_js_json($json_24h ?? '[]');
$js_24h_humi = _safe_js_json($json_24h_humi ?? '[]');
$js_30d      = _safe_js_json($json_30d_range ?? '[]');

$inc_container_id = 'chartContainer_' . $chart_id . '_auto';
$inc_canvas_id = $chart_id . '_auto';
$max_id = 'maxValueIndicator_' . $chart_id . '_auto';
$min_id = 'minValueIndicator_' . $chart_id . '_auto';
?>

<script>
(function () {
    const chartId = <?= json_encode($chart_id) ?>;
    const phpData = {
        data7d: <?= $js_7d ?>,
        data7dHumi: <?= $js_7d_humi ?>,
        data24h: <?= $js_24h ?>,
        data24hHumi: <?= $js_24h_humi ?>,
        data30d: <?= $js_30d ?>,
    };

    // Kör i en closure — inga globals
    function createGradient(ctx) {
        const g = ctx.createLinearGradient(0,0,0,ctx.canvas.height);
        g.addColorStop(0, 'rgba(85,111,91,0.8)');
        g.addColorStop(0.4, 'rgba(85,111,91,0.12)');
        g.addColorStop(1, 'rgba(85,111,91,0)');
        return g;
    }

    function buildDatasets(d) {
        return {
            '7d': {
                type: 'line',
                datasets: [
                    { label: 'Temperatur', data: d.data7d, borderColor: 'rgba(85,111,91,1)', borderWidth: 1, pointRadius: 0, fill: true, tension: 0.3 },
                    { label: 'Luftfuktighet', data: d.data7dHumi, borderColor: 'rgba(85,111,91,0.5)', borderDash: [4,4], borderWidth: 1, pointRadius: 0, fill: false, tension:0.3 }
                ]
            },
            '30d': {
                type: 'bar',
                datasets: [{ label: 'Min–Max', data: d.data30d, barThickness: 8 }]
            },
            '24h': {
                type: 'line',
                datasets: [
                    { label: 'Temperatur', data: d.data24h, borderColor: 'rgba(85,111,91,1)', borderWidth:1, pointRadius:0, fill:true, tension:0.3 },
                    { label: 'Luftfuktighet', data: d.data24hHumi, yAxisID: 'y2', borderColor: 'rgba(85,111,91,0.5)', borderDash:[4,4], borderWidth:1, pointRadius:0, fill:false, tension:0.3 }
                ]
            }
        };
    }

    // försöker destroy:a en eventuell existerande Chart-instans kopplad till canvas
    function destroyExistingChart(canvas) {
        try {
            if (!canvas) return;
            // Chart.js v3/v4
            if (typeof Chart.getChart === 'function') {
                const inst = Chart.getChart(canvas) || Chart.getChart(canvas.id);
                if (inst && typeof inst.destroy === 'function') {
                    inst.destroy();
                    return;
                }
            }

            // fallback: äldre Chart.js - loop över Chart.instances om den finns
            if (typeof Chart.instances !== 'undefined') {
                // Chart.instances kan vara ett objekt eller array beroende på version
                try {
                    for (const key in Chart.instances) {
                        if (!Chart.instances.hasOwnProperty(key)) continue;
                        const maybe = Chart.instances[key];
                        if (maybe && maybe.canvas && maybe.canvas.id === canvas.id) {
                            if (typeof maybe.destroy === 'function') {
                                maybe.destroy();
                            }
                        }
                    }
                } catch (e) {
                    // ignore
                }
            }

            // sista utväg: vissa versioner lägger referens på canvas._chartjs eller canvas.__chartjs
            if (canvas._chartjs && canvas._chartjs[0] && canvas._chartjs[0].chartInstance) {
                try { canvas._chartjs[0].chartInstance.destroy(); } catch (e) {}
            }
            if (canvas.__chartjs && canvas.__chartjs.chart) {
                try { canvas.__chartjs.chart.destroy(); } catch (e) {}
            }
        } catch (e) {
            console.warn('destroyExistingChart error', e);
        }
    }

    // Om canvas med chartId finns — använd den. Annars skapa en egen container + canvas.
    let canvas = document.getElementById(chartId);
    let createdByInclude = false;
    if (!canvas) {
        // skapa ny container precis efter var include körs
        const wrapper = document.createElement('div');
        wrapper.id = <?= json_encode($inc_container_id) ?>;
        wrapper.className = 'chart-container';
        wrapper.style.cssText = 'width:100%; height:160px; position:relative; margin:0.5rem 0;';
        canvas = document.createElement('canvas');
        canvas.id = <?= json_encode($inc_canvas_id) ?>;
        canvas.style.cssText = 'width:100%; height:100%;';
        const maxEl = document.createElement('div'); maxEl.id = <?= json_encode($max_id) ?>; maxEl.className = 'value-indicator'; maxEl.style.pointerEvents='none';
        const minEl = document.createElement('div'); minEl.id = <?= json_encode($min_id) ?>; minEl.className = 'value-indicator'; minEl.style.pointerEvents='none';
        wrapper.appendChild(canvas);
        wrapper.appendChild(maxEl);
        wrapper.appendChild(minEl);
        // lägg wrapper efter det sista script-taggen (detta include-block)
        const currentScript = document.currentScript;
        if (currentScript && currentScript.parentNode) {
            currentScript.parentNode.insertBefore(wrapper, currentScript.nextSibling);
        } else {
            // fallback: append to body
            document.body.appendChild(wrapper);
        }
        createdByInclude = true;
    } else {
        // Om canvas hittades: säkerställ att vi först destroy:ar ev. existerande chart
        destroyExistingChart(canvas);
    }

    (function initChart() {
        // defensiv check
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        // Destroy again defensivt (om något annat skapade chart mellan ovan och nu)
        destroyExistingChart(canvas);

        const sets = buildDatasets(phpData);
        let current = '24h';
        const initial = sets[current];

        // skapa gradient efter vi har ctx
        if (initial.datasets && initial.datasets[0]) {
            initial.datasets[0].backgroundColor = createGradient(ctx);
        }

        // skapa chart i try/catch för att fånga eventuella fel utan att bryta sidan
        let chart;
        try {
            chart = new Chart(ctx, {
                type: initial.type,
                data: { datasets: initial.datasets },
                options: {
                    parsing: { xAxisKey: 'x', yAxisKey: 'y' },
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: null },
                    scales: {
                        x: { type:'time', time:{ unit: 'day' }, ticks: { color: 'rgba(85,111,91,0.6)' }, grid:{ color:'rgba(85,111,91,0.08)'} },
                        y: { ticks: { color:'rgba(85,111,91,0.6)'} },
                        y2: { type:'linear', position:'right', grid:{ drawOnChartArea:false }, ticks:{ min:0, max:100, stepSize:20 } }
                    },
                    plugins: { legend: { display:false }, tooltip:{ enabled:false } },
                    animation: { duration:0 }
                }
            });
        } catch (e) {
            console.error('Could not create Chart instance for', canvas.id, e);
            return;
        }

        // klick roterar period (förenklad)
        canvas.addEventListener('click', () => {
            const order = ['24h','7d','30d'];
            let i = order.indexOf(current);
            i = (i + 1) % order.length;
            current = order[i];
            const cfg = sets[current];

            // destroy previous chart instance before re-creating to avoid Chart.js error
            try {
                if (chart && typeof chart.destroy === 'function') chart.destroy();
            } catch (e) {
                // ignore
            }

            // recreate with new cfg
            try {
                // åter-bygg gradient för nya ctx (kan behövas om canvas size ändrats)
                const g = createGradient(ctx);
                if (cfg.datasets && cfg.datasets[0]) cfg.datasets[0].backgroundColor = g;

                chart = new Chart(ctx, {
                    type: cfg.type,
                    data: { datasets: cfg.datasets },
                    options: {
                        parsing: { xAxisKey: 'x', yAxisKey: 'y' },
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: null },
                        scales: {
                            x: { type:'time', time:{ unit: (current === '24h') ? 'hour' : 'day' }, ticks: { color: 'rgba(85,111,91,0.6)' }, grid:{ color:'rgba(85,111,91,0.08)'} },
                            y: { ticks: { color:'rgba(85,111,91,0.6)'} },
                            y2: { type:'linear', position:'right', grid:{ drawOnChartArea:false }, ticks:{ min:0, max:100, stepSize:20 } }
                        },
                        plugins: { legend: { display:false }, tooltip:{ enabled:false } },
                        animation: { duration:0 }
                    }
                });
            } catch (e) {
                console.error('Could not recreate Chart instance for', canvas.id, e);
            }
        }, { passive: true });

    })();

})();
</script>
