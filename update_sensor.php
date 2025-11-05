<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$sensor_id = isset($_POST['sensor_id']) ? intval($_POST['sensor_id']) : 0;
if ($sensor_id <= 0) {
    header('Location: settings.php');
    exit;
}

// Kontrollera ägarskap
$chk = $con->prepare("SELECT user_id FROM sensors WHERE sensor_id = ?");
$chk->bind_param("i", $sensor_id);
$chk->execute();
$chk_res = $chk->get_result();
if ($chk_res->num_rows === 0) {
    $chk->close();
    header('Location: settings.php');
    exit;
}
$row = $chk_res->fetch_assoc();
$chk->close();
if (intval($row['user_id']) !== $user_id) {
    header('Location: settings.php');
    exit;
}

// Bygg dynamisk update beroende på vilka fält som skickats
$fields = [];
$types = '';
$params = [];

// given_name
if (isset($_POST['given_name'])) {
    $given_name = trim($_POST['given_name']);
    $fields[] = 'sensor_givenname = ?';
    $types .= 's';
    $params[] = $given_name;
}

// interval
if (isset($_POST['interval'])) {
    $interval = intval($_POST['interval']);
    if ($interval < 1) $interval = 1;
    $fields[] = 'cfg_interval = ?';
    $types .= 'i';
    $params[] = $interval;
}

// location
if (isset($_POST['location'])) {
    $location = $_POST['location'];
    $allowed = ['in','out','gh'];
    if (!in_array($location, $allowed)) $location = 'in';
    $fields[] = 'sensor_location = ?';
    $types .= 's';
    $params[] = $location;
}


// temp_calibration (validera -5 -> 5, spara som int)
if (isset($_POST['temp_calibration'])) {
    $cal_raw = str_replace(',', '.', $_POST['temp_calibration']);
    // runda/konvertera till heltal
    $cal = intval(round(floatval($cal_raw), 0));
    if ($cal < -5) $cal = -5;
    if ($cal > 5) $cal = 5;
    $fields[] = 'temp_calibration = ?';
    $types .= 'i'; // integer
    $params[] = $cal;
}


if (count($fields) === 0) {
    // inget att uppdatera
    header('Location: settings.php');
    exit;
}

// Bygg och kör prepared statement
$sql = "UPDATE sensors SET " . implode(', ', $fields) . " WHERE sensor_id = ?";
$types .= 'i';
$params[] = $sensor_id;

$stmt = $con->prepare($sql);
if ($stmt === false) {
    header('Location: settings.php');
    exit;
}

// bind_param kräver references
$bind_names = [];
$bind_names[] = $types;
for ($i = 0; $i < count($params); $i++) {
    ${"param$i"} = $params[$i];
    $bind_names[] = &${"param$i"};
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

$ok = $stmt->execute();
$stmt->close();

// Redirect tillbaka med saved-flagga
header('Location: settings.php?saved=1');
exit;
