// Live score autosave: while a match is live, score changes are saved
// immediately (no need to press Live or Save again) so the dashboard updates.
(function () {
  var endpoint = location.pathname + location.search;

  document.querySelectorAll('form.match-edit').forEach(function (form) {
    var timer = null;
    var state = form.querySelector('.save-state');

    function isLive() { return form.classList.contains('status-live'); }

    function show(text, kind) {
      if (!state) return;
      state.textContent = text;
      state.className = 'save-state ' + (kind || '');
    }

    function save() {
      var data = new FormData(form);
      data.set('status', 'keep');
      show('Saving…', 'saving');
      fetch(endpoint, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.json();
        })
        .then(function (d) {
          if (d.ok) {
            show('Saved ✓', 'ok');
          } else {
            show(d.message || 'Not saved', 'error');
          }
        })
        .catch(function () {
          show('Not saved – reload the page', 'error');
        });
    }

    function saveSoon() {
      if (!isLive()) return;
      clearTimeout(timer);
      show('…', 'saving');
      timer = setTimeout(save, 350);
    }

    form.querySelectorAll('.score-in').forEach(function (input) {
      input.addEventListener('input', saveSoon);
    });

    form.querySelectorAll('.step').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = form.querySelector('[name="' + btn.getAttribute('data-target') + '"]');
        var value = parseInt(input.value || '0', 10) + parseInt(btn.getAttribute('data-delta'), 10);
        input.value = Math.max(0, value);
        saveSoon();
      });
    });

    // Pressing Enter (or Live/Save) on a live match saves without reloading the page.
    form.addEventListener('submit', function (ev) {
      var action = ev.submitter ? ev.submitter.value : 'keep';
      if (isLive() && (action === 'live' || action === 'keep')) {
        ev.preventDefault();
        clearTimeout(timer);
        save();
      }
    });
  });
})();
