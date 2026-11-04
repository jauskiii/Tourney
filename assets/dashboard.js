// Live dashboard: re-fetches the content every 5 seconds and keeps a clock running.
(function () {
  var dash = document.getElementById('dash');
  var updated = document.getElementById('updated');
  var url = dash.getAttribute('data-refresh');
  var lastHtml = null;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function tickClock() {
    var el = document.getElementById('clock');
    if (el) {
      var d = new Date();
      el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
  }

  function refresh() {
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        // Only touch the page when something changed, so nothing flickers.
        if (html !== lastHtml) {
          dash.innerHTML = html;
          lastHtml = html;
        }
        tickClock();
        var d = new Date();
        updated.textContent = 'Live · updated ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        updated.classList.remove('stale');
      })
      .catch(function () {
        updated.textContent = 'Connection lost – retrying…';
        updated.classList.add('stale');
      });
  }

  setInterval(refresh, 5000);
  setInterval(tickClock, 5000);

  document.getElementById('fullscreen').addEventListener('click', function () {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(function () {});
    } else {
      document.exitFullscreen();
    }
  });
})();
