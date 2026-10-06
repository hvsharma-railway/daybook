<?php
/**
 * Monthly Daybook process: download the nine Suspense Head files from AIMS, run JV separation,
 * then open every allocation's Daybook - all through the existing pages.
 */
require __DIR__ . '/monthly/bootstrap.php';

monthlyStartSession();
$month = isset($_GET['month']) && MonthlyRun::isValidMonth($_GET['month']) ? $_GET['month'] : monthlyDefaultMonth();
$run = monthlyRun($month);
$initialState = monthlyStatePayload($run);
$csrf = $_SESSION['monthly_csrf'];
session_write_close();
$period = $run->period();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monthly Daybook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .status-badge { min-width: 104px; }
    .step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; font-weight: 600; margin-right: 8px; font-size: 14px; }
    .step-done .step-number { background: #198754; }
    .step-waiting .step-number { background: #adb5bd; }
    #log { max-height: 240px; overflow-y: auto; font-size: 14px; }
    #log li { padding: 2px 0; }
    .alloc-table td { vertical-align: middle; }
    .details { font-size: 13px; }
  </style>
</head>

<body class="bg-light">
  <div class="container py-4" style="max-width: 1080px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
      <h1 class="h3 fw-bold mb-0">Daybook &mdash; Monthly Process</h1>
      <a href="index.php" class="small">Portal home (manual mode)</a>
    </div>

    <!-- Month and AIMS session -->
    <div class="row g-3 mb-3">
      <div class="col-md-5">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <form method="get" id="monthForm">
              <label for="month" class="form-label fw-semibold">Month</label>
              <input type="month" class="form-control form-control-lg" id="month" name="month" value="<?php echo monthlyEscape($month); ?>" required>
            </form>
            <div class="mt-2 text-secondary small">
              AIMS period <strong><?php echo monthlyEscape($period['startDisplay'] . ' to ' . $period['endDisplay']); ?></strong>
              &middot; AU <strong><?php echo monthlyEscape($initialState['au']); ?></strong>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-7">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-semibold">AIMS session</div>
                <div id="sessionStatus" class="small mt-1"></div>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="clearCookieBtn">Clear</button>
            </div>
            <details class="mt-2" id="sessionDetails">
              <summary class="small">Set or replace the AIMS session</summary>
              <ol class="small text-secondary mt-2 mb-2 ps-3">
                <li>Log in to AIMS in this browser and open any AIMS page.</li>
                <li>Press <kbd>F12</kbd> &rarr; <em>Network</em>, reload, click the first request.</li>
                <li>Under <em>Request Headers</em>, copy the whole <code>Cookie</code> value and paste it below.</li>
              </ol>
              <textarea class="form-control form-control-sm font-monospace" id="cookieInput" rows="2" placeholder="WASJSESSIONID=...; aimsweb=...; TS01...=..." autocomplete="off" spellcheck="false"></textarea>
              <button type="button" class="btn btn-primary btn-sm mt-2" id="saveCookieBtn">Use this session</button>
              <div class="small text-secondary mt-2">Held only in your session on the Daybook server &mdash; never written to Daybook files. If AIMS rejects it, you can still upload each file by hand below.</div>
            </details>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 1 -->
    <div class="card shadow-sm mb-3" id="step1">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h2 class="h5 mb-0"><span class="step-number">1</span>Download Suspense Head files</h2>
          <span class="badge text-bg-secondary fs-6" id="readyCounter"></span>
        </div>
        <div class="progress mb-3" style="height: 8px;">
          <div class="progress-bar" id="progressBar" role="progressbar"></div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm alloc-table mb-3">
            <thead class="table-light">
              <tr>
                <th style="width: 90px;">Allocation</th>
                <th style="width: 130px;">Status</th>
                <th>Details</th>
                <th style="width: 190px;" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="allocRows"></tbody>
          </table>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary btn-lg" id="startBtn">Start Monthly Daybook Process</button>
          <button type="button" class="btn btn-outline-danger ms-auto" id="resetBtn">Restart month</button>
        </div>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="card shadow-sm mb-3" id="step2">
      <div class="card-body">
        <h2 class="h5 mb-3"><span class="step-number">2</span>JV Separation &amp; Allocation sheets</h2>
        <div id="step2Body"></div>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="card shadow-sm mb-3" id="step3">
      <div class="card-body">
        <h2 class="h5 mb-3"><span class="step-number">3</span>Daybook</h2>
        <div id="step3Body"></div>
      </div>
    </div>

    <!-- Activity -->
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h2 class="h6 text-secondary mb-2">Activity</h2>
        <ul class="list-unstyled mb-0" id="log"></ul>
      </div>
    </div>

    <form method="post" action="downloadAllocationSheets.php" target="_blank" id="allocationSheetsForm" class="d-none">
      <input type="hidden" name="exported_co6numbers" id="exportedCo6Numbers">
    </form>

    <footer class="text-center small text-secondary">IT Cell, Office of Sr.DFM - RJT, WR</footer>
  </div>

  <script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    const MONTH = <?php echo json_encode($month); ?>;
    let state = <?php echo json_encode($initialState); ?>;
    let busy = false;

    const STATUS = {
      pending: ['Pending', 'text-bg-light border'],
      downloading: ['Downloading…', 'text-bg-info'],
      uploading: ['Uploading…', 'text-bg-info'],
      downloaded: ['Downloaded', 'text-bg-primary'],
      processing: ['Processing…', 'text-bg-warning'],
      completed: ['Completed', 'text-bg-success'],
      failed: ['Failed', 'text-bg-danger']
    };

    function esc(text) {
      return String(text == null ? '' : text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function isReady(a) {
      return a.status === 'downloaded' || a.status === 'completed';
    }

    function log(message, tone) {
      const li = document.createElement('li');
      li.className = tone ? 'text-' + tone : '';
      li.textContent = new Date().toLocaleTimeString() + '  ' + message;
      document.getElementById('log').appendChild(li);
      li.scrollIntoView({ block: 'nearest' });
    }

    async function api(action, data, file) {
      const body = new FormData();
      body.append('action', action);
      body.append('month', MONTH);
      body.append('csrf', CSRF);
      Object.keys(data || {}).forEach(k => body.append(k, data[k]));
      if (file) body.append('file', file);
      try {
        const response = await fetch('monthlyApi.php', { method: 'POST', body: body, credentials: 'same-origin' });
        const text = await response.text();
        try {
          return JSON.parse(text);
        } catch (e) {
          return { ok: false, error: 'Unexpected server response (HTTP ' + response.status + '): ' + text.replace(/<[^>]*>/g, ' ').trim().slice(0, 200) };
        }
      } catch (e) {
        return { ok: false, error: 'Could not reach the Daybook server: ' + e.message };
      }
    }

    async function refresh() {
      const res = await api('status');
      if (res.state) { state = res.state; render(); }
    }

    function details(a) {
      if (a.status === 'failed') return '<span class="text-danger"><strong>Reason:</strong> ' + esc(a.message) + '</span>';
      if (!isReady(a)) return '';
      const content = a.heads === 0
        ? 'No transactions this month (empty report)'
        : a.heads + ' sub-allocation head' + (a.heads === 1 ? '' : 's') + ', ' + a.entries + ' entr' + (a.entries === 1 ? 'y' : 'ies');
      const source = a.via === 'upload' ? 'uploaded ' + esc(a.sourceName) : 'from AIMS';
      const when = a.updatedAt ? ' · ' + new Date(a.updatedAt * 1000).toLocaleString() : '';
      return esc(content) + '<br><span class="text-secondary">' + source + when + '</span>';
    }

    function render() {
      const s = state;
      const sessionEl = document.getElementById('sessionStatus');
      if (s.session.configured) {
        const from = s.session.source === 'pasted'
          ? 'pasted' + (s.session.setAt ? ' at ' + new Date(s.session.setAt * 1000).toLocaleTimeString() : '')
          : 'from server settings';
        sessionEl.innerHTML = '<span class="badge text-bg-success">Set</span> <span class="text-secondary">' + esc(from) + ' &middot; cookies: ' + esc(s.session.cookieNames.join(', ')) + '</span>';
      } else {
        sessionEl.innerHTML = '<span class="badge text-bg-warning">Not set</span> <span class="text-secondary">needed to download from AIMS</span>';
      }
      document.getElementById('clearCookieBtn').classList.toggle('d-none', s.session.source !== 'pasted');

      // Step 1
      document.getElementById('readyCounter').textContent = s.readyCount + ' / ' + s.total + ' ready';
      const bar = document.getElementById('progressBar');
      bar.style.width = (100 * s.readyCount / s.total) + '%';
      bar.className = 'progress-bar' + (s.readyCount === s.total ? ' bg-success' : '');
      document.getElementById('allocRows').innerHTML = s.allocations.map(a => {
        const [label, cls] = STATUS[a.status] || [a.status, 'text-bg-secondary'];
        const working = ['downloading', 'uploading', 'processing'].includes(a.status);
        return '<tr>' +
          '<td class="fw-semibold">' + esc(a.code) + '</td>' +
          '<td><span class="badge status-badge ' + cls + '">' + esc(label) + '</span></td>' +
          '<td class="details">' + details(a) + '</td>' +
          '<td class="text-end text-nowrap">' +
          '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-retry="' + esc(a.code) + '"' + (busy || working ? ' disabled' : '') + '>' + (isReady(a) ? 'Re-download' : (a.status === 'failed' ? 'Retry' : 'Download')) + '</button>' +
          '<label class="btn btn-sm btn-outline-secondary mb-0' + (busy || working ? ' disabled' : '') + '" title="Use a file you downloaded from AIMS yourself">Upload<input type="file" class="d-none" accept=".xls,.xlsx" data-upload="' + esc(a.code) + '"' + (busy || working ? ' disabled' : '') + '></label>' +
          '</td></tr>';
      }).join('');

      const startBtn = document.getElementById('startBtn');
      const failed = s.allocations.filter(a => a.status === 'failed').length;
      startBtn.classList.toggle('d-none', s.complete);
      startBtn.disabled = busy;
      startBtn.textContent = busy ? 'Working…'
        : s.readyCount === s.total ? 'Continue: JV Separation & Daybook'
        : failed ? 'Retry failed & continue'
        : s.readyCount > 0 ? 'Continue Monthly Daybook Process'
        : 'Start Monthly Daybook Process';
      document.getElementById('resetBtn').disabled = busy;

      // Step 2
      const step2 = document.getElementById('step2Body');
      if (s.complete && s.jv) {
        step2.innerHTML =
          '<p class="mb-3 text-success">&#10003; JV Separation completed &mdash; <strong>' + s.jv.jvNumbers.length + '</strong> JV CO6 numbers, <strong>' + s.jv.nonJvNumbers.length + '</strong> CO6 numbers for allocation sheets.</p>' +
          '<div class="d-flex flex-wrap gap-2">' +
          '<a class="btn btn-outline-primary" target="_blank" href="monthlyJV.php?month=' + encodeURIComponent(MONTH) + '">Open JV / CO6 list</a>' +
          '<button type="button" class="btn btn-outline-primary" id="allocationSheetsBtn"' + (s.jv.nonJvNumbers.length ? '' : ' disabled') + '>Download allocation sheets (' + s.jv.nonJvNumbers.length + ')</button>' +
          '</div>' +
          '<div class="small text-secondary mt-2">Allocation sheets open from AIMS in your browser, using your AIMS login, as before.</div>';
      } else {
        step2.innerHTML = '<p class="text-secondary mb-0">Runs automatically once all ' + s.total + ' Suspense Head files are downloaded (' + s.readyCount + ' / ' + s.total + ').</p>';
      }

      // Step 3
      const step3 = document.getElementById('step3Body');
      if (s.complete) {
        step3.innerHTML =
          '<p class="text-success mb-3">&#10003; Daybook generated for all ' + s.total + ' allocations &mdash; ' + esc(s.period.label) + '.</p>' +
          '<div class="table-responsive"><table class="table table-sm mb-3"><thead class="table-light"><tr><th>Allocation</th><th>Contents</th><th class="text-end">Open</th></tr></thead><tbody>' +
          s.allocations.map(a => {
            const q = 'month=' + encodeURIComponent(MONTH) + '&alloc=' + encodeURIComponent(a.code);
            return '<tr><td class="fw-semibold">' + esc(a.code) + '</td><td class="details">' + (a.heads === 0 ? 'No transactions' : a.entries + ' entries') + '</td>' +
              '<td class="text-end text-nowrap"><a class="btn btn-sm btn-primary me-1" target="_blank" href="monthlyView.php?' + q + '">View / Print PDF</a>' +
              '<a class="btn btn-sm btn-outline-success" href="monthlyExport.php?' + q + '&mode=all">Excel</a></td></tr>';
          }).join('') +
          '</tbody></table></div>' +
          '<a class="btn btn-success btn-lg" id="zipBtn" href="monthlyApi.php?action=zip&month=' + encodeURIComponent(MONTH) + '">Download All Outputs (.zip)</a>' +
          '<div class="small text-secondary mt-2">Enter each allocation\'s Last Month figures on its View page, then use PRINT ALL &rarr; Save as PDF, or EXPORT ALL EXCEL, as before.</div>';
      } else {
        step3.innerHTML = '<p class="text-secondary mb-0">Available once Step 2 has completed. Nothing is generated from an incomplete set of files.</p>';
      }

      document.getElementById('step1').className = 'card shadow-sm mb-3' + (s.readyCount === s.total ? ' step-done' : '');
      document.getElementById('step2').className = 'card shadow-sm mb-3' + (s.complete ? ' step-done' : (s.readyCount === s.total ? '' : ' step-waiting'));
      document.getElementById('step3').className = 'card shadow-sm mb-3' + (s.complete ? ' step-done' : ' step-waiting');
    }

    function setBusy(value) {
      busy = value;
      render();
    }

    function setRowStatus(code, status) {
      state.allocations.forEach(a => { if (a.code === code) { a.status = status; a.message = ''; } });
      render();
    }

    // Returns 'ok', 'failed' or 'session'
    async function downloadOne(code) {
      setRowStatus(code, 'downloading');
      log('Downloading allocation ' + code + '…');
      const res = await api('download', { alloc: code });
      if (res.state) { state = res.state; render(); } else { await refresh(); }
      if (res.ok) {
        log('✓ Downloaded allocation ' + code, 'success');
        return 'ok';
      }
      log('✗ Allocation ' + code + ' — Failed. Reason: ' + res.error, 'danger');
      return res.errorType === 'session' ? 'session' : 'failed';
    }

    async function finalizeIfReady() {
      if (state.complete) return;
      if (state.readyCount !== state.total) {
        const missing = state.allocations.filter(a => !isReady(a)).map(a => a.code).join(', ');
        log(state.readyCount + ' / ' + state.total + ' Suspense Head files ready. Not continuing until allocation ' + missing + ' ' + (missing.includes(',') ? 'are' : 'is') + ' downloaded — retry, or upload the file by hand.', 'warning');
        return;
      }
      log(state.total + ' / ' + state.total + ' Suspense Head files downloaded successfully. Proceeding to JV Separation & Allocation…');
      state.allocations.forEach(a => { a.status = 'processing'; });
      render();
      const res = await api('finalize');
      if (res.state) { state = res.state; render(); } else { await refresh(); }
      if (!res.ok) {
        log('✗ Processing stopped: ' + res.error, 'danger');
        return;
      }
      log('✓ JV Separation completed (' + state.jv.jvNumbers.length + ' JV CO6 numbers)', 'success');
      log('✓ CO6 / Allocation sheets ready (' + state.jv.nonJvNumbers.length + ' CO6 numbers)', 'success');
      log('✓ Daybook generated for allocations ' + state.allocations.map(a => a.code).join(', '), 'success');
    }

    async function startProcess() {
      const todo = state.allocations.filter(a => !isReady(a)).map(a => a.code);
      if (todo.length && !state.session.configured) {
        log('Set the AIMS session first (or upload the ' + todo.length + ' remaining file' + (todo.length === 1 ? '' : 's') + ' by hand).', 'danger');
        document.getElementById('sessionDetails').open = true;
        document.getElementById('cookieInput').focus();
        return;
      }
      setBusy(true);
      try {
        if (todo.length) log('Starting ' + state.period.label + ': ' + todo.length + ' allocation' + (todo.length === 1 ? '' : 's') + ' to download.');
        for (const code of todo) {
          if (await downloadOne(code) === 'session') {
            log('Stopped: the AIMS session was not accepted. Set a fresh session and click the button again — files already downloaded are kept.', 'danger');
            document.getElementById('sessionDetails').open = true;
            break;
          }
        }
        await finalizeIfReady();
      } finally {
        setBusy(false);
      }
    }

    async function retryOne(code) {
      setBusy(true);
      try {
        await downloadOne(code);
        await finalizeIfReady();
      } finally {
        setBusy(false);
      }
    }

    async function uploadOne(code, file) {
      setBusy(true);
      try {
        setRowStatus(code, 'uploading');
        log('Uploading ' + file.name + ' for allocation ' + code + '…');
        const res = await api('upload', { alloc: code }, file);
        if (res.state) { state = res.state; render(); } else { await refresh(); }
        if (res.ok) {
          log('✓ Allocation ' + code + ' loaded from ' + file.name, 'success');
          await finalizeIfReady();
        } else {
          log('✗ Allocation ' + code + ' — Failed. Reason: ' + res.error, 'danger');
        }
      } finally {
        setBusy(false);
      }
    }

    document.getElementById('month').addEventListener('change', function () {
      if (this.value) document.getElementById('monthForm').submit();
    });
    document.getElementById('startBtn').addEventListener('click', startProcess);
    document.getElementById('resetBtn').addEventListener('click', async function () {
      if (!confirm('Restart ' + state.period.label + '? All downloaded files for this month will be removed.')) return;
      setBusy(true);
      const res = await api('reset');
      if (res.state) state = res.state;
      log(res.ok ? state.period.label + ' restarted.' : '✗ ' + res.error, res.ok ? '' : 'danger');
      setBusy(false);
    });
    document.getElementById('saveCookieBtn').addEventListener('click', async function () {
      const res = await api('setCookie', { cookie: document.getElementById('cookieInput').value });
      if (res.ok) {
        state.session = res.session;
        document.getElementById('cookieInput').value = '';
        document.getElementById('sessionDetails').open = false;
        log('AIMS session set.', 'success');
      } else {
        log('✗ ' + res.error, 'danger');
      }
      render();
    });
    document.getElementById('clearCookieBtn').addEventListener('click', async function () {
      const res = await api('clearCookie');
      if (res.ok) { state.session = res.session; log('AIMS session cleared.'); }
      render();
    });
    document.getElementById('allocRows').addEventListener('click', function (e) {
      const button = e.target.closest('[data-retry]');
      if (!button || busy) return;
      const code = button.getAttribute('data-retry');
      if (!state.session.configured) {
        log('Set the AIMS session first, or use Upload for allocation ' + code + '.', 'danger');
        document.getElementById('sessionDetails').open = true;
        return;
      }
      retryOne(code);
    });
    document.getElementById('allocRows').addEventListener('change', function (e) {
      const input = e.target.closest('[data-upload]');
      if (input && input.files.length && !busy) uploadOne(input.getAttribute('data-upload'), input.files[0]);
    });
    document.getElementById('step2Body').addEventListener('click', function (e) {
      if (e.target.id !== 'allocationSheetsBtn') return;
      document.getElementById('exportedCo6Numbers').value = JSON.stringify(state.jv.nonJvNumbers);
      document.getElementById('allocationSheetsForm').submit();
    });
    document.getElementById('step3Body').addEventListener('click', function (e) {
      if (e.target.id === 'zipBtn') log('Preparing the ZIP of all outputs… (the download starts when ready)');
    });

    render();
    if (state.complete) log(state.period.label + ' is complete. All outputs are ready.', 'success');
    else if (state.readyCount) log(state.period.label + ': ' + state.readyCount + ' / ' + state.total + ' Suspense Head files already downloaded.');
  </script>
</body>

</html>
