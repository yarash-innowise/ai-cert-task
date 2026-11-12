'use strict';
// Concurrent-user load generator for the running app (task 44, AC-88). Plain Node, no dependencies.
// Each virtual user (VU) signs in once as the dev Super Admin, then repeats a browse loop with think time:
// GET /login (public, sets the CSRF cookie) is done once per sign-in; the loop is GET /admin/users?page=N and GET /admin.
// Env: LOAD_VUS (default 1000), LOAD_RAMP_S (30), LOAD_HOLD_S (60), LOAD_THINK_MS (1500), LOAD_EMAIL, LOAD_PASSWORD, LOAD_HOST (app), LOAD_SERVERNAME (localhost)
const https = require('node:https');

const VUS = Number(process.env.LOAD_VUS || 1000);
const RAMP_MS = Number(process.env.LOAD_RAMP_S || 30) * 1000;
const HOLD_MS = Number(process.env.LOAD_HOLD_S || 60) * 1000;
const THINK_MS = Number(process.env.LOAD_THINK_MS || 1500);
const EMAIL = process.env.LOAD_EMAIL;
const PASSWORD = process.env.LOAD_PASSWORD;
const HOST = process.env.LOAD_HOST || 'app';
const SERVERNAME = process.env.LOAD_SERVERNAME || 'localhost';
const REQUEST_TIMEOUT_MS = 30000;

const agent = new https.Agent({ keepAlive: true, maxSockets: VUS + 50, rejectUnauthorized: false, servername: SERVERNAME });
const stats = new Map(); // step -> {latencies: [], ok: 0, bad: 0, errors: {}}

function record(step, ms, ok, why) {
  if (!stats.has(step)) stats.set(step, { latencies: [], ok: 0, bad: 0, errors: {} });
  const s = stats.get(step);
  s.latencies.push(ms);
  if (ok) s.ok++; else { s.bad++; s.errors[why] = (s.errors[why] || 0) + 1; }
}

function request(step, method, path, jar, body) {
  return new Promise((resolve) => {
    const started = process.hrtime.bigint();
    const headers = { Host: SERVERNAME, 'User-Agent': 'load-test' };
    const cookie = Object.entries(jar).map(([k, v]) => k + '=' + v).join('; ');
    if (cookie) headers.Cookie = cookie;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      headers['Content-Length'] = Buffer.byteLength(body);
    }
    const req = https.request({ host: HOST, servername: SERVERNAME, port: 443, method, path, headers, agent, timeout: REQUEST_TIMEOUT_MS }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        for (const line of res.headers['set-cookie'] || []) {
          const [pair] = line.split(';');
          const i = pair.indexOf('=');
          jar[pair.slice(0, i)] = pair.slice(i + 1);
        }
        const ms = Number(process.hrtime.bigint() - started) / 1e6;
        resolve({ status: res.statusCode, text: Buffer.concat(chunks).toString('utf8'), ms, step });
      });
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', (e) => {
      const ms = Number(process.hrtime.bigint() - started) / 1e6;
      resolve({ status: 0, text: '', ms, step, error: e.code || e.message });
    });
    if (body !== undefined) req.write(body);
    req.end();
  });
}

async function step(name, method, path, jar, body, okStatuses) {
  const r = await request(name, method, path, jar, body);
  const ok = okStatuses.includes(r.status);
  record(name, r.ms, ok, r.error || 'HTTP ' + r.status);
  return r;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const percentile = (sorted, p) => sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))] : 0;

async function vu(index, endAt) {
  await sleep((index / VUS) * RAMP_MS);
  const jar = {};
  const form = await step('GET /login', 'GET', '/login', jar, undefined, [200]);
  const token = (form.text.match(/name="_csrf" value="([^"]+)"/) || [])[1];
  if (!token) return;
  const body = new URLSearchParams({ _csrf: token, email: EMAIL, password: PASSWORD }).toString();
  const login = await step('POST /login (Argon2id verify)', 'POST', '/login', jar, body, [302]);
  if (login.status !== 302) return;
  while (Date.now() < endAt) {
    await step('GET /admin/users?page=N (10,000 users)', 'GET', '/admin/users?page=' + (1 + Math.floor(Math.random() * 200)), jar, undefined, [200]);
    await sleep(THINK_MS * (0.5 + Math.random()));
    await step('GET /admin', 'GET', '/admin', jar, undefined, [200]);
    await sleep(THINK_MS * (0.5 + Math.random()));
  }
}

(async () => {
  const startedAt = Date.now();
  const endAt = startedAt + RAMP_MS + HOLD_MS;
  await Promise.all(Array.from({ length: VUS }, (_, i) => vu(i, endAt)));
  const seconds = (Date.now() - startedAt) / 1000;
  const report = { vus: VUS, rampSeconds: RAMP_MS / 1000, holdSeconds: HOLD_MS / 1000, thinkMs: THINK_MS, wallSeconds: Math.round(seconds), steps: {} };
  let total = 0;
  for (const [name, s] of stats) {
    const sorted = s.latencies.slice().sort((a, b) => a - b);
    total += sorted.length;
    report.steps[name] = { requests: sorted.length, ok: s.ok, failed: s.bad, errors: s.errors, p50Ms: Math.round(percentile(sorted, 0.5)), p95Ms: Math.round(percentile(sorted, 0.95)), p99Ms: Math.round(percentile(sorted, 0.99)), maxMs: Math.round(sorted[sorted.length - 1] || 0) };
  }
  report.totalRequests = total;
  report.requestsPerSecond = Math.round(total / seconds);
  console.log(JSON.stringify(report, null, 1));
  agent.destroy();
})();
