<?php
session_start();
include 'db.php';
setlocale(LC_TIME, 'sv_SE.UTF-8', 'sv_SE', 'Swedish');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Standard hidden keys (kan utökas senare för demo)
$hidden_keys = ['password_hash', 'login_token', 'remember_me', 'two_factor_secret'];

// Skapa CSRF-token om saknas
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

// === Hjälp: hämta username före POST-hantering så vi kan blockera demo från att posta ===
$username_for_check = '';
$snu = $con->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1");
if ($snu) {
    $snu->bind_param('i', $user_id);
    $snu->execute();
    $resu = $snu->get_result();
    $rowu = $resu->fetch_assoc();
    $username_for_check = $rowu['username'] ?? '';
    $snu->close();
}

// Hantera enkel lösenordsändring (endast nytt lösenord)
$pass_msg = '';
$pass_msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password_simple') {

    // Blockera demo redan här (server-side)
    if ($username_for_check === 'demo') {
        $pass_msg = 'Demo-användaren kan inte ändra lösenord.';
        $pass_msg_type = 'error';
    } else {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            $pass_msg = 'Ogiltig CSRF-token.';
            $pass_msg_type = 'error';
        } else {
            $new = $_POST['new_password'] ?? '';

            if (trim($new) === '') {
                $pass_msg = 'Fyll i ett nytt lösenord.';
                $pass_msg_type = 'error';
            } elseif (strlen($new) < 8) {
                $pass_msg = 'Lösenordet måste vara minst 8 tecken.';
                $pass_msg_type = 'error';
            } else {
                $new_hash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $con->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                if ($upd) {
                    $upd->bind_param('si', $new_hash, $user_id);
                    if ($upd->execute()) {
                        $pass_msg = 'Lösenordet sparades.';
                        $pass_msg_type = 'success';
                        // Rotera CSRF-token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                    } else {
                        $pass_msg = 'Kunde inte uppdatera lösenordet.';
                        $pass_msg_type = 'error';
                    }
                    $upd->close();
                } else {
                    $pass_msg = 'Databasfel.';
                    $pass_msg_type = 'error';
                }
            }
        }
    }
}

// Hämta användardata (oförändrat)
$stmt = $con->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$user = [];
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc() ?: [];
    $stmt->close();
}

// --- Dölj vissa fält endast för demo-användaren ---
// Placera detta precis efter att $user har hämtats och stmt stängts.
if (isset($user['username']) && $user['username'] === 'demo') {
    // lägg till i hidden-keys så loopen inte visar dem
    $hidden_keys = array_merge($hidden_keys, ['email', 'start_page']);

    // ta bort från $user så värdena inte finns kvar i arrayen
    unset($user['email'], $user['start_page']);
}

/* --- övriga inställningar / visning som förr --- */
$field_labels = [
    'user_id'      => 'Användar-ID',
    'username'     => 'Användarnamn',
    'email'        => 'E-post',
    'full_name'    => 'Fullständigt namn',
    'display_name' => 'Visningsnamn',
    'created_at'   => 'Registrerad',
    'last_login'   => 'Senaste inloggning',
    'start_page'   => 'Startsida',
    'city'         => 'Ort',
    'lon'          => 'Longitud',
    'lat'          => 'Latitud',
];

function format_swedish_datetime($datetime_str) {
    if (!$datetime_str) return '';
    $ts = strtotime($datetime_str);
    return strftime('%e %B %Y %H:%M', $ts);
}

$preferred_order = ['user_id', 'username', 'email', 'full_name', 'display_name', 'created_at', 'last_login', 'start_page'];
$fields_to_show = [];

foreach ($preferred_order as $k) {
    if (array_key_exists($k, $user) && !in_array($k, $hidden_keys)) {
        $fields_to_show[$k] = $user[$k];
    }
}
foreach ($user as $k => $v) {
    if (in_array($k, $hidden_keys)) continue;
    if (in_array($k, $preferred_order)) continue;
    $fields_to_show[$k] = $v;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <?php include 'head.php'; ?>
    <meta charset="UTF-8">
    <title>Min profil — Monosense</title>
    <style>
        .msg { padding: 10px; border-radius: 4px; margin-bottom: 10px; }
        .msg.error { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .msg.success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .pw-form { margin-top: 12px; }
        .pw-form input[type="password"] { display:block; width:100%; padding:8px; margin:6px 0; box-sizing: border-box; }
        .pw-form button { margin-top:8px; padding:8px 12px; }
    </style>
</head>
<body>
    <div class="logo"><a href="index.php"><img src="img/logga3.png"></a></div>
    <h2>Monosense</h2>

    <main class="sensor-container">
        <div class="sensor-box settings">
            <h3>Min profil</h3>

            <?php if (empty($user)): ?>
                <div class="error">Kunde inte hämta din användardata.</div>
            <?php else: ?>

                <div class="small" style="margin-bottom:10px;">
                    Här visas den information som registrerats för ditt konto.
                </div>

                <?php foreach ($fields_to_show as $key => $val): 
                    $display = $val;
                    if (in_array($key, ['created_at','created','registered_at','last_login','updated_at'])) {
                        $display = format_swedish_datetime($val);
                    }
                    if (is_null($display)) $display = '';
                    elseif (is_bool($display)) $display = $display ? 'Ja' : 'Nej';
                    $display_trimmed = is_string($display) ? trim($display) : (string)$display;
                    if ($display_trimmed === '') continue;
                    $label = $field_labels[$key] ?? ucwords(str_replace(['_', '-'], [' ', ' '], $key));
                    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                    $labelNoBreak = str_replace(' ', '&nbsp;', $labelEsc);
                    $displayEsc = htmlspecialchars((string)$display, ENT_QUOTES, 'UTF-8');
                ?>
                    <div class="sensor-block-row">
                        <div class="sensor-values">
                            <label style="white-space:nowrap;"><?= $labelNoBreak ?></label>
                        </div>
                        <div class="value-row2">
                            <div class="value-row small"><?= $displayEsc ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>

               <hr style="margin:12px 0;">
<h4>Spara nytt lösenord</h4>

<?php if ($pass_msg !== ''): ?>
    <div class="msg <?= ($pass_msg_type === 'success') ? 'success' : 'error' ?>">
        <?= htmlspecialchars($pass_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if (($user['username'] ?? '') !== 'demo'): ?>
    <form method="post" class="pw-form" autocomplete="off" novalidate>
        <input type="hidden" name="action" value="change_password_simple">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="new_password">Nytt lösenord (minst 8 tecken)</label>
        <input id="new_password" name="new_password" type="password" required autocomplete="new-password">

        <button type="submit">Spara nytt lösenord</button>
    </form>
<?php else: ?>
    <div class="msg error">Demo-användaren kan inte ändra lösenord.</div>
<?php endif; ?>


            <?php endif; ?>
        </div>

        <div style="margin-top:12px;">
            <a href="index.php">← Tillbaka</a>
        </div>
    </main>
</body>
</html>
