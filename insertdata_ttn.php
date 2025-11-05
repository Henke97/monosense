<?php
// API to insert TTN webhook data directly into DB

include "db.php";

$api_key_value = "NfkoenKmg97m";

// --- Läs in inkommande JSON ---
$raw = file_get_contents("php://input");
$ttn = json_decode($raw, true);

// --- Debug-logg med maxstorlek 1 MB ---
$logfile = "ttn_debug_ttn.log";
if (file_exists($logfile) && filesize($logfile) > 1024 * 1024) {
    unlink($logfile); // börja om när >1MB
}
file_put_contents($logfile, date("c") . " " . $raw . "\n", FILE_APPEND);

// --- API-nyckelkontroll ---
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? ($ttn["api_key"] ?? null);
if (!$ttn || !isset($ttn["uplink_message"])) {
    http_response_code(400);
    die("Invalid TTN JSON");
}
if ($api_key !== $api_key_value) {
    http_response_code(403);
    die("Wrong API key");
}

// --- Metadata från TTN ---
$devEui   = $ttn["end_device_ids"]["dev_eui"]    ?? null;
$deviceId = $ttn["end_device_ids"]["device_id"]  ?? null;

$uplink   = $ttn["uplink_message"];
$decoded  = $uplink["decoded_payload"] ?? [];
$rxMeta   = $uplink["rx_metadata"][0] ?? [];

$receivedAt = $ttn["received_at"] ?? null;

// --- Sensorvärden ---
$temp   = isset($decoded["temp"]) ? (float)$decoded["temp"] : null;
$hum    = isset($decoded["hum"])  ? (float)$decoded["hum"]  : null;
$vbat   = isset($decoded["vbat"]) ? (float)$decoded["vbat"] : null;

// Batteri i mV
$vbat_mv = ($vbat !== null) ? (int) round($vbat * 1) : null;

// Power (USB eller batteriprocent)
$power_text = null;
$power_pct  = null;
if ($vbat !== null) {
    if ($vbat < 0.5) {
        $power_text = "USB";
    } else {
        $power_pct = min(100, max(0, round(($vbat_mv - 3200) / (4000 - 3200) * 100)));
    }
}

// --- Radio metadata ---
$rssi = $rxMeta["rssi"] ?? null;

// --- DB-koppling ---
if ($con->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $con->connect_error]);
    exit;
}

// --- Kolla om sensorn finns (identifiera via DevEUI) ---
$sensor_id = null;
$sensor_check = $con->prepare("SELECT sensor_id FROM sensors WHERE lora_deveui = ?");
$sensor_check->bind_param("s", $devEui);
$sensor_check->execute();
$sensor_check->bind_result($sensor_id);
$sensor_check->fetch();
$sensor_check->close();

if ($sensor_id) {
    // Uppdatera sensor info
    $update_sensor = $con->prepare(
        "UPDATE sensors 
         SET sensor_name = ?, sensor_type = 'LoRaWAN',
             lora_deviceid = COALESCE(?, lora_deviceid)
         WHERE sensor_id = ?"
    );
    $update_sensor->bind_param("ssi", $deviceId, $deviceId, $sensor_id);
    $update_sensor->execute();
    $update_sensor->close();
} else {
    // Skapa ny sensor
    $sensor_pin = generatePin();
    $create_sensor = $con->prepare(
        "INSERT INTO sensors 
          (sensor_name, sensor_type, sensor_mac, sensor_update, sensor_pin, firmware_version, lora_deveui, lora_deviceid) 
         VALUES (?, 'LoRaWAN', ?, 60, ?, '', ?, ?)"
    );
    $create_sensor->bind_param("ssiss", $deviceId, $devEui, $sensor_pin, $devEui, $deviceId);
    $create_sensor->execute();
    $sensor_id = $con->insert_id;
    $create_sensor->close();
}

// --- Infoga mätdata i sensor_data (value1..10) ---
// value1 = temp, value2 = hum, value4 = strömtyp (% eller USB), value5 = RSSI, value8 = batteri mV
$stmt = $con->prepare(
    "INSERT INTO sensor_data 
       (sensor_id, value1, value2, value4, value5, value6, value7, value8, value9, value10) 
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Prepare failed: " . $con->error]);
    exit;
}

$null = null;

// Om USB → spara text, annars procent
$power_val = ($power_text !== null) ? $power_text : $power_pct;

$stmt->bind_param(
    "idssdddddd",
    $sensor_id,
    $temp,          // value1 = temp
    $hum,           // value2 = hum
    $power_val,     // value4 = USB eller %
    $rssi,          // value5 = RSSI
    $null,          // value6 = NULL (ingen SNR)
    $null,          // value7 = NULL (hPa)
    $vbat_mv,       // value8 = mV
    $null,          // value9 = NULL (Lux)
    $null           // value10 = NULL (Soil moisture)
);

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(["message" => "OK"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Insert failed: " . $stmt->error]);
}

$stmt->close();
$con->close();

function generatePin() {
    return rand(1000, 9999);
}
?>
