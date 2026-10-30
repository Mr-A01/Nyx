(function () {
  if (window.__NYX_UPDATE_LISTENER__) return;
  window.__NYX_UPDATE_LISTENER__ = true;
  function base() {
    var p = location.pathname;
    var i = p.indexOf('/nyx');
    return location.origin + (i >= 0 ? p.slice(0, i + 4) : '/nyx');
  }
  function onUpdate(data) {
    try {
      console.info('[NYX] update available', data);
      if (data && data.target === 'client.html') {
        // soft reload UI assets
        var u = new URL(location.href);
        u.searchParams.set('_nyx_v', String(data.version || Date.now()));
        location.replace(u.toString());
      }
    } catch (e) {}
  }
  try {
    var es = new EventSource(base() + '/api/v3/updates/stream');
    es.addEventListener('update', function (ev) {
      try { onUpdate(JSON.parse(ev.data)); } catch (e) {}
    });
    es.onerror = function () {
      // fallback long-poll
      es.close();
      poll(0);
    };
  } catch (e) {
    poll(0);
  }
  function poll(since) {
    fetch(base() + '/api/v3/updates/wait?since=' + encodeURIComponent(since), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var d = (j && j.data) || j || {};
        if (d.changed) onUpdate(d.manifest || d);
        setTimeout(function () { poll(d.ts_ms || since); }, 2000);
      })
      .catch(function () { setTimeout(function () { poll(since); }, 8000); });
  }
})();
