<?php
require_once 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    if (empty($email)) {
        $error = "Ange din e-postadress.";
    } else {
        // Kontrollera om användaren finns
        $stmt = $con->prepare("SELECT user_id FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $error = "E-postadressen finns inte registrerad.";
            } else {
                $stmt->bind_result($user_id);
                $stmt->fetch();

                // Skapa nytt lösenord
                $new_password_plain = bin2hex(random_bytes(4)); // t.ex. 8 tecken
                $new_password_hashed = password_hash($new_password_plain, PASSWORD_DEFAULT);

                // Uppdatera databasen
                $upd = $con->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                if ($upd) {
                    $upd->bind_param('si', $new_password_hashed, $user_id);
                    $upd->execute();
                    $upd->close();
                }

                // Skicka e-post
                $subject = "Återställning av lösenord";
                $message = "Hej!\n\nDitt nya lösenord är: $new_password_plain\n\nLogga in och byt det omgående.\n\n/Monosense";
                $headers = "From: no-reply@monosense.se\r\nContent-Type: text/plain; charset=UTF-8";

                if (@mail($email, $subject, $message, $headers)) {
                    $success = "Ett nytt lösenord har skickats till din e-post.";
                } else {
                    $error = "Kunde inte skicka e-post.";
                }
            }

            $stmt->close();
        } else {
            $error = "Ett internt fel uppstod.";
        }
    }

    // Behåll samma beteende som tidigare: stäng anslutning om så önskas
    $con->close();
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <?php include 'head.php'; ?>
    <style>
      /* Matchande enkel styling med login.php */
      .logo { display:flex; justify-content:center; align-items:center; margin-bottom:0rem; }
      .logo img { max-height:60px; width:auto; margin-top:10px; }
      .sensor-container { max-width:420px; margin: 0 auto; padding:20px; background:#fff; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06); }
      input[type="email"], input[type="text"], input[type="password"] { width:100%; padding:10px; margin:6px 0 10px; border:1px solid #ddd; border-radius:4px; }
      button { width:100%; padding:10px; background:#4b7bec; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:1rem; }
      .info { color:#155724; background:#e6ffed; padding:10px; border-radius:4px; margin-bottom:10px; }
      .error { color:#721c24; background:#ffe6e9; padding:10px; border-radius:4px; margin-bottom:10px; }
      .auth-actions { display:flex; justify-content:flex-start; align-items:center; margin-top:12px; font-size:0.95rem; }
      a { color:#4b7bec; text-decoration:none; }
    </style>
</head>
<body>
    <div class="logo">
        <img src="img/logga3.png" alt="Monosense logo">
    </div>

    <h2 style="text-align:center">Monosense</h2>

    <div class="sensor-container">
        <h2>Återställ lösenord</h2>

        <?php if (!empty($error)) : ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!empty($success)) : ?>
            <div class="info"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="restore_password.php" novalidate>
            <input type="email" name="email" placeholder="Din e-postadress" required>
            <button type="submit">Återställ lösenord</button>
        </form>

        <div class="auth-actions">
          <div>
            <p><a href="login.php">Logga in här</a></p>
          </div>
        </div>
    </div>

    <script>
    // Om ett framgångsmeddelande finns, vänta en kort stund så användaren hinner se det och skicka sen till login.
    // Detta förändrar inte serverlogiken — endast klientbeteendet.
    (function() {
        const info = document.querySelector('.info');
        if (info) {
            // visa meddelandet en kort stund, därefter redirect till login
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
        }
    })();
    </script>
</body>
</html>
