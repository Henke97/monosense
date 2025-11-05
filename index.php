<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Hämta sensorer för användaren (inkl. temp_calibration)
$sensor_query = $con->prepare("SELECT sensor_id, sensor_givenname, sensor_name, sensor_location, temp_calibration FROM sensors WHERE user_id = ?");
$sensor_query->bind_param("i", $user_id);
$sensor_query->execute();
$sensors_result = $sensor_query->get_result();
$sensors = $sensors_result->fetch_all(MYSQLI_ASSOC);

$sensors_with_data = [];

foreach ($sensors as $sensor) {
    $sensor_id = intval($sensor['sensor_id']);

    $data_query = $con->prepare("
        SELECT value4 AS battery, value5 AS wifi, value8 AS mv, value9 AS ldr, value10 AS soil, reading_time
        FROM sensor_data
        WHERE sensor_id = ?
        ORDER BY reading_time DESC
        LIMIT 1
    ");
    $data_query->bind_param("i", $sensor_id);
    $data_query->execute();
    $result = $data_query->get_result();
    $data = $result->fetch_assoc();

    $sensor['battery'] = $data['battery'] ?? '';
    $sensor['wifi'] = $data['wifi'] ?? '';
    $sensor['mv'] = $data['mv'] ?? '';
    $sensor['ldr'] = $data['ldr'] ?? '';
    $sensor['soil'] = $data['soil'] ?? '';
    $sensor['reading_time'] = $data['reading_time'] ?? '';

    // Behåll temp_calibration i sensorn (float)
    $sensor['temp_calibration'] = isset($sensor['temp_calibration']) ? floatval($sensor['temp_calibration']) : 0.0;

    $sensors_with_data[] = $sensor;
}

?>

<!DOCTYPE html>
<html lang="sv">
<head>
     <?php include 'head.php'; ?>
    <meta charset="UTF-8">
    <title>Monosense</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="logo">
        <a href="index.php"><img src="img/logga3.png"></a>
    </div>
    <h2>Monosense</h2>

    <div class="sensor-filter-buttons">
    <button onclick="filterSensors('out')" data-sortorder-filterbutton="out">Utomhus</button>
    <button onclick="filterSensors('in')" data-sortorder-filterbutton="in">Inomhus</button>
    <button onclick="filterSensors('gh')" data-sortorder-filterbutton="gh">Växthus</button>
    </div>

    <main class="sensor-container">
        <div class="sensor-grid">
            <?php foreach ($sensors_with_data as $sensor): ?>
                <?php
                $sensor_id = $sensor['sensor_id'];
                $givenname = $sensor['sensor_givenname'] ?? $sensor['sensor_name'];
                $sensor_location = $sensor['sensor_location'];
                $cal = floatval($sensor['temp_calibration'] ?? 0.0); // kalibrering per sensor

                // Senaste mätningen (rå)
                $latest_stmt = $con->prepare("SELECT value1 AS temp, value2 AS humidity, reading_time FROM sensor_data WHERE sensor_id = ? ORDER BY reading_time DESC LIMIT 1");
                $latest_stmt->bind_param("i", $sensor_id);
                $latest_stmt->execute();
                $latest = $latest_stmt->get_result()->fetch_assoc();

                // applicera kalibrering på senaste temp (om numerisk)
                if (isset($latest['temp']) && is_numeric($latest['temp'])) {
                    $raw_latest_temp = floatval($latest['temp']);
                    $calibrated_latest_temp = round($raw_latest_temp + $cal, 2);
                } else {
                    $raw_latest_temp = null;
                    $calibrated_latest_temp = null;
                }

                // Dagens min/max (rå) -> justera i PHP
                $minmax_stmt = $con->prepare("SELECT MAX(value1) AS max_temp, MIN(value1) AS min_temp, MAX(value2) AS max_humi, MIN(value2) AS min_humi FROM sensor_data WHERE sensor_id = ? AND DATE(reading_time) = CURDATE()");
                $minmax_stmt->bind_param("i", $sensor_id);
                $minmax_stmt->execute();
                $minmax = $minmax_stmt->get_result()->fetch_assoc();

                $minmax_display = [
                    'max_temp' => null,
                    'min_temp' => null,
                    'max_humi' => null,
                    'min_humi' => null
                ];
                if (isset($minmax['max_temp']) && is_numeric($minmax['max_temp'])) {
                    $minmax_display['max_temp'] = round(floatval($minmax['max_temp']) + $cal, 2);
                }
                if (isset($minmax['min_temp']) && is_numeric($minmax['min_temp'])) {
                    $minmax_display['min_temp'] = round(floatval($minmax['min_temp']) + $cal, 2);
                }
                if (isset($minmax['max_humi'])) $minmax_display['max_humi'] = $minmax['max_humi'];
                if (isset($minmax['min_humi'])) $minmax_display['min_humi'] = $minmax['min_humi'];

                // 7d max temperatur (per day) - justera vid byggande av array
                $chart_stmt = $con->prepare("
                    SELECT DATE(reading_time) AS day, MAX(value1) AS max_val
                    FROM sensor_data
                    WHERE sensor_id = ? AND reading_time >= CURDATE() - INTERVAL 6 DAY AND value1 IS NOT NULL
                    GROUP BY day ORDER BY day ASC
                ");
                $chart_stmt->bind_param("i", $sensor_id);
                $chart_stmt->execute();
                $res_7d = $chart_stmt->get_result();
                $data_7d = [];
                while ($r = $res_7d->fetch_assoc()) {
                    $raw = is_numeric($r['max_val']) ? floatval($r['max_val']) : null;
                    $y = $raw !== null ? round($raw + $cal, 2) : null;
                    $data_7d[] = ['x' => $r['day'], 'y' => $y];
                }

                // 7d batteri (mV)
                $mv_stmt = $con->prepare("
                    SELECT DATE(reading_time) AS day, MAX(value8) AS max_mv
                    FROM sensor_data
                    WHERE sensor_id = ? AND reading_time >= CURDATE() - INTERVAL 30 DAY AND value8 IS NOT NULL
                    GROUP BY day ORDER BY day ASC
                ");
                $mv_stmt->bind_param("i", $sensor_id);
                $mv_stmt->execute();
                $res_mv = $mv_stmt->get_result();
                $data_mv_7d = [];
                while ($r = $res_mv->fetch_assoc()) {
                    $data_mv_7d[] = ['x' => $r['day'], 'y' => floatval($r['max_mv'])];
                }
                $json_mv_7d = json_encode($data_mv_7d);

                // 7d humi (unchanged)
                $chart_stmt_humi = $con->prepare("
                    SELECT DATE(reading_time) AS day, MAX(value2) AS max_val
                    FROM sensor_data
                    WHERE sensor_id = ? AND reading_time >= CURDATE() - INTERVAL 6 DAY AND value2 IS NOT NULL
                    GROUP BY day ORDER BY day ASC
                ");
                $chart_stmt_humi->bind_param("i", $sensor_id);
                $chart_stmt_humi->execute();
                $res_7d_humi = $chart_stmt_humi->get_result();
                $data_7d_humi = [];
                while ($r = $res_7d_humi->fetch_assoc()) {
                    $data_7d_humi[] = ['x' => $r['day'], 'y' => floatval($r['max_val'])];
                }
                $json_7d_humi = json_encode($data_7d_humi);

                // 24h humi serie (unchanged)
                $data24_humi_stmt = $con->prepare("SELECT reading_time, value2 FROM sensor_data WHERE sensor_id = ? AND reading_time >= NOW() - INTERVAL 1 DAY AND value2 IS NOT NULL ORDER BY reading_time");
                $data24_humi_stmt->bind_param("i", $sensor_id);
                $data24_humi_stmt->execute();
                $res24_humi = $data24_humi_stmt->get_result();
                $data_24h_humi = [];
                while ($r = $res24_humi->fetch_assoc()) {
                    $iso_time = date('c', strtotime($r['reading_time']));
                    $data_24h_humi[] = ['x' => $iso_time, 'y' => floatval($r['value2'])];
                }
                $json_24h_humi = json_encode($data_24h_humi);

                // 24h temperaturserie - justera varje punkt
                $data24_stmt = $con->prepare("SELECT reading_time, value1 FROM sensor_data WHERE sensor_id = ? AND reading_time >= NOW() - INTERVAL 1 DAY AND value1 IS NOT NULL ORDER BY reading_time");
                $data24_stmt->bind_param("i", $sensor_id);
                $data24_stmt->execute();
                $res24 = $data24_stmt->get_result();
                $data_24h = [];
                while ($r = $res24->fetch_assoc()) {
                    $iso_time = date('c', strtotime($r['reading_time']));
                    $raw = is_numeric($r['value1']) ? floatval($r['value1']) : null;
                    $y = $raw !== null ? round($raw + $cal, 2) : null;
                    $data_24h[] = ['x' => $iso_time, 'y' => $y];
                }

                // 30d min/max - justera min & max med kalibrering
                $data30_stmt = $con->prepare("
                    SELECT DATE(reading_time) AS day, MIN(value1) AS min_val, MAX(value1) AS max_val
                    FROM sensor_data
                    WHERE sensor_id = ? AND reading_time >= NOW() - INTERVAL 30 DAY AND value1 IS NOT NULL
                    GROUP BY day ORDER BY day ASC
                ");
                $data30_stmt->bind_param("i", $sensor_id);
                $data30_stmt->execute();
                $res30 = $data30_stmt->get_result();

                $min_30d = [];
                $max_30d = [];
                $data_30d_range = [];

                while ($r = $res30->fetch_assoc()) {
                    $day = $r['day'];
                    $min_raw = is_numeric($r['min_val']) ? floatval($r['min_val']) : null;
                    $max_raw = is_numeric($r['max_val']) ? floatval($r['max_val']) : null;

                    $min = $min_raw !== null ? round($min_raw + $cal, 2) : null;
                    $max = $max_raw !== null ? round($max_raw + $cal, 2) : null;

                    $min_30d[] = ['x' => $day, 'y' => $min];
                    $max_30d[] = ['x' => $day, 'y' => $max];
                    $data_30d_range[] = ['x' => $day, 'y' => [$min, $max]];
                }

                $json_30d_min = json_encode($min_30d);
                $json_30d_max = json_encode($max_30d);
                $json_30d_range = json_encode($data_30d_range);

                $json_7d = json_encode($data_7d);
                $json_24h = json_encode($data_24h);

                $chart_id = "chart_" . $sensor_id;
                ?>

           

<!-- Popup för batterichart --> 
    
    <div id="popupOverlay" class="popup-overlay" style="display: none;"></div>
    <div id="mvPopup" class="popup" style="display: none;">
        <div class="popup-content">
            <span id="popupClose" class="popup-close">×</span>
            <h3>Batteri</h3>
            <canvas id="mvChart"></canvas>
        </div>
    </div>


         

            <div class="sensor-box <?= htmlspecialchars($sensor_location) ?>" data-sensor-id="<?= $sensor_id ?>">
                <h3><?= htmlspecialchars($givenname) ?></h3>
                <span class="move-up-btn" title="Flytta överst" role="button">↑</span>
                <span class="hide-btn" title="Dölj box" role="button">×</span>

                <div id="valueContainer_<?= $chart_id ?>" class="sensor-values">
                    <!-- Temperatur & Luftfuktighet -->
                    <div class="sensor-block-row">
                        <div class="sensor-block" onclick="toggleChart('<?= $chart_id ?>')">

                            <div class="value-row big temp-normal"><?= $calibrated_latest_temp !== null ? htmlspecialchars($calibrated_latest_temp) . '°C' : '–' ?></div>
                            <div class="value-row">↑<?= htmlspecialchars($minmax_display['max_temp'] ?? '–') ?>° | ↓<?= htmlspecialchars($minmax_display['min_temp'] ?? '–') ?>°</div>
                        </div>
                        
                        <div class="sensor-separator"></div>
                        
                        <div class="sensor-block" onclick="toggleChart('<?= $chart_id ?>')">
                            <div class="value-row big"><div class="value"><?= htmlspecialchars($latest['humidity'] ?? '–') ?>%</div></div>
                            <div class="value-row">↑<?= htmlspecialchars($minmax_display['max_humi'] ?? '–') ?>% | ↓<?= htmlspecialchars($minmax_display['min_humi'] ?? '–') ?>%</div>
                            
                        </div>
                    </div>
                </div>

                        <!-- Rad 2: Ljus + reservblock -->
                        <div class="sensor-block-row">
                                
                                    <div class="value-row2">
                                            <!-- Vänsterdel: Lux -->
                                            <div style="width: 50%; display: flex; flex-direction: column; align-items: flex-end;">
                                                <?php
                                                if ($sensor['ldr'] !== '' && $sensor['ldr'] !== null && $sensor['ldr'] !== 'NULL') {
                                                    $ldr = (int) $sensor['ldr'];

                                                    echo '<span class="lux-value">' . $ldr . ' Lux</span>';

                                                    if ($ldr <= 2000)
                                                        $steps = 1;
                                                    elseif ($ldr <= 10000)
                                                        $steps = 2;
                                                    elseif ($ldr <= 30000)
                                                        $steps = 3;
                                                    else
                                                        $steps = 4;

                                                    echo '<div class="lux-bar">';
                                                    for ($i = 1; $i <= 4; $i++) {
                                                        $class = ($i <= $steps) ? 'bar-segment active' : 'bar-segment';
                                                        echo '<span class="' . $class . '"></span>';
                                                    }
                                                    echo '</div>';
                                                }
                                                ?>
                                            </div>

                                            <!-- Separator -->
                                            <div class="sensor-separator"></div>

                                            <!-- Högerdel: Soil -->
                                            <div style="width: 50%; display: flex; flex-direction: column; align-items: flex-end;">
                                                    <?php
                                                    if ($sensor['soil'] !== '' && $sensor['soil'] !== null && $sensor['soil'] !== 'NULL') {
                                                        $soil = (int) $sensor['soil'];

                                                        echo '<span class="lux-value">' . $soil . ' ¤</span>';

                                                        if ($soil <= 500)
                                                            $steps = 4;
                                                        elseif ($soil <= 1000)
                                                            $steps = 3;
                                                        elseif ($soil <= 2000)
                                                            $steps = 2;
                                                        else
                                                            $steps = 1;

                                                        echo '<div class="lux-bar">';
                                                        for ($i = 1; $i <= 4; $i++) {
                                                            $class = ($i <= $steps) ? 'bar-segment active' : 'bar-segment';
                                                            echo '<span class="' . $class . '"></span>';
                                                        }
                                                        echo '</div>';
                                                    }
                                                    ?>
                                                </div>
                                        </div>


                                

                            </div>


              

                            <div id="chartContainer_<?= $chart_id ?>" class="chart-container">
                                <canvas id="<?= $chart_id ?>"></canvas>
                                <div id="maxValueIndicator_<?= $chart_id ?>" class="value-indicator"></div>
                                <div id="minValueIndicator_<?= $chart_id ?>" class="value-indicator"></div>
                            </div>


     
<script>
(function () {
    const id = '<?= $chart_id ?>';
    const canvas = document.getElementById(id);
    const ctx = canvas.getContext('2d');

    function gradient(ctx) {
        const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height);
        g.addColorStop(0, 'rgba(85, 111, 91, 0.8)');
        g.addColorStop(0.4, 'rgba(85, 111, 91, 0.1)');
        g.addColorStop(1, 'rgba(85, 111, 91, 0)');
        return g;
    }

    const yLabels = {
        '7d': '7 dagar',
        '24h': '24h',
        '30d': '30 dagar'
    };

    const dataSets = {
        "7d": {
            type: "line",
            datasets: [
                {
                    label: "Temperatur",
                    data: <?= $json_7d ?>,
                    borderColor: "rgba(85,111,91,1)",
                    borderWidth: 1,
                    backgroundColor: gradient(ctx),
                    pointRadius: 0,
                    fill: true,
                    tension: 0.3
                },
                {
                    label: "Luftfuktighet",
                    data: <?= $json_7d_humi ?>,
                    borderColor: "rgba(85,111,91,0.5)",
                    borderDash: [4, 4],
                    borderWidth: 1,
                    pointRadius: 0,
                    fill: false,
                    tension: 0.3
                }
            ]
        },
        "30d": {
            type: "bar",
            datasets: [{
                label: "Min–Max",
                data: <?= $json_30d_range ?>,
                barThickness: 1,
                backgroundColor: "rgba(85,111,91,1)"
            }]
        },
        "24h": {
            type: "line",
            datasets: [
                {
                    label: "Temperatur",
                    data: <?= $json_24h ?>,
                    borderColor: "rgba(85,111,91,1)",
                    borderWidth: 1,
                    backgroundColor: gradient(ctx),
                    pointRadius: 0,
                    fill: true,
                    tension: 0.3
                },
                {
                    label: "Luftfuktighet",
                    data: <?= $json_24h_humi ?>,
                    yAxisID: "y2",
                    borderColor: "rgba(85,111,91,0.5)",
                    borderDash: [4, 4],
                    borderWidth: 1,
                    pointRadius: 0,
                    fill: false,
                    tension: 0.3
                }
            ]
        }
    };


        // Förbered suggestedMin/suggestedMax för 24h direkt
        const values24h = dataSets["24h"].datasets[0].data.map(p => p.y).filter(v => typeof v === 'number' && !isNaN(v));
        if (values24h.length) {
            const min = Math.min(...values24h);
            const max = Math.max(...values24h);
            dataSets["24h"].options = {
                scales: {
                    y: {
                        suggestedMin: min - 1,
                        suggestedMax: max + 1
                    }
                }
            };
        }

        let current = "24h";
        const chart = new Chart(ctx, {
            type: dataSets[current].type,
            data: { datasets: dataSets[current].datasets },
            options: {
                parsing: { xAxisKey: 'x', yAxisKey: 'y' },
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: null },
                scales: {
                    x: {
                        type: 'time',
                        time: { unit: current === '24h' ? 'hour' : 'day' },
                        ticks: {
                            color: 'rgba(85,111,91,0.6)',
                            minRotation: 55,
                            maxRotation: 45,
                            font: { size: 9, family: 'Exo 2', weight: 600 }
                        },
                        grid: { color: 'rgba(85,111,91,0.1)' }
                    },
                    y: {
                        title: {
                            display: true,
                            text: yLabels[current],
                            color: 'rgba(85,111,91,0.8)',
                            font: {
                                size: 10,
                                family: 'Exo 2',
                                weight: '600'
                            },
                            padding: { bottom: 5 }
                        },
                        ticks: {
                            color: 'rgba(85,111,91,0.6)',
                            precision: 0,
                            font: { size: 7, family: 'Exo 2', weight: 600 }
                        },
                        grid: {
                            color: ctx => ctx.tick.value === 0 ? 'rgba(255,255,255,0)' : 'rgba(85,111,91,0.1)',
                            lineWidth: ctx => ctx.tick.value === 0 ? 2 : 1
                        }
                    },
                     y2: {
                            type: 'linear',
                            position: 'right',
                            title: { text: 'Luftfuktighet (%)', display: false },
                            ticks: {
                             min: 0,
                         max: 100,
                         stepSize: 20,
                        color: 'rgba(85,111,91,0.4)',
                         font: { size: 8 }
                          },
                    grid: { drawOnChartArea: false }
                }
                    
                },
                layout: { padding: { top: 20, right: 0, bottom: 0, left: 0 } },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                },
                animation: { duration: 0 }
            }
        });

        chart.options.animation.onComplete = function () {
            const dataset = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            const maxIndicator = document.getElementById('maxValueIndicator_' + id);
            const minIndicator = document.getElementById('minValueIndicator_' + id);

            if (!dataset.data || !dataset.data.length) return;

            if (chart.config.type === 'bar' && Array.isArray(dataset.data[0].y)) {
                let maxVal = -Infinity;
                let minVal = Infinity;
                let maxIndex = -1;
                let minIndex = -1;

                dataset.data.forEach((entry, index) => {
                    const [min, max] = entry.y;
                    if (max > maxVal) { maxVal = max; maxIndex = index; }
                    if (min < minVal) { minVal = min; minIndex = index; }
                });

                const barMax = meta.data[maxIndex];
                const barMin = meta.data[minIndex];

                if (barMax && maxIndicator) {
                    maxIndicator.style.left = barMax.x + canvas.offsetLeft - 10 + "px";
                    maxIndicator.style.top = barMax.y + canvas.offsetTop - 8 + "px";
                    maxIndicator.innerHTML = maxVal + '°';
                    maxIndicator.style.opacity = 1;
                }

                if (barMin && minIndicator) {
                    const bottom = barMin.y + barMin.height;
                    minIndicator.style.left = barMin.x + canvas.offsetLeft - 10 + "px";
                    minIndicator.style.top = bottom + canvas.offsetTop - 10 + "px";
                    minIndicator.innerHTML = minVal + '°';
                    minIndicator.style.opacity = 1;
                }

            } else {
                const maxVal = Math.max(...dataset.data.map(p => p.y));
                const minVal = Math.min(...dataset.data.map(p => p.y));
                const maxIdx = dataset.data.findIndex(p => p.y === maxVal);
                const minIdx = dataset.data.findIndex(p => p.y === minVal);
                const pointMax = meta.data[maxIdx];
                const pointMin = meta.data[minIdx];

                if (pointMax && maxIndicator) {
    const isFirst = maxIdx === 0;
    const isLast = maxIdx === dataset.data.length - 1;
    const offsetX = isFirst ? 5 : isLast ? -35 : -10;

    maxIndicator.style.left = pointMax.x + canvas.offsetLeft + offsetX + "px";
    maxIndicator.style.top = pointMax.y + canvas.offsetTop - 15 + "px";
    maxIndicator.innerHTML = maxVal + '°';
    maxIndicator.style.opacity = 1;
}

if (pointMin && minIndicator) {
    const isFirst = minIdx === 0;
    const isLast = minIdx === dataset.data.length - 1;
    const offsetX = isFirst ? 5 : isLast ? -35 : -10;

    minIndicator.style.left = pointMin.x + canvas.offsetLeft + offsetX + "px";
    minIndicator.style.top = pointMin.y + canvas.offsetTop + 10 + "px";
    minIndicator.innerHTML = minVal + '°';
    minIndicator.style.opacity = 1;
}

            }
        };

                            canvas.addEventListener('click', () => {
                                const next = {"7d": "30d", "30d": "24h", "24h": "7d"};
                                current = next[current];

                                const cfg = dataSets[current];
                                chart.config.type = cfg.type;
                                chart.data.datasets = cfg.datasets;
                                chart.options.scales.x.time.unit = current === '24h' ? 'hour' : 'day';
                                chart.options.scales.y.title.text = yLabels[current];
                                
                             

                                if (cfg.options && cfg.options.scales && cfg.options.scales.y) {
                                    chart.options.scales.y.suggestedMin = cfg.options.scales.y.suggestedMin;
                                    chart.options.scales.y.suggestedMax = cfg.options.scales.y.suggestedMax;
                                } else {
                                    delete chart.options.scales.y.suggestedMin;
                                    delete chart.options.scales.y.suggestedMax;
                                }

                                chart.update();
                            });

                            chart.update();
                        })();
                    </script>
                    
                    
    <script>
    function toggleChart(chartId) {
      const chartContainer = document.getElementById(`chartContainer_${chartId}`);
      const icon = document.querySelector(`#toggle_${chartId} i`);
      const isMobile = window.innerWidth <= 768;

      if (!isMobile) return; // endast mobil ska toggla

      const isVisible = window.getComputedStyle(chartContainer).display !== 'none';
      chartContainer.style.display = isVisible ? 'none' : 'block';

      if (icon) {
        icon.classList.toggle('fa-chevron-right', isVisible);
        icon.classList.toggle('fa-chevron-down', !isVisible);
      }
    }

</script>

<script>
function filterSensors(loc) {
  const boxes = document.querySelectorAll('.sensor-box');

  // Läs dolda från localStorage (robust parsing)
  let hidden = [];
  try {
    hidden = JSON.parse(localStorage.getItem('hiddenBoxes')) || [];
    hidden = hidden.map(x => String(x));
  } catch (e) {
    hidden = [];
  }

  boxes.forEach(box => {
    const id = String(box.dataset.sensorId ?? '');
    // Om boxen är markerad som dold i localStorage -> visa aldrig den
    if (hidden.includes(id)) {
      box.style.display = 'none';
      return;
    }

    // Annars: visa endast om klass matchar loc
    if (box.classList.contains(loc)) {
      box.style.display = '';
    } else if (box.classList.contains('in') || box.classList.contains('out') || box.classList.contains('gh')) {
      box.style.display = 'none';
    } else {
      // Om boxen inte har någon av de förväntade klasserna, lämna som är (för säkerhets skull)
    }
  });

  // Hantera knappens aktiva stil
  const buttons = document.querySelectorAll('.sensor-filter-buttons button');
  buttons.forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.querySelector(`.sensor-filter-buttons button[onclick="filterSensors('${loc}')"]`);
  if (activeBtn) activeBtn.classList.add('active');
}
</script>


<!-- --- Robust hantering av order + hide (ersätter tidigare fragila kod) --- -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.sensor-grid');
  if (!container) return;

  const orderKey = 'sensorOrder';
  const hiddenKey = 'hiddenBoxes';

  /* --- Order hantering --- */
  function saveOrder() {
    const ids = Array.from(container.children).map(el => el.dataset.sensorId);
    localStorage.setItem(orderKey, JSON.stringify(ids));
  }

  function loadOrder() {
    const saved = JSON.parse(localStorage.getItem(orderKey));
    if (!Array.isArray(saved)) return;
    // Reverse så att prepend bevarar ordningen
    saved.slice().reverse().forEach(id => {
      const el = container.querySelector(`.sensor-box[data-sensor-id="${id}"]`);
      if (el) container.prepend(el);
    });
  }

  /* --- Hidden hantering (robust) --- */
  function addHidden(id) {
    id = String(id);
    const hidden = JSON.parse(localStorage.getItem(hiddenKey)) || [];
    if (!hidden.includes(id)) {
      hidden.push(id);
      localStorage.setItem(hiddenKey, JSON.stringify(hidden));
    }
  }
  function removeHidden(id) {
    id = String(id);
    const hidden = JSON.parse(localStorage.getItem(hiddenKey)) || [];
    const idx = hidden.indexOf(id);
    if (idx !== -1) {
      hidden.splice(idx, 1);
      localStorage.setItem(hiddenKey, JSON.stringify(hidden));
    }
  }
  function loadHidden() {
    const hidden = JSON.parse(localStorage.getItem(hiddenKey)) || [];
    hidden.forEach(id => {
      const el = container.querySelector(`.sensor-box[data-sensor-id="${id}"]`);
      if (el) el.style.display = 'none';
    });
  }

  /* --- Event delegation för move-up och hide --- */
  container.addEventListener('click', (ev) => {
    const target = ev.target;

    // Move-up
    if (target.closest('.move-up-btn')) {
      const el = target.closest('.sensor-box');
      if (!el) return;
      container.prepend(el);
      saveOrder();
      return;
    }

    // Hide (kryss)
    if (target.closest('.hide-btn')) {
      const box = target.closest('.sensor-box');
      if (!box) return;
      const id = String(box.dataset.sensorId);
      box.style.display = 'none';
      addHidden(id);
      saveOrder(); // uppdatera ordningen för synliga
      return;
    }
  });

  /* --- Restore-knapp: visa alla och rensa hidden --- */
  const restoreBtn = document.getElementById('restore-btn');
  if (restoreBtn) {
    restoreBtn.addEventListener('click', () => {
      localStorage.removeItem(hiddenKey);
      // visa alla boxar
      container.querySelectorAll('.sensor-box').forEach(box => box.style.display = '');
    });
  }

  /* --- Ladda ordning först, sedan dolda --- */
  loadOrder();
  loadHidden();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const buttonContainer = document.querySelector('.sensor-filter-buttons');
    const orderKey = 'filterButtonOrder';
    const holdTime = 600;

    function saveButtonOrder() {
        const order = Array.from(buttonContainer.children).map(btn =>
            btn.getAttribute('data-sortorder-filterbutton'));
        localStorage.setItem(orderKey, JSON.stringify(order));
    }

    function updateActiveButton(key) {
        buttonContainer.querySelectorAll('button').forEach(btn => {
            const btnKey = btn.getAttribute('data-sortorder-filterbutton');
            btn.classList.toggle('active', btnKey === key);
        });
    }

    function applyStoredOrderAndFilter() {
        const saved = JSON.parse(localStorage.getItem(orderKey));
        const map = {};
        buttonContainer.querySelectorAll('button').forEach(btn => {
            const key = btn.getAttribute('data-sortorder-filterbutton');
            if (key) map[key] = btn;
        });

        if (saved && Array.isArray(saved)) {
            saved.forEach(key => {
                const btn = map[key];
                if (btn) buttonContainer.appendChild(btn);
            });

            const firstKey = saved[0];
            if (firstKey) {
                // Kör när allt är laddat
                window.addEventListener('load', () => {
                    filterSensors(firstKey);
                    updateActiveButton(firstKey);
                });
            }
        } else {
            // Ingen lagrad ordning – ta första från DOM
            const btn = buttonContainer.querySelector('button');
            const key = btn?.getAttribute('data-sortorder-filterbutton');
            if (key) {
                window.addEventListener('load', () => {
                    filterSensors(key);
                    updateActiveButton(key);
                });
            }
        }
    }

    function enableLongPress() {
        let timer = null;

        buttonContainer.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('mousedown', () => {
                timer = setTimeout(() => {
                    buttonContainer.insertBefore(btn, buttonContainer.firstChild);
                    saveButtonOrder();
                    const key = btn.getAttribute('data-sortorder-filterbutton');
                    filterSensors(key);
                    updateActiveButton(key);
                }, holdTime);
            });

            btn.addEventListener('mouseup', () => clearTimeout(timer));
            btn.addEventListener('mouseleave', () => clearTimeout(timer));
            btn.addEventListener('touchstart', () => {
                timer = setTimeout(() => {
                    buttonContainer.insertBefore(btn, buttonContainer.firstChild);
                    saveButtonOrder();
                    const key = btn.getAttribute('data-sortorder-filterbutton');
                    filterSensors(key);
                    updateActiveButton(key);
                }, holdTime);
            }, { passive: true });

            btn.addEventListener('touchend', () => clearTimeout(timer));
            btn.addEventListener('touchcancel', () => clearTimeout(timer));
        });
    }

    applyStoredOrderAndFilter();
    enableLongPress();
});
</script>

<?php
$readingTime = strtotime($latest['reading_time']);
$now = time();
$diff = $now - $readingTime;
?>

<div class="value-row small">
    
<div class="value-row small">
    
        <?php
// mv-baserad ikon (endast mv styr ikonval). USB-texthantering bevaras som tidigare.
$mv = (isset($sensor['mv']) && is_numeric($sensor['mv'])) ? (int)$sensor['mv'] : null;
$mv_data = htmlspecialchars(json_encode($data_mv_7d), ENT_QUOTES); // popup-data (7d)
$no_popup_class = '';

// Om mv finns: använd endast mv för att välja ikon
if ($mv !== null) {
    if ($mv > 3600) {
        $icon = '/img/icon-bat100f.png';
    } elseif ($mv >= 3200) {
        $icon = '/img/icon-bat50f.png';
    } else {
        $icon = '/img/icon-bat0f.png';
    }
    $title = $mv . ' mV';
} else {
    // mv saknas -> fallback: behåll USB-detektion som tidigare
    $battery_raw = $sensor['battery'] ?? '';
    if (!empty($battery_raw) && stripos($battery_raw, 'usb') !== false) {
        $icon = '/img/icon-usb.png';
        $no_popup_class = ' no-popup';
        $title = 'USB';
    } else {
        // Okänt läge -> neutral ikon och visa råtext i tooltip
        $icon = '/img/icon-bat50f.png';
        $title = is_scalar($battery_raw) && $battery_raw !== '' ? $battery_raw : 'mV okänt';
    }
}

// Rendera img (samma struktur som du haft tidigare)
echo '<img src="' . htmlspecialchars($icon, ENT_QUOTES) . '"'
   . ' class="icontyp1 battery-icon' . $no_popup_class . '"'
   . ' data-sensor-id="' . htmlspecialchars($sensor['sensor_id'], ENT_QUOTES) . '"'
   . ' data-mv=\'' . $mv_data . '\''
   . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '">';



    // Tid till höger
    $readingTime = isset($latest['reading_time']) ? strtotime($latest['reading_time']) : null;
    $now = time();
    $diff = $readingTime ? ($now - $readingTime) : null;
    $formattedTime = ($diff !== null && $diff < 86400 && $readingTime) ? date('H:i', $readingTime) : ($readingTime ? date('Y-m-d H:i', $readingTime) : '');

    echo '<span style="margin-left:auto;">@' . $formattedTime . '</span>';
    ?>
</div>
</div>
            </div>
        <?php endforeach; ?>
        <?php include 'getweather.php'; ?>
            
<div class="footer-box">
<?php if (isset($_SESSION['user_id'])): ?>
  <!-- Profil (visas när inloggad) -->
  <a href="user_info.php" class="footer-circle-btn profile" title="Min profil">
    <img src="/img/icon-user.png" class="icontyp2" alt="Min profil">
  </a>

  <!-- Logga ut -->
  <a href="logout.php?logout=1" class="footer-circle-btn logout" title="Logga ut">
    <img src="/img/icon-logout.png" class="icontyp2" alt="Logga ut">
  </a>
<?php else: ?>
  <!-- Profilknapp leder till login om användaren inte är inloggad -->
  <a href="login.php" class="footer-circle-btn profile" title="Min profil">
    <img src="/img/icon-user.png" class="icontyp2" alt="Min profil">
  </a>

  <a href="login.php" class="footer-circle-btn login" title="Logga in">
    <img src="/img/icon-logout.png" class="icontyp2" alt="Logga in">
  </a>
<?php endif; ?>

  <button class="footer-circle-btn" id="restore-btn" title="Visa alla">
    <img src="/img/icon-listall.png" class="icontyp2" alt="Visa alla">
  </button>

  <a href="settings.php" class="footer-circle-btn" title="Inställningar">
    <img src="/img/icon-settings2.png" class="icontyp2" alt="Inställningar">
  </a>
</div>
    </div>
</main>
    
    
    <script>

document.addEventListener('DOMContentLoaded', function () {
    const popup = document.getElementById('mvPopup');
    const overlay = document.getElementById('popupOverlay');
    const closeBtn = document.getElementById('popupClose');
    let mvChart;

    // Öppna popup på batteri-klick
    document.querySelectorAll('.battery-icon:not(.no-popup)').forEach(img => {
        img.addEventListener('click', () => {
            const raw = img.getAttribute('data-mv');
            if (!raw) return;

            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                console.error("Ogiltig JSON i data-mv:", e);
                return;
            }

            const ctx = document.getElementById('mvChart')?.getContext('2d');
            if (!ctx) return;

            if (mvChart) mvChart.destroy();

            mvChart = new Chart(ctx, {
                type: 'line',
                data: {
                    datasets: [{
                        label: 'Batteri (mV)',
                        data: data,
                        borderColor: 'rgba(85,111,91,1)',
                        fill: false,
                        tension: 0.3
                    }]
                },
                options: {
                    parsing: { xAxisKey: 'x', yAxisKey: 'y' },
                    scales: {
                        x: {
                            type: 'time',
                            time: { unit: 'day' },
                            ticks: { font: { size: 10 } }
                        },
                        y: {
                            title: { display: true, text: 'mV' },
                            ticks: { precision: 0 }
                        }
                    },
                    plugins: { legend: { display: false } },
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            popup.style.display = 'block';
            overlay.style.display = 'block';
        });
    });

    function closePopup() {
        popup.style.display = 'none';
        overlay.style.display = 'none';
    }

    closeBtn.addEventListener('click', closePopup);
    overlay.addEventListener('click', closePopup);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePopup();
    });
});
</script>
   
</body>
</html>
