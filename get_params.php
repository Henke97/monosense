<?php
header('Content-Type: application/json');
include 'db.php';

// Läs och rensa sensor_mac
$sensor_mac_raw = $_GET['sensor_mac'] ?? '';

if (empty($sensor_mac_raw)) {
    http_response_code(400); // Bad Request
    echo json_encode(["error" => "sensor_mac saknas"]);
    exit;
}

// Ta bort kolon
$sensor_mac = str_replace(":", "", $sensor_mac_raw);

// === Dynamiskt bygg SELECT-query med alla cfg_-fält ===
$columnsRes = $con->query("SHOW COLUMNS FROM sensors");
$cfgFields = [];

while ($row = $columnsRes->fetch_assoc()) {
    $colName = $row['Field'];
    if (strpos($colName, 'cfg_') === 0) {
        $cfgFields[] = $colName;
    }
}

if (empty($cfgFields)) {
    http_response_code(500);
    echo json_encode(["error" => "Inga cfg_-fält hittades i tabellen"]);
    exit;
}

$fieldsSql = implode(",", $cfgFields);
$sql = "SELECT $fieldsSql FROM sensors WHERE sensor_mac = ?";

$stmt = $con->prepare($sql);
$stmt->bind_param("s", $sensor_mac);
$stmt->execute();

$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo json_encode(["error" => "Sensor med angiven MAC hittades inte"]);
}

$stmt->close();
$con->close();
?>
