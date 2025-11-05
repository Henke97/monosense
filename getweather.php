<?php
if (!isset($_SESSION)) session_start();
include_once 'db.php';

$apiKey = "e4a88c46a0c38131e256bc91f6690b68";
$lang = "sv";
$exclude = "minutely";
$user_id = intval($_SESSION['user_id']);

$defaultCity = "Stockholm";
$defaultLat = 59.3293;
$defaultLon = 18.0686;

$city = $defaultCity;
$lat = $defaultLat;
$lon = $defaultLon;

$res = $con->query("SELECT city, lat, lon FROM users WHERE user_id = $user_id");
if ($res && $row = $res->fetch_assoc()) {
    if (!empty($row['city'])) $city = $row['city'];
    if (!empty($row['lat'])) $lat = $row['lat'];
    if (!empty($row['lon'])) $lon = $row['lon'];
}

$url = "https://api.openweathermap.org/data/3.0/onecall?lat=$lat&lon=$lon&exclude=$exclude&units=metric&lang=$lang&appid=$apiKey";
$response = @file_get_contents($url);
$weather = [];

function getWeekdayShortSv($timestamp) {
    $days = ['Sön', 'Mån', 'Tis', 'Ons', 'Tors', 'Fre', 'Lör'];
    return $days[date('w', $timestamp)];
}

if ($response) {
    $data = json_decode($response, true);
    if (isset($data['daily'][0]) && isset($data['current'])) {
        $today = $data['daily'][0];

        $weather = [
            "icon" => $today['weather'][0]['icon'],
            "description" => ucfirst($today['weather'][0]['description']),
            
            "temp" => round($today['temp']['day']),
            "humidity" => $today['humidity'],
            "pressure" => $today['pressure'],
            "uvi" => round($today['uvi']),
            "sunrise" => date("H:i", $today['sunrise']),
            "sunset" => date("H:i", $today['sunset']),
            "dayLength" => floor(($today['sunset'] - $today['sunrise']) / 3600) . " h " .
                          floor((($today['sunset'] - $today['sunrise']) % 3600) / 60) . " min"
        ];
        $weather['city'] = $city;

        if (!empty($today['rain'])) {
            $weather['rain'] = round($today['rain'], 1) . " mm";
        }
        if (!empty($today['snow'])) {
            $weather['snow'] = round($today['snow'], 1) . " mm";
        }

        $weather['hourly'] = array_slice(array_map(function($h) {
            $precip = 0;
            if (!empty($h['rain']['1h'])) $precip += $h['rain']['1h'];
            if (!empty($h['snow']['1h'])) $precip += $h['snow']['1h'];
            return [
                'time' => date("H:i", $h['dt']),
                'temp' => round($h['temp']),
                'icon' => $h['weather'][0]['icon'],
                'precip' => $precip > 0 ? round($precip, 1) : 0
            ];
        }, $data['hourly']), 0, 12);

        $weather['daily3'] = array_slice(array_map(function($d) {
            $precip = 0;
            if (!empty($d['rain'])) $precip += $d['rain'];
            if (!empty($d['snow'])) $precip += $d['snow'];
            return [
                'date' => getWeekdayShortSv($d['dt']) . ' ' . date("j/n", $d['dt']),
                'temp_min' => round($d['temp']['min']),
                'temp_max' => round($d['temp']['max']),
                'icon' => $d['weather'][0]['icon'],
                'desc' => ucfirst($d['weather'][0]['description']),
                'precip' => $precip > 0 ? round($precip, 1) : 0
            ];
        }, $data['daily']), 1, 3);
    }
}
?>

<!-- Yttre box: data-sensor-id används av din befintliga JS för att spara dold status -->
<div class="sensor-box weather" data-sensor-id="weather">
    <div class="move-up-area">
        <span class="move-up-btn" title="Flytta överst" role="button">↑</span>
    </div>

    <!-- Hide-knapp: stoppar propagation så klick inte öppnar detaljer -->
    <span class="hide-btn" title="Dölj box" role="button" onclick="event.stopPropagation()">×</span>

    <?php if (!empty($weather) && isset($weather['temp'])): ?>
        <div class="sensor-values">
            <div class="value-row big2" style="width: 100%;">
                <div style="flex: 1; display: flex; justify-content: flex-end; align-items: center; gap: 0.3rem;">
                    <img src="https://monosense.se/owimg/<?= $weather['icon'] ?>.png" alt="ikon" style="height: 20px;">
                    <span><?= round($weather['temp']) ?> °</span>
                </div>
                <div class="sensor-separator"></div>
                <div style="flex: 1; display: flex; justify-content: flex-start; align-items: center;">
                    <span><?= $weather['humidity'] ?>%</span>
                </div>
            </div>

            <!-- TIMVIS -->
            <div class="weather-hourly">
                    <?php foreach ($weather['hourly'] as $h): ?>
                        <div class="weather-hour">
                            <div><?= $h['time'] ?></div>
                            <img src="https://monosense.se/owimg/<?= $h['icon'] ?>.png" style="height: 20px;">
                            <div><?= $h['temp'] ?>°</div>
                            <?php if ($h['precip'] > 0): ?>
                                <div class="weather-precip"><?= $h['precip'] ?> mm</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <div class="details" style="display: none;">
                <div class="value-row3"><?= $weather['city'] ?> idag:</div>
                <div class="value-row3"><?= $weather['description'] ?></div>
                <div class="value-row3">Soluppgång: <?= $weather['sunrise'] ?> – Solnedgång: <?= $weather['sunset'] ?></div>
                <div class="value-row3">Dagens längd: <?= $weather['dayLength'] ?></div>
                <div class="value-row3">Luftryck: <?= $weather['pressure'] ?> hPa</div>
                <div class="value-row3">UV-index: <?= $weather['uvi'] ?></div>
                <?php if (isset($weather['rain'])): ?>
                    <div class="value-row3">Regn: <?= $weather['rain'] ?></div>
                <?php endif; ?>
                <?php if (isset($weather['snow'])): ?>
                    <div class="value-row3">Snö: <?= $weather['snow'] ?></div>
                <?php endif; ?>

                <!-- 3 DAGAR -->
                    <div class="weather-daily-container">
                        <?php foreach ($weather['daily3'] as $d): ?>
                            <div class="weather-daily-row">
                                <div class="weather-daily-date"><?= $d['date'] ?></div>
                                <img class="weather-daily-icon" src="https://monosense.se/owimg/<?= $d['icon'] ?>.png">
                                <div class="weather-daily-desc"><?= $d['desc'] ?>
                                            <?php if ($d['precip'] > 0): ?>
                                                <span class="weather-precip-daily"> <?= $d['precip'] ?> mm</span>
                                            <?php endif; ?>
                                        </div>
                                        <div><?= $d['temp_max'] ?>°/<?= $d['temp_min'] ?>°</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <div class="value-row small" style="font-size: 0.75rem; color: #666;">
                    @ <?= date("H:i") ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="sensor-values">
            <div class="value-row">Väderdata kunde inte hämtas.</div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const box = document.querySelector('.sensor-box.weather');
    if (!box) return;

    const details = box.querySelector('.details');
    const moveArea = box.querySelector('.move-up-area');

    box.addEventListener('click', () => {
        if (!details) return;
        const visible = details.style.display === "block";
        details.style.display = visible ? "none" : "block";
    });

    if (moveArea) {
        moveArea.addEventListener('click', (event) => {
            event.stopPropagation();
            const grid = box.closest('.sensor-grid');
            if (grid) grid.prepend(box);
            const orderKey = 'sensorOrder';
            const current = JSON.parse(localStorage.getItem(orderKey)) || [];
            const thisId = box.dataset.sensorId;
            const newOrder = [thisId, ...current.filter(id => id !== thisId)];
            localStorage.setItem(orderKey, JSON.stringify(newOrder));
        });
    }
});
</script>
