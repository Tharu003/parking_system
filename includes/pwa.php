<?php /* ParkSmart PWA - include this ONCE inside <head> of every page.
         Paths are relative to the project root (all pages live there). */ ?>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#FF6B00">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ParkSmart">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="image/png" sizes="48x48" href="icons/favicon-48.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<style>
  #psInstall{position:fixed;right:16px;bottom:16px;z-index:99999;display:none;align-items:center;gap:10px;
    background:#FF6B00;color:#fff;border-radius:999px;padding:6px 6px 6px 18px;box-shadow:0 8px 24px rgba(0,0,0,.35);
    font:700 14px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  #psInstall button{font:inherit;color:inherit;background:none;border:0;cursor:pointer;padding:8px 0}
  #psInstall .ps-x{width:30px;height:30px;border-radius:50%;background:rgba(0,0,0,.22);padding:0;font-size:14px}
  #psInstall.ios{max-width:290px;align-items:flex-start;border-radius:16px;padding:14px 12px 14px 16px;
    font-weight:600;line-height:1.45}
  @media (max-width:768px){#psInstall{bottom:84px}}
  @media print{#psInstall{display:none!important}}
</style>
<script>
(function () {
  // 1) Register the service worker (needs HTTPS, or localhost)
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function (e) { console.warn('SW failed', e); });
    });
  }

  // 2) Install button
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return;

  var KEY = 'ps_install_dismissed';
  function dismissedRecently() {
    try { var t = +localStorage.getItem(KEY) || 0; return Date.now() - t < 3 * 24 * 3600 * 1000; }
    catch (e) { return false; }
  }
  function remember() { try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {} }

  var deferred = null, box = null;
  function build(html, id) {
    box = document.createElement('div');
    box.id = 'psInstall';
    if (id) box.className = id;
    box.innerHTML = html;
    document.body.appendChild(box);
    box.style.display = 'flex';
    box.querySelector('.ps-x').addEventListener('click', function () { remember(); box.remove(); });
    return box;
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (dismissedRecently()) return;
    var b = build('<button type="button" class="ps-go">Install App</button><button type="button" class="ps-x" aria-label="Close">&times;</button>');
    b.querySelector('.ps-go').addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; if (box) box.remove(); });
    });
  });

  window.addEventListener('appinstalled', function () { if (box) box.remove(); });

  // iPhone / iPad Safari has no install prompt - show manual steps
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  if (isIOS && !dismissedRecently()) {
    window.addEventListener('load', function () {
      build('<div>App එක install කරන්න: Safari එකේ <b>Share</b> ඔබලා <b>Add to Home Screen</b> තෝරන්න.</div><button type="button" class="ps-x" aria-label="Close">&times;</button>', 'ios');
    });
  }
})();
</script>
