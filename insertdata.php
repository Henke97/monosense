<?php
// API to insert data into DB from ESP devices

include "db.php";

$api_key_value = "xxxxx";

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    $_POST = $_GET; // För test
}

// Initiera variabler
$api_key = $sensor_mac = $sensor_typ = $sensor_name = $firmware_version = "";
$sensor_update = 60; // Standardintervall
$value1 = $value2 = $value3 = $value4 = $value5 = $value6 = $value7 = $value8 = $value9 = $value10 = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $api_key = test_input($_POST["api_key"] ?? "");

    if ($api_key === $api_key_value) {
        $sensor_mac_raw = test_input($_POST["sensor_mac"] ?? "");
        $sensor_mac = str_replace(":", "", $sensor_mac_raw);

        $sensor_typ = test_input($_POST["sensor_type"] ?? "");
        $sensor_name = test_input($_POST["sensor_name"] ?? ("MonoS " . substr($sensor_mac, -4)));
        $firmware_version = test_input($_POST["firmware_version"] ?? "");
        $sensor_update = test_input($_POST["sensor_update"] ?? $sensor_update);

        $value1 = $_POST["value1"] ?? null;
        $value2 = $_POST["value2"] ?? null;
        $value3 = $_POST["value3"] ?? null;
        $value4 = $_POST["value4"] ?? null;
        $value5 = $_POST["value5"] ?? null;
        $value6 = $_POST["value6"] ?? null;
        $value7 = $_POST["value7"] ?? null;
        $value8 = $_POST["value8"] ?? null;
        $value9 = $_POST["value9"] ?? null;
        $value10 = $_POST["value10"] ?? null;

        if ($con->connect_error) {
            http_response_code(500);
            echo json_encode(["error" => "Database connection failed: " . $con->connect_error]);
            exit;
        }

        // Kontrollera om sensorn finns
        $sensor_check = $con->prepare("SELECT sensor_id FROM sensors WHERE sensor_mac = ?");
        $sensor_check->bind_param("s", $sensor_mac);
        $sensor_check->execute();
        $sensor_check->bind_result($sensor_id);
        $sensor_check->fetch();
        $sensor_check->close();

        if ($sensor_id) {
            // Uppdatera befintlig sensor
            $update_sensor = $con->prepare(
                "UPDATE sensors SET sensor_name = ?, sensor_type = ?, sensor_update = ?, firmware_version = ? WHERE sensor_id = ?"
            );
            $update_sensor->bind_param("ssssi", $sensor_name, $sensor_typ, $sensor_update, $firmware_version, $sensor_id);
            if (!$update_sensor->execute()) {
                http_response_code(500);
                echo json_encode(["error" => "Failed to update sensor: " . $update_sensor->error]);
                exit;
            }
            $update_sensor->close();
        } else {
            // Skapa ny sensor
            $sensor_pin = generatePin();
            $create_sensor = $con->prepare(
                "INSERT INTO sensors (sensor_name, sensor_type, sensor_mac, sensor_update, sensor_pin, firmware_version) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $create_sensor->bind_param("sssiss", $sensor_name, $sensor_typ, $sensor_mac, $sensor_update, $sensor_pin, $firmware_version);
            if (!$create_sensor->execute()) {
                http_response_code(500);
                echo json_encode(["error" => "Failed to create sensor: " . $create_sensor->error]);
                exit;
            }
            $sensor_id = $con->insert_id;
            $create_sensor->close();
        }

        // Infoga mätdata
        $stmt = $con->prepare(
            "INSERT INTO sensor_data (sensor_id, value1, value2, value3, value4, value5, value6, value7, value8, value9, value10) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["error" => "Prepare failed: " . $con->error]);
            exit;
        }

        $stmt->bind_param(
            "idddssssddd",
            $sensor_id,
            $value1,
            $value2,
            $value3,
            $value4,
            $value5,
            $value6,
            $value7,
            $value8,
            $value9,
            $value10
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
    } else {
        http_response_code(403);
        echo json_encode(["error" => "Wrong API key"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["error" => "No data posted"]);
}

// Sanera data
function test_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Generera PIN
function generatePin() {
    return rand(1000, 9999);
}

