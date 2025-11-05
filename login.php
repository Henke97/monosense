<?php
session_start();
require_once 'db.php';

// Kolla om det är AJAX-begäran (från JS)
$is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
if ($is_ajax) {
    header("Content-Type: application/json; charset=utf-8");
}

// Autoinloggning via remember_me-cookie
if (isset($_COOKIE['remember_me']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = intval($_COOKIE['remember_me']);

    if ($is_ajax) {
        echo json_encode(['status' => 'already_logged_in']);
        exit;
    } else {
        header("Location: index.php");
        exit;
    }
}

// Inloggning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $con->real_escape_string($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);

    // Hämta användare + start_page
    $query = "SELECT user_id, password_hash, start_page 
              FROM users 
              WHERE username = '$username' 
              LIMIT 1";
    $result = $con->query($query);

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];

            if ($remember) {
                setcookie('remember_me', $user['user_id'], time() + (86400 * 30), "/");
            }

            // Skapa login_token
            $token = bin2hex(random_bytes(32));
            $con->query("UPDATE users SET login_token = '$token' WHERE user_id = {$user['user_id']}");

            // Hämta startsida från DB (fallback index.php)
            $target = $user['start_page'] ?: 'index.php';

            if ($is_ajax) {
                echo json_encode([
                    'status' => 'success',
                    'user_id' => $user['user_id'],
                    'token' => $token,
                    'redirect' => $target
                ]);
                exit;
            } else {
                header("Location: $target");
                exit;
            }
        } else {
            $error = "Fel lösenord.";
        }
    } else {
        $error = "Användaren hittades inte.";
    }

    if ($is_ajax) {
        echo json_encode(['status' => 'error', 'message' => $error]);
        exit;
    }
}

// Visuell checkbox om cookie finns
$remember_checked = isset($_COOKIE['remember_me']) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <?php include 'head.php'; ?>
    <style>
      /* Enkel styling så länken ligger snyggt */
      .auth-actions { display:flex; justify-content:space-between; align-items:center; margin-top:8px; }
      .auth-actions .left { font-size:0.95rem; }
      .auth-actions .right { font-size:0.95rem; }
      .forgot-link { margin-left:10px; font-size:0.95rem; }
      .error { color: #b00020; background:#fee; padding:8px; border-radius:4px; margin-bottom:8px; }
    </style>
</head>
<body>
    <div class="logo">
        <img src="img/logga3.png" alt="Monosense logo">
    </div>
    <h2>Monosense</h2>
    <div class="sensor-container">
        <h2>Logga in</h2><br>
        <?php if (isset($error)) echo "<div class='error'>" . htmlspecialchars($error) . "</div>"; ?>
        <form id="loginForm" method="post">
            <input type="text" name="username" id="username" placeholder="Användarnamn" required><br>
            <input type="password" name="password" placeholder="Lösenord" required><br>
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="remember_me" <?= $remember_checked ?>> Kom ihåg mig
                </label>
            </div>
            <button type="submit">Logga in</button>
        </form>

        <div class="auth-actions">
          <div class="left">
            <p>Har du inget konto? <a href="register.php">Skapa konto här</a></p>
          </div>
          <div class="right">
            <!-- Glömt lösenord-länk -->
            <a href="restore_password.php" id="forgotLink" class="forgot-link" aria-label="Glömt lösenord">Glömt lösenord?</a>
          </div>
        </div>
    </div>

    <script>
    // Kör som AJAX i PWA-läge
    document.addEventListener("DOMContentLoaded", () => {
        const isPWA = window.matchMedia('(display-mode: standalone)').matches 
                   || window.navigator.standalone === true;

        const form = document.getElementById('loginForm');
        form.addEventListener('submit', e => {
            // Om vi är i PWA, skickas som AJAX
            if (isPWA) {
                e.preventDefault();
                const formData = new FormData(form);
                formData.append('ajax', '1');

                fetch('login.php', {
                    method: 'POST',
                    body: new URLSearchParams(formData),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        localStorage.setItem('login_token', data.token);
                        window.location.href = data.redirect || 'index.php';
                    } else if (data.status === 'already_logged_in') {
                        window.location.href = 'index.php';
                    } else {
                        alert(data.message || "Inloggning misslyckades.");
                    }
                })
                .catch(err => alert("Nätverksfel: " + err));
            }
            // annars låt formuläret skicka normalt (icke-PWA)
        });

        // Hantera "Glömt lösenord?"-länken
        const forgotLink = document.getElementById('forgotLink');
        forgotLink.addEventListener('click', function(e) {
            // Förifyll användarnamn som query-param om fältet har värde
            const usernameInput = document.getElementById('username').value.trim();
            let href = this.getAttribute('href') || 'restore_password.php';

            if (usernameInput.length > 0) {
                // Bifoga som query-param för att förifylla restore-form (servern bestämmer)
                // Ex: restore_password.php?user=användarnamn
                href = href.split('?')[0] + '?user=' + encodeURIComponent(usernameInput);
            }

            // Navigera normalt men med korrekt beteende i PWA standalone-läge
            if (isPWA) {
                e.preventDefault();
                window.location.href = href;
            }
            // I vanliga webbläsaren gör vi ingenting och låter länken följa href
        });
    });
    </script>
</body>
</html>
