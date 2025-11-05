<!-- head.php -->
<meta charset="UTF-8">
<title>Monosense</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- PWA & iOS installbarhet -->
<meta name="theme-color" content="#f2f1e7">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Monosense">

<!-- Ikoner & manifest -->
<link rel="apple-touch-icon" href="/img/android-192.png">
<link rel="manifest" href="manifest.json">

<!-- Typsnitt -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jura:wght@300..700&family=Oxanium:wght@200..800&display=swap" rel="stylesheet">

<!-- CSS -->
<link rel="stylesheet" href="style.css?v=2.7">

<!-- PWA Script -->
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js')
      .then(reg => console.log("Service Worker registrerad:", reg.scope))
      .catch(err => console.error("SW-fel:", err));
  }

  document.addEventListener("DOMContentLoaded", () => {
    const token = localStorage.getItem('login_token');

    if (token && !sessionStorage.getItem('already_sent_token') && !window.location.search.includes('token=')) {
      sessionStorage.setItem('already_sent_token', '1');
      const url = new URL(window.location.href);
      url.searchParams.set('token', token);
      window.location.href = url.toString();
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('token')) {
      urlParams.delete('token');
      const cleanUrl = window.location.origin + window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
      history.replaceState({}, document.title, cleanUrl);
    }
  });
</script>
