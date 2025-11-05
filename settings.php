<?php
session_start();
require_once 'db.php';
setlocale(LC_TIME, 'sv_SE.UTF-8', 'sv_SE', 'Swedish');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// --- CSRF token för enkla POST-åtgärder ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

// Visa meddelande om borttagning (redirect från delete_sensor_data.php)
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $deletedSensorName = isset($_GET['sensor_name']) ? htmlspecialchars($_GET['sensor_name']) : '';
    echo '<div id="deleted-msg" style="background: #fff3cd; color: #856404; padding: 0.75rem; margin: 1rem auto; max-width: 600px; border: 1px solid #ffeeba; border-radius: 5px; text-align:center;">';
    echo '🗑️ All data för sensorn ' . ($deletedSensorName !== '' ? "<strong>$deletedSensorName</strong>" : ' (okänd) ') . ' har raderats.';
    echo '</div>';
    echo '<script>setTimeout(()=>{const e=document.getElementById("deleted-msg"); if(e) e.remove(); const u=new URL(window.location); u.searchParams.delete("deleted"); u.searchParams.delete("sensor_name"); window.history.replaceState({}, document.title, u.toString());},4000);</script>';
}


// Hämta sensorer för användaren
$sensors = [];
$stmt = $con->prepare("SELECT sensor_id, sensor_name, sensor_givenname, sensor_location, cfg_interval, firmware_version, temp_calibration FROM sensors WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($sensor = $res->fetch_assoc()) {
    // Hämta senaste sensor_data för denna sensor
    $sd_stmt = $con->prepare("
        SELECT value1 AS raw_temp, value4 AS battery, value5 AS wifi, value8 AS mv, value9 AS ldr, reading_time
        FROM sensor_data
        WHERE sensor_id = ?
        ORDER BY reading_time DESC
        LIMIT 1
    ");
    $sd_stmt->bind_param("i", $sensor['sensor_id']);
    $sd_stmt->execute();
    $sd = $sd_stmt->get_result()->fetch_assoc();
    $sd_stmt->close();

    $sensor['battery'] = $sd['battery'] ?? '';
    $sensor['wifi']    = $sd['wifi'] ?? '';
    $sensor['mv']      = $sd['mv'] ?? '';
    $sensor['ldr']     = $sd['ldr'] ?? '';
    $sensor['reading_time'] = $sd['reading_time'] ?? '';

    $rawTemp = isset($sd['raw_temp']) && is_numeric($sd['raw_temp']) ? floatval($sd['raw_temp']) : null;
    $cal     = isset($sensor['temp_calibration']) ? floatval($sensor['temp_calibration']) : 0.0;
    if ($rawTemp !== null) {
        $sensor['raw_temp'] = round($rawTemp, 2);
        $sensor['calibrated_temp'] = round($rawTemp + $cal, 2);
    } else {
        $sensor['raw_temp'] = null;
        $sensor['calibrated_temp'] = null;
    }

    $sensors[] = $sensor;
}

function format_swedish_datetime($datetime_str) {
    if (!$datetime_str) return '';
    $ts = strtotime($datetime_str);
    return strftime('%e %B %Y %H:%M', $ts);
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <?php include 'head.php'; ?>
    <meta charset="utf-8">
    <title>Inställningar — Monosense</title>

    <!-- Minimal slider/CSS för att integrera med befintlig style.css.
         Vi ändrar INTE din style.css, detta bara kompletterar sliderns layout. -->
    <style>
    .tempCalSlider { width: 100%; }
    .calibration-row { display:flex; gap:12px; align-items:center; margin-top:6px; }
    .cal-value { min-width:70px; text-align:center; font-weight:700; }
    .range-ticks { display:flex; justify-content:space-between; font-size:0.8rem; color:#777; margin-top:6px; max-width:420px; }
    @media (max-width:720px) {
      .calibration-row { flex-direction:column; align-items:stretch; }
    }
    </style>
</head>
<body>

<div class="logo">
        <a href="index.php"><img src="img/logga3.png"></a>
    </div>
    <h2>Monosense</h2>

<main class="sensor-container">
   

<?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
    <div id="saved-msg" style="background: #d4edda; color: #155724; padding: 0.75rem; margin: 1rem auto; max-width: 600px; border: 1px solid #c3e6cb; border-radius: 5px; text-align: center;">
        ✅ Inställningar sparade
    </div>
    <script>
        setTimeout(() => {
            const msg = document.getElementById("saved-msg");
            if (msg) msg.remove();
            const url = new URL(window.location);
            url.searchParams.delete("saved");
            window.history.replaceState({}, document.title, url.toString());
        }, 3000);
    </script>
<?php endif; ?>

<?php foreach ($sensors as $row): ?>
<form method="post" action="update_sensor.php" class="sensor-box settings" autocomplete="off">
    <h3><?= htmlspecialchars($row['sensor_givenname'] ?: $row['sensor_name']) ?></h3>

    <label>Placering</label>
    <div class="radio-group">
        <label><input type="radio" name="location" value="in" <?= $row['sensor_location'] === 'in' ? 'checked' : '' ?> disabled> Inomhus</label>
        <label><input type="radio" name="location" value="out" <?= $row['sensor_location'] === 'out' ? 'checked' : '' ?> disabled> Utomhus</label>
        <label><input type="radio" name="location" value="gh" <?= $row['sensor_location'] === 'gh' ? 'checked' : '' ?> disabled> Växthus</label>
    </div>

    <label>Visningsnamn</label>
    <input type="text" name="given_name" value="<?= htmlspecialchars($row['sensor_givenname']) ?>" disabled class="readonly">

    <label>Intervall (min)</label>
    <input type="number" name="interval" value="<?= htmlspecialchars($row['cfg_interval'] ?? '') ?>" disabled class="readonly" min="1">

    <!-- Temperaturkalibrering -->
        <label>Temperaturkalibrering (°C)</label>
    <div class="calibration-row">
        <div style="flex:1; max-width:420px;">
            <input
                type="range"
                class="tempCalSlider"
                name="temp_calibration"
                min="-5"
                max="5"
                step="1"
                value="<?= number_format(floatval($row['temp_calibration'] ?? 0), 0, '.', '') ?>"
                disabled
            >
            <div class="range-ticks"><span>-5</span><span>0</span><span>+5</span></div>
        </div>

        <div class="cal-value">
            <span class="displayVal"><?= number_format(floatval($row['temp_calibration'] ?? 0), 0, '.', '') ?></span>°C
        </div>
    </div>


    <div class="status-line">
        <div>Wifi/Lora: <?= is_numeric($row['wifi']) ? ($row['wifi'] >= 0 ? htmlspecialchars($row['wifi'] . ' %') : htmlspecialchars($row['wifi'] . ' dBm')) : htmlspecialchars($row['wifi']) ?></div>
        <div>Batteri: <?= is_numeric($row['battery']) ? htmlspecialchars($row['battery'] . ' %') : htmlspecialchars($row['battery']) ?> <?= htmlspecialchars($row['mv']) ?></div>
        <?php if (!empty($row['ldr'])): ?><div>Ljus: <?= htmlspecialchars($row['ldr']) ?></div><?php endif; ?>
        <div>Senaste mätningen: <?= htmlspecialchars(format_swedish_datetime($row['reading_time'])) ?></div>
        <div>Firmware: <?= htmlspecialchars($row['firmware_version'] ?? '') ?></div>
        <div>Sensor-namn: <?= htmlspecialchars($row['sensor_name'] ?? '') ?></div>
        <div>Sensor ID: <?= htmlspecialchars($row['sensor_id']) ?></div>
        <div style="min-width:140px;">
            Temperatur: <?= $row['calibrated_temp'] !== null ? htmlspecialchars($row['calibrated_temp']) . ' °C' : '—' ?>
            <div style="font-size:0.85rem;color:#666">(rådata: <?= $row['raw_temp'] !== null ? htmlspecialchars($row['raw_temp']) . ' °C' : '—' ?>)</div>
        </div>
    </div>

    <input type="hidden" name="sensor_id" value="<?= htmlspecialchars($row['sensor_id']) ?>">

   
<div class="btn-row">
    <form method="post" action="delete_sensor_data.php" class="delete-data-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="sensor_id" value="<?= htmlspecialchars($row['sensor_id']) ?>">
        <input type="hidden" name="sensor_name" value="<?= htmlspecialchars($row['sensor_givenname'] ?: $row['sensor_name']) ?>">

     


    </form>

    <button type="button" class="btn-edit" onclick="toggleEdit(this)">Ändra</button>
</div>


</form>
<?php endforeach; ?>

    <?php include 'user_city_settings.php'; ?>

</main>

<script>
/*
  Komplett skript för:
  - toggleEdit(button) (aktiverar inputs och visar delete-knapp för aktuell form)
  - openDeletePopup(button) (öppnar befintlig popup-overlay/.popup eller fallback confirm)
  - hanterar bind/unbind av event så inga dubbletter uppstår
  - enkel HTML-escaping / säker hantering av sensor-namn
*/
(function() {
  'use strict';

  // Gör toggleEdit global så din onclick="toggleEdit(this)" fungerar som tidigare
  window.toggleEdit = function(button) {
    const form = button && button.closest ? button.closest('form') : null;
    if (!form) return;

    const inputs = form.querySelectorAll('input:not([type=hidden])');
    const slider = form.querySelector('.tempCalSlider');
    const disp = form.querySelector('.displayVal');
    const deleteBtn = form.querySelector('.delete-data-btn');

    if (button.textContent.trim() === 'Ändra') {
      // Aktivera inputs
      inputs.forEach(i => { i.disabled = false; i.classList.remove('readonly'); });

      // Slider: visa heltal och bind event
      if (slider && disp) {
        disp.textContent = Number(slider.value).toFixed(0);
        slider.oninput = function (e) { disp.textContent = Number(e.target.value).toFixed(0); };
      }

      // Visa och aktivera delete-knappen för just denna form
      if (deleteBtn) {
        deleteBtn.disabled = false;
        deleteBtn.removeAttribute('aria-disabled');
        deleteBtn.removeAttribute('hidden');

        // bind event en gång per knapp (spara handlerreferens så vi kan unbinda senare)
        if (!deleteBtn.__ms_delete_handler) {
          const handler = function(ev){ ev.preventDefault(); openDeletePopup(deleteBtn); };
          deleteBtn.addEventListener('click', handler);
          deleteBtn.__ms_delete_handler = handler;
        }
      }

      // byt knapptext till Spara
      button.textContent = 'Spara';
      button.classList.remove('btn-edit');
      button.classList.add('btn-save');
      return;
    }

    // Save-läget: säkerställ heltal för slider (om finns)
    if (slider) {
      let val = parseInt(slider.value, 10);
      if (isNaN(val)) val = 0;
      val = Math.max(-5, Math.min(5, val));
      slider.value = val;
    }

    // innan submit: göm och ta bort listener från delete-knappen för att återställa
    if (deleteBtn) {
      deleteBtn.disabled = true;
      deleteBtn.setAttribute('aria-disabled', 'true');
      deleteBtn.setAttribute('hidden', '');

      // ta bort event listener om satt
      if (deleteBtn.__ms_delete_handler) {
        deleteBtn.removeEventListener('click', deleteBtn.__ms_delete_handler);
        deleteBtn.__ms_delete_handler = null;
      }
    }

    // ge UI-feedback och submit
    button.disabled = true;
    button.textContent = 'Sparar...';
    form.submit();
  };

  // Öppnar popup som återanvänder .popup-overlay/.popup om tillgänglig, annars fallback confirm
  function openDeletePopup(button) {
    const form = button && button.closest ? button.closest('form') : null;
    if (!form) return;
    const sensorNameInput = form.querySelector('input[name="sensor_name"]');
    const sensorName = sensorNameInput ? sensorNameInput.value : 'denna sensor';

    const overlay = document.querySelector('.popup-overlay');
    const popup = overlay ? overlay.querySelector('.popup') : null;

    // Helper: skapa element utan att använda innerHTML för namntext (säker)
    function createWrapper() {
      const wrapper = document.createElement('div');
      wrapper.className = 'ms-delete-wrapper';
      // title/text
      const titleDiv = document.createElement('div');
      titleDiv.style.marginBottom = '8px';
      const strong = document.createElement('strong');
      strong.textContent = 'Radera all data för:';
      titleDiv.appendChild(strong);
      const nameDiv = document.createElement('div');
      nameDiv.style.marginTop = '6px';
      nameDiv.textContent = sensorName;
      titleDiv.appendChild(nameDiv);
      wrapper.appendChild(titleDiv);

      // actions
      const actions = document.createElement('div');
      actions.style.display = 'flex';
      actions.style.gap = '8px';
      actions.style.justifyContent = 'flex-end';
      // Avbryt
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'ms-delete-cancel';
      cancelBtn.textContent = 'Avbryt';
      // Bekräfta
      const confirmBtn = document.createElement('button');
      confirmBtn.type = 'button';
      confirmBtn.className = 'ms-delete-confirm';
      confirmBtn.textContent = 'Radera';

      actions.appendChild(cancelBtn);
      actions.appendChild(confirmBtn);
      wrapper.appendChild(actions);

      return { wrapper, cancelBtn, confirmBtn };
    }

    if (overlay && popup) {
      // ta bort tidigare wrapper om existerar
      const prev = popup.querySelector('.ms-delete-wrapper');
      if (prev) prev.remove();

      const { wrapper, cancelBtn, confirmBtn } = createWrapper();
      popup.appendChild(wrapper);

      // visa overlay (behåll din CSS-princip; här sätter vi så att den syns)
      // Om din overlay redan visas via CSS (t.ex. display:flex när en klass finns), detta är benign
      overlay.style.display = overlay.style.display && overlay.style.display !== 'none' ? overlay.style.display : 'block';

      // cancel: ta bort wrapper och försök gömma overlay om popup blir tom
      const cleanup = () => {
        if (wrapper && wrapper.parentNode) wrapper.remove();
        // göm overlay endast om popup saknar innehåll (mycket försiktig test)
        if (popup && popup.children.length === 0) {
          overlay.style.display = 'none';
        }
      };

      cancelBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        cleanup();
      });

      // klick utanför popup (overlay) ska stänga (enda om overlay används)
      const onOverlayClick = function(ev) {
        if (ev.target === overlay) {
          cleanup();
          overlay.removeEventListener('click', onOverlayClick);
        }
      };
      overlay.addEventListener('click', onOverlayClick);

      confirmBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Raderar...';
        // submit form (POST)
        form.submit();
      });

      // flytta fokus till Avbryt för keyboard-användare
      setTimeout(() => { try { cancelBtn.focus(); } catch(e){ /* ignore */ } }, 50);
      return;
    }

    // fallback: enkel browser-confirm
    if (window.confirm(`Är du säker på att du vill radera ALL data för "${sensorName}"? Detta går inte att ångra.`)) {
      form.submit();
    }
  }

  // Bind event to any delete buttons that accidentally are visible on load (defensive)
  function bindVisibleDeleteButtons() {
    document.querySelectorAll('.delete-data-btn:not([hidden])').forEach(btn => {
      if (!btn.__ms_delete_handler) {
        btn.addEventListener('click', function(ev){ ev.preventDefault(); openDeletePopup(btn); });
        btn.__ms_delete_handler = true;
      }
    });
  }

  // Kör bind på DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindVisibleDeleteButtons);
  } else {
    bindVisibleDeleteButtons();
  }

  // Export function for potential manual enabling (optional)
  window.msOpenDeletePopup = openDeletePopup;

})();
</script>




</body>
</html>
