<?php
// Inkludera databasanslutning
include "db.php";

// Definiera den tillåtna API-nyckeln
$allowed_api_key = 'xxxxx';

// Kontrollera om API-nyckel finns i headern och matchar den tillåtna nyckeln
$headers = getallheaders();
if (!isset($headers['org']) || $headers['org'] !== $allowed_api_key) {
    http_response_code(403); // Förbjuden begäran
    die("Invalid request");
}

// Hämta JSON-payload från webhook
$payload = file_get_contents('php://input');

// Avkoda JSON-data
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    die("Invalid JSON input");
}

// Mappa inkommande JSON-data till motsvarande databasfält
$sensor_mac = $data["deviceSerialNumber"] ?? null;  // sensormac
$sensor_name = $data["measurementPointName"] ?? null;  // sensorname
$sensor_type = "HC5";  // Sätts alltid till HC5
$signalStrength = $data["signalStrength"] ?? null;  // value5
$batteryStatus = $data["batteryStatus"] ?? null;  // value4

// Om något av de obligatoriska fälten saknas, returnera fel
if (!$sensor_mac || !$sensor_name || !$signalStrength || !$batteryStatus) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    exit;
}

// Kontrollera om sensorn redan finns i `sensors`-tabellen
$sensor_check = $con->prepare("SELECT sensor_id FROM sensors WHERE sensor_mac = ?");
$sensor_check->bind_param("s", $sensor_mac);
$sensor_check->execute();
$sensor_check->bind_result($sensor_id);
$sensor_check->fetch();
$sensor_check->close();

if (!$sensor_id) {
    // Generera en slumpmässig PIN-kod för den nya sensorn
    $sensor_pin = generatePin();

    // Skapa ny sensor om den inte finns
    $sensor_insert = $con->prepare(
        "INSERT INTO sensors (sensor_mac, sensor_name, sensor_type, sensor_pin) VALUES (?, ?, ?, ?)"
    );
    $sensor_insert->bind_param("sssi", $sensor_mac, $sensor_name, $sensor_type, $sensor_pin);
    if (!$sensor_insert->execute()) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to create sensor: " . $sensor_insert->error]);
        exit;
    }
    $sensor_id = $con->insert_id;
    $sensor_insert->close();
    
     
}



// Iterera över `measurementsEvents` och lagra data
foreach ($data["measurementsEvents"] as $measurementEvent) {
    foreach ($measurementEvent["events"] as $event) {
        $timestamp = $event["timestamp"];
        $value = $event["value"];
        $status = $event["status"];
        
 // Lägg till +2 timmar på tidsstämpeln
$dateTime = new DateTime($timestamp);
$dateTime->modify('+2 hours');
$timestamp = $dateTime->format('Y-m-d H:i:s');

// Förbered SQL där value10 explicit blir NULL
$stmt = $con->prepare("
    INSERT INTO sensor_data (sensor_id, value1, value4, value5, reading_time, value10)
    VALUES (?, ?, ?, ?, ?, NULL)
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to prepare statement: " . $con->error]);
    exit;
}

// Bind 5 parametrar (sensor_id, value1, battery, signal, timestamp)
$stmt->bind_param(
    "idsss",
    $sensor_id,
    $value,
    $batteryStatus,
    $signalStrength,
    $timestamp
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Error: " . $stmt->error]);
}

$stmt->close();

    }
}

$con->close();
http_response_code(200);
echo json_encode(["message" => "Data saved successfully"]);

// Funktion för att generera en slumpmässig 4-siffrig PIN-kod
function generatePin() {
    return rand(1000, 9999);
}
?>

