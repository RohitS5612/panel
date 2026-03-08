const http = require('http');
const crypto = require('crypto');
const { execSync } = require('child_process');
const { URLSearchParams } = require('url');

const PORT = process.env.PORT || 3000;
const USERNAME = 'admin';
const PASSWORD = 'db2026';
const SYSTEMCTL_PATH = '/bin/systemctl';
const USE_SUDO_FOR_SYSTEMCTL = true;

const sessions = new Map();

function escapeHtml(value = '') {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function parseCookies(cookieHeader = '') {
  return cookieHeader
    .split(';')
    .map((part) => part.trim())
    .filter(Boolean)
    .reduce((acc, item) => {
      const index = item.indexOf('=');
      if (index > -1) {
        acc[item.slice(0, index)] = decodeURIComponent(item.slice(index + 1));
      }
      return acc;
    }, {});
}

function sanitizeServiceName(service = '') {
  const trimmed = service.trim();
  return /^[A-Za-z0-9@._:-]+\.service$/.test(trimmed) ? trimmed : null;
}

function buildSystemctlCommand(args) {
  const prefix = USE_SUDO_FOR_SYSTEMCTL ? 'sudo ' : '';
  return `${prefix}${SYSTEMCTL_PATH} ${args}`;
}

function runSystemctl(args) {
  const command = buildSystemctlCommand(args);
  try {
    const output = execSync(command, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    return { ok: true, output };
  } catch (error) {
    const stderr = error.stderr ? error.stderr.toString() : error.message;
    return { ok: false, output: stderr };
  }
}

function listServices() {
  const result = runSystemctl('list-units --type=service --all --no-legend --no-pager');
  const services = result.output
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const parts = line.split(/\s+/, 5);
      return {
        name: parts[0] || '',
        load: parts[1] || 'unknown',
        active: parts[2] || 'unknown',
        sub: parts[3] || 'unknown',
        description: parts[4] || '',
      };
    })
    .sort((a, b) => a.name.localeCompare(b.name));

  return {
    services,
    error: result.ok ? null : result.output,
  };
}

function buildOverview(services) {
  return services.reduce(
    (acc, svc) => {
      acc.total += 1;
      if (Object.hasOwn(acc, svc.active)) {
        acc[svc.active] += 1;
      } else {
        acc.other += 1;
      }
      return acc;
    },
    { total: 0, active: 0, inactive: 0, failed: 0, other: 0 }
  );
}

function statusClass(active) {
  if (active === 'active') return 'active';
  if (active === 'inactive' || active === 'failed') return 'inactive';
  return 'unknown';
}

function layout(content) {
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Service Panel</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f4f6fb; color: #1f2937; }
    .container { max-width: 1100px; margin: 2rem auto; background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    h1 { margin-top: 0; }
    .topbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .alert { padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; }
    .alert.success { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }
    .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    .login-form { max-width: 360px; margin: 4rem auto; }
    input, button { padding: .65rem .75rem; border-radius: 8px; border: 1px solid #d1d5db; font-size: 1rem; }
    input { width: 100%; margin-bottom: .75rem; box-sizing: border-box; }
    button { border: none; background: #2563eb; color: white; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { padding: .7rem; border-bottom: 1px solid #e5e7eb; font-size: .95rem; text-align: left; }
    .actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    .actions button { font-size: .85rem; padding: .45rem .6rem; }
    .tag { display: inline-block; padding: .2rem .45rem; border-radius: 999px; font-size: .8rem; }
    .tag.active { background: #dcfce7; color: #166534; }
    .tag.inactive { background: #fee2e2; color: #991b1b; }
    .tag.unknown { background: #e5e7eb; color: #374151; }
    .overview { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: .75rem; margin: 1rem 0 1.4rem; }
    .card { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: .8rem; }
    .card .label { font-size: .8rem; color: #475569; }
    .card .value { font-size: 1.3rem; font-weight: 700; }
  </style>
</head>
<body>
  <div class="container">
    ${content}
  </div>
</body>
</html>`;
}

function loginPage(message = '') {
  return layout(`
    <div class="login-form">
      <h1>Service Panel Login</h1>
      <p>Sign in with your admin account.</p>
      ${message ? `<div class="alert error">${escapeHtml(message)}</div>` : ''}
      <form method="post" action="/login">
        <label>
          Username
          <input type="text" name="username" required autocomplete="username">
        </label>
        <label>
          Password
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Login</button>
      </form>
    </div>
  `);
}

function dashboardPage(username, services, overview, message = '', messageType = 'success', serviceQueryError = '') {
  const rows = services
    .map((svc) => {
      const cls = statusClass(svc.active);
      return `<tr>
        <td>${escapeHtml(svc.name)}</td>
        <td>${escapeHtml(svc.load)}</td>
        <td><span class="tag ${cls}">${escapeHtml(svc.active)}</span></td>
        <td>${escapeHtml(svc.sub)}</td>
        <td>${escapeHtml(svc.description)}</td>
        <td>
          <form method="post" action="/service" class="actions">
            <input type="hidden" name="service" value="${escapeHtml(svc.name)}">
            <button type="submit" name="action" value="start">Start</button>
            <button type="submit" name="action" value="stop">Stop</button>
            <button type="submit" name="action" value="restart">Restart</button>
            <button type="submit" name="action" value="reload">Reload</button>
          </form>
        </td>
      </tr>`;
    })
    .join('');

  return layout(`
    <div class="topbar">
      <div>
        <h1>Systemd Service Dashboard</h1>
        <p>Logged in as <strong>${escapeHtml(username)}</strong></p>
      </div>
      <a href="/logout">Logout</a>
    </div>

    ${message ? `<div class="alert ${escapeHtml(messageType)}">${escapeHtml(message)}</div>` : ''}
    ${serviceQueryError ? `<div class="alert error">Unable to read services from systemd. ${escapeHtml(serviceQueryError)}</div>` : ''}

    <div class="overview">
      <div class="card"><div class="label">Total</div><div class="value">${overview.total}</div></div>
      <div class="card"><div class="label">Active</div><div class="value">${overview.active}</div></div>
      <div class="card"><div class="label">Inactive</div><div class="value">${overview.inactive}</div></div>
      <div class="card"><div class="label">Failed</div><div class="value">${overview.failed}</div></div>
      <div class="card"><div class="label">Other</div><div class="value">${overview.other}</div></div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Service</th>
          <th>Load</th>
          <th>Status</th>
          <th>Sub-state</th>
          <th>Description</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `);
}

function parseBody(req) {
  return new Promise((resolve) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk.toString();
      if (body.length > 1e6) {
        req.destroy();
      }
    });
    req.on('end', () => resolve(new URLSearchParams(body)));
  });
}

function send(res, statusCode, html, headers = {}) {
  res.writeHead(statusCode, { 'Content-Type': 'text/html; charset=utf-8', ...headers });
  res.end(html);
}

function redirect(res, location, headers = {}) {
  res.writeHead(302, { Location: location, ...headers });
  res.end();
}

http
  .createServer(async (req, res) => {
    const cookies = parseCookies(req.headers.cookie);
    const sid = cookies.sid;
    const session = sid && sessions.get(sid);

    if (req.method === 'GET' && req.url === '/logout') {
      if (sid) {
        sessions.delete(sid);
      }
      return redirect(res, '/', { 'Set-Cookie': 'sid=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax' });
    }

    if (req.method === 'POST' && req.url === '/login') {
      const form = await parseBody(req);
      const username = form.get('username') || '';
      const password = form.get('password') || '';

      if (username === USERNAME && password === PASSWORD) {
        const newSid = crypto.randomBytes(24).toString('hex');
        sessions.set(newSid, { username });
        return redirect(res, '/', { 'Set-Cookie': `sid=${newSid}; Path=/; HttpOnly; SameSite=Lax` });
      }

      return send(res, 401, loginPage('Invalid username or password.'));
    }

    if (!session) {
      return send(res, 200, loginPage());
    }

    let message = '';
    let messageType = 'success';

    if (req.method === 'POST' && req.url === '/service') {
      const form = await parseBody(req);
      const action = form.get('action') || '';
      const service = sanitizeServiceName(form.get('service') || '');
      const allowedActions = ['start', 'stop', 'restart', 'reload'];

      if (!allowedActions.includes(action) || !service) {
        message = 'Invalid action or service name.';
        messageType = 'error';
      } else {
        const result = runSystemctl(`'${action}' '${service}'`);
        if (result.ok) {
          message = `Successfully ran "${action}" on ${service}.`;
        } else {
          message = `Failed to run "${action}" on ${service}. Details: ${result.output}`;
          messageType = 'error';
        }
      }
    }

    const { services, error } = listServices();
    const overview = buildOverview(services);
    return send(res, 200, dashboardPage(session.username, services, overview, message, messageType, error || ''));
  })
  .listen(PORT, '0.0.0.0', () => {
    console.log(`Node dashboard listening on http://0.0.0.0:${PORT}`);
  });
