import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Gauge, Rate, Trend } from 'k6/metrics';

const manifest = JSON.parse(open(__ENV.BTS_MANIFEST));
const syntheticDocument = open('./fixtures/synthetic-proof.txt', 'b');
const baseUrl = String(__ENV.BTS_BASE_URL || 'http://127.0.0.1:8200').replace(/\/$/, '');
const collectorUrl = String(__ENV.BTS_OTP_COLLECTOR_URL || 'http://127.0.0.1:8299').replace(/\/$/, '');
const scenarioName = __ENV.BTS_SCENARIO || 'login';
const pattern = __ENV.BTS_PATTERN || 'burst';
const accounts = Number(__ENV.BTS_ACCOUNTS || manifest.accounts);
const concurrency = Number(__ENV.BTS_CONCURRENCY || Math.min(accounts, 5));
const applicationsPerAccount = Number(__ENV.BTS_APPLICATIONS_PER_ACCOUNT || manifest.applications_per_account || 1);
const thinkTimeMs = Number(__ENV.BTS_THINK_TIME_MS || 0);
const rampUpSeconds = Number(__ENV.BTS_RAMP_UP_SECONDS || 0);
const duration = __ENV.BTS_DURATION || '30s';
const maxErrorRate = Number(__ENV.BTS_MAX_ERROR_RATE || 0);
const p95Ms = Number(__ENV.BTS_P95_MS || 0);
const p99Ms = Number(__ENV.BTS_P99_MS || 0);

if (manifest.marker !== 'SYNTHETIC_LOAD_TEST_ONLY' || accounts < 1 || accounts > manifest.customers.length) {
  throw new Error('Invalid synthetic load-test manifest or account count.');
}

const workflowsStarted = new Counter('bts_workflows_started');
const workflowsCompleted = new Counter('bts_workflows_completed');
const workflowsSucceeded = new Counter('bts_workflows_succeeded');
const workflowsFailed = new Counter('bts_workflows_failed');
const workflowSuccessRate = new Rate('bts_workflow_success_rate');
const workflowDuration = new Trend('bts_workflow_duration', true);
const authenticationFailures = new Counter('bts_authentication_failures');
const validationFailures = new Counter('bts_validation_failures');
const timeoutCount = new Counter('bts_timeouts');
const unauthorizedAccess = new Counter('bts_unauthorized_access');
const status2xx = new Counter('bts_http_2xx');
const status4xx = new Counter('bts_http_4xx');
const status5xx = new Counter('bts_http_5xx');
const statusOther = new Counter('bts_http_other');
const apiRequests = new Counter('bts_api_requests');
const apiDuration = new Trend('bts_api_duration', true);
const queuePending = new Gauge('bts_queue_pending');
const queueFailed = new Gauge('bts_queue_failed');
const queueDelayMs = new Gauge('bts_queue_delay_ms');
const jobRuntimeMs = new Gauge('bts_job_runtime_ms');
const reverbFailures = new Gauge('bts_reverb_failures');
const processMemoryBytes = new Gauge('bts_process_memory_bytes');
const dbConnections = new Gauge('bts_db_connections');
const dbDeadlocks = new Gauge('bts_db_deadlocks');
const dbLockWaits = new Gauge('bts_db_lock_waits');

function buildScenarios() {
  if (scenarioName !== 'read') {
    return {
      workflow: {
        executor: 'shared-iterations',
        vus: concurrency,
        maxDuration: duration,
        iterations: accounts,
      },
    };
  }

  if (pattern === 'ramp') {
    return { read: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: `${Math.max(1, rampUpSeconds)}s`, target: concurrency }, { duration, target: concurrency }, { duration: '5s', target: 0 }] } };
  }
  if (pattern === 'spike') {
    const baseline = Math.max(1, Math.floor(concurrency / 5));
    return { read: { executor: 'ramping-vus', startVUs: baseline, stages: [{ duration: '5s', target: baseline }, { duration: '5s', target: concurrency }, { duration: '10s', target: concurrency }, { duration: '5s', target: baseline }] } };
  }

  return { read: { executor: 'constant-vus', vus: concurrency, duration } };
}

const thresholds = {
  bts_workflows_failed: ['count==0'],
  bts_unauthorized_access: ['count==0'],
};
if (maxErrorRate > 0) thresholds.bts_workflow_success_rate = [`rate>${1 - maxErrorRate}`];
if (p95Ms > 0 || p99Ms > 0) {
  thresholds.bts_api_duration = [];
  if (p95Ms > 0) thresholds.bts_api_duration.push(`p(95)<${p95Ms}`);
  if (p99Ms > 0) thresholds.bts_api_duration.push(`p(99)<${p99Ms}`);
}

export const options = {
  scenarios: buildScenarios(),
  thresholds,
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)', 'count'],
  noConnectionReuse: false,
  userAgent: 'BTS-Synthetic-Load-Test/1.0',
};

let readToken = null;
let requestCount = 0;

function headers(identity, token = null, json = true) {
  const values = {
    Accept: 'application/json',
    'X-BTS-Synthetic-User': identity,
    'X-Correlation-Id': `load-${manifest.campaign_id}-${identity}-${exec.vu.iterationInInstance}`,
  };
  if (json) values['Content-Type'] = 'application/json';
  if (token) values.Authorization = `Bearer ${token}`;
  return values;
}

function recordStatus(response, expectedAuthFailure = false) {
  requestCount += 1;
  apiRequests.add(1);
  apiDuration.add(Number(response.timings.duration || 0));
  if (response.status >= 200 && response.status < 300) status2xx.add(1);
  else if (response.status >= 400 && response.status < 500) status4xx.add(1);
  else if (response.status >= 500) status5xx.add(1);
  else statusOther.add(1);
  if (response.status === 0) timeoutCount.add(1);
  if (response.status === 422) validationFailures.add(1);
  if (!expectedAuthFailure && (response.status === 401 || response.status === 403)) authenticationFailures.add(1);
}

function api(method, path, identity, token = null, body = null, expected = [200], params = {}) {
  const expectedAuthFailure = params.expectedAuthFailure === true;
  const requestParams = Object.assign({ headers: headers(identity, token, body !== null), timeout: '30s', tags: { endpoint: path.replace(/\/[0-9]+/g, '/:id') } }, params);
  delete requestParams.expectedAuthFailure;
  let response;
  if (method === 'GET') response = http.get(`${baseUrl}${path}`, requestParams);
  else response = http.request(method, `${baseUrl}${path}`, body === null ? null : JSON.stringify(body), requestParams);
  recordStatus(response, expectedAuthFailure);
  const ok = expected.includes(response.status);
  check(response, { [`${method} ${path} -> ${expected.join('|')}`]: () => ok });
  if (!ok) {
    let code = 'HTTP_ERROR';
    try { code = response.json('error.code') || code; } catch (_) { /* sanitized below */ }
    throw new Error(`${method} ${path} failed (${response.status}, ${code})`);
  }
  if (response.status === 204 || !response.body) return {};
  try { return response.json(); } catch (_) { throw new Error(`${method} ${path} returned invalid JSON`); }
}

function customerLogin(customer, identity) {
  const first = api('POST', '/api/auth/login', identity, null, { identifier: customer.email, password: manifest.password });
  let otpResponse = null;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    otpResponse = http.get(`${collectorUrl}/otp?phone=${encodeURIComponent(customer.phone)}`, {
      headers: { Authorization: `Bearer ${__ENV.BTS_OTP_COLLECTOR_TOKEN}` },
      timeout: '2s',
      tags: { endpoint: 'otp_collector' },
    });
    if (otpResponse.status === 200) break;
    sleep(0.1);
  }
  if (!otpResponse || otpResponse.status !== 200) throw new Error('Synthetic OTP was not delivered to the loopback collector.');
  const code = otpResponse.json('code');
  const verified = api('POST', '/api/auth/login/verify-otp', identity, null, { pre_auth_token: first.data.pre_auth_token, otp_code: code });
  return verified.data.access_token;
}

function staffLogin(email, identity, admin = false) {
  const path = admin ? '/api/staff/admin/login' : '/api/staff/login';
  return api('POST', path, identity, null, { email, password: manifest.password }).data.access_token;
}

function isoDate(offsetDays = 0) {
  const value = new Date(Date.now() + offsetDays * 86400000);
  return value.toISOString().slice(0, 10);
}

function createAndSubmit(customer, token, identity, applicationOrdinal) {
  const created = api('POST', '/api/applications', identity, token, {}, [201]);
  const id = created.data.application.id;
  const suffix = String(customer.ordinal).padStart(6, '0');
  const firstName = 'Synthétique';
  const lastName = `Charge${suffix}`;

  api('PUT', `/api/applications/${id}/client`, identity, token, {
    civilite: 'M', nom: lastName, prenom: firstName, nom_epoux: null, deuxieme_prenom: null,
    date_naissance: '1990-01-15', lieu_naissance: customer.branch.ville, pays_naissance: 'Tunisie',
    nationalite: 'Tunisienne', pays_residence: 'Tunisie', etat_civil: 'célibataire', nombre_enfants: 0,
    type_pid: 'CIN', numero_pid: `${String(customer.ordinal).padStart(7, '0').slice(-7)}${applicationOrdinal % 10}`,
    date_delivrance_pid: '2020-01-15', lieu_delivrance_pid: customer.branch.ville,
    numero_carte_sejour: null, profession: 'Activité synthétique', date_entree_relation: '2024-01-01',
  });
  const credit = api('PUT', `/api/applications/${id}/credit`, identity, token, {
    nom_ou_rs: lastName, prenom_ou_dc: firstName, origine: 'SYNTHETIC_LOAD_TEST',
    date_depot: isoDate(-1), date_reception: isoDate(0), type_demande: 'Création synthétique', code_devise: 'TND',
    montant_global_sollicite: 50000, montant_eqp: 30000, montant_fdr: 10000, montant_amg: 10000,
    montant_chp: 0, nombre_credits_sollicites: 1, unite_depot: customer.branch.name,
  });
  api('PUT', `/api/applications/${id}/project`, identity, token, {
    nom_ou_rs: lastName, prenom_ou_dc: firstName, type_projet: 'Création', objet: 'Projet synthétique de test de charge',
    adresse: customer.branch.address, ville: customer.branch.ville, code_postal: '1000', activite: 'Services numériques',
    description: 'Données entièrement synthétiques pour validation technique BTS Bank.', delegation: customer.branch.delegation,
    localisation: customer.branch.address, latitude: customer.branch.latitude, longitude: customer.branch.longitude,
    cout: 60000, investissement_personnel: 10000, financement: 50000, revenus: 9000, depenses: 4500,
  });

  const upload = http.post(`${baseUrl}/api/applications/${id}/documents`, {
    document_type: 'other',
    file: http.file(syntheticDocument, `synthetic-${suffix}-${applicationOrdinal}.txt`, 'text/plain'),
  }, { headers: headers(identity, token, false), timeout: '30s', tags: { endpoint: '/api/applications/:id/documents' } });
  recordStatus(upload);
  if (upload.status !== 201) throw new Error(`Document upload failed (${upload.status})`);

  api('POST', `/api/applications/${id}/validation-1`, identity, token, {});
  // In the current BTS domain, validation-2 atomically locks and auto-submits. Calling the
  // legacy explicit submit route afterwards would correctly return APPLICATION_NOT_LOCKED.
  const submitted = api('POST', `/api/applications/${id}/validation-2`, identity, token, {});
  return {
    id,
    applicationNumber: credit.data.application.credit_request.n_demande,
    status: submitted.data.application.status,
    branchId: customer.branch.id,
    branchName: customer.branch.name,
  };
}

function staffReview(application, customer, identity) {
  const staff = manifest.staff_by_branch[String(customer.branch.id)];
  const token = staffLogin(staff.email, `${identity}-staff`);
  api('GET', `/api/staff/applications/${application.id}`, `${identity}-staff`, token);
  const approved = api('POST', `/api/staff/applications/${application.id}/approve`, `${identity}-staff`, token, {});
  if (approved.data.application.status !== 'STAFF_APPROVED') throw new Error('Staff approval did not reach STAFF_APPROVED.');

  const wrongStaff = Object.values(manifest.staff_by_branch).find((entry) => entry.branch_id !== customer.branch.id);
  if (wrongStaff) {
    const wrongToken = staffLogin(wrongStaff.email, `${identity}-wrong-branch`);
    const denied = http.get(`${baseUrl}/api/staff/applications/${application.id}`, { headers: headers(`${identity}-wrong-branch`, wrongToken), tags: { endpoint: '/api/staff/applications/:id/branch-denial' } });
    recordStatus(denied, true);
    const deniedCorrectly = denied.status === 403;
    check(denied, { 'cross-branch access denied': () => deniedCorrectly });
    if (!deniedCorrectly) unauthorizedAccess.add(1);
  }
}

function adminReview(application, identity) {
  const token = staffLogin(manifest.admin.email, `${identity}-admin`, true);
  const approved = api('POST', `/api/staff/applications/${application.id}/admin-approve`, `${identity}-admin`, token, {});
  if (approved.data.application.status !== 'APPOINTMENT_PROPOSED') throw new Error('Admin approval did not create an appointment proposal.');
}

function acceptAppointment(application, token, identity) {
  const current = api('GET', `/api/applications/${application.id}/appointment`, identity, token);
  const appointment = current.data.appointment;
  const today = isoDate(0);
  if (appointment.scheduled_date <= today) throw new Error('Automatic appointment is not after today.');
  const accepted = api('POST', `/api/applications/${application.id}/appointment/accept`, identity, token, {});
  if (accepted.data.appointment.status !== 'accepted') throw new Error('Appointment was not accepted.');
  const refreshed = api('GET', `/api/applications/${application.id}`, identity, token);
  if (refreshed.data.application.status !== 'APPOINTMENT_CONFIRMED') throw new Error('Appointment was not confirmed.');
  application.status = refreshed.data.application.status;
}

function chat(application, token, identity) {
  api('POST', `/api/applications/${application.id}/report/messages`, identity, token, {
    body: `Message synthétique campagne ${manifest.campaign_id}`,
  }, [201]);
  api('GET', `/api/applications/${application.id}/report/messages`, identity, token);
}

function observe(identity) {
  const response = api('GET', '/api/load-test/status', identity);
  const data = response.data;
  const checks = data.health && data.health.checks ? data.health.checks : {};
  const queue = checks.queue || {};
  const queueMatch = String(queue.detail || '').match(/ready=(\d+)/);
  const pendingValue = queueMatch ? Number(queueMatch[1]) : 0;
  if (queueMatch) queuePending.add(pendingValue);
  const failedMatch = String((checks.failed_jobs || {}).detail || '').match(/count=(\d+)/);
  const failedValue = failedMatch ? Number(failedMatch[1]) : 0;
  if (failedMatch) queueFailed.add(failedValue);
  const outboxDetail = String((checks.async_outbox || {}).detail || '');
  const delayMatch = outboxDetail.match(/avg_queue_delay_ms=(\d+)/);
  const runtimeMatch = outboxDetail.match(/avg_runtime_ms=(\d+)/);
  if (delayMatch) queueDelayMs.add(Number(delayMatch[1]));
  if (runtimeMatch) jobRuntimeMs.add(Number(runtimeMatch[1]));
  if (checks.reverb) reverbFailures.add(checks.reverb.ok ? 0 : 1);
  if (data.process) processMemoryBytes.add(Number(data.process.memory_bytes || 0));
  const db = data.database_metrics || {};
  dbConnections.add(Number(db.Threads_connected || 0));
  dbDeadlocks.add(Number(db.Innodb_deadlocks || 0));
  dbLockWaits.add(Number(db.Innodb_row_lock_waits || 0));
  console.log(`BTS_METRIC ${JSON.stringify({ queue_pending: pendingValue, failed_jobs: failedValue, reverb_ok: !checks.reverb || checks.reverb.ok === true })}`);
}

function stopRequested() {
  const response = http.get(`${collectorUrl}/control`, { headers: { Authorization: `Bearer ${__ENV.BTS_OTP_COLLECTOR_TOKEN}` }, timeout: '1s', tags: { endpoint: 'load_control' } });
  return response.status === 200 && response.json('stop') === true;
}

function runRead(customer, identity) {
  if (!readToken) readToken = customerLogin(customer, identity);
  api('GET', '/api/user', identity, readToken);
  api('GET', '/api/applications?per_page=25', identity, readToken);
  api('GET', '/api/notifications?limit=25', identity, readToken);
  observe(identity);
}

export function setup() {
  const response = http.get(`${baseUrl}/api/load-test/status`, { headers: { Accept: 'application/json' }, timeout: '5s' });
  if (response.status !== 200 || response.json('data.marker') !== 'SYNTHETIC_LOAD_TEST_ONLY' || response.json('data.campaign_id') !== manifest.campaign_id) {
    fail('Target refused: it is not the matching isolated synthetic load-test environment.');
  }
  console.log(`BTS_EVENT ${JSON.stringify({ type: 'campaign_started', marker: manifest.marker, campaign_id: manifest.campaign_id, accounts, concurrency, scenario: scenarioName })}`);
  return { ready: true };
}

export default function () {
  if (stopRequested()) { sleep(1); return; }
  const iteration = scenarioName === 'read' ? (exec.vu.idInTest - 1) % accounts : exec.scenario.iterationInTest % accounts;
  const customer = manifest.customers[iteration];
  const identity = `c${customer.ordinal}`;
  if (scenarioName !== 'read' && rampUpSeconds > 0) sleep((iteration / Math.max(accounts - 1, 1)) * rampUpSeconds);
  workflowsStarted.add(1);
  const started = Date.now();
  const requestsBefore = requestCount;
  let success = false;
  let error = null;
  let applications = [];
  try {
    if (scenarioName === 'read') {
      runRead(customer, identity);
    } else {
      const customerToken = customerLogin(customer, identity);
      api('GET', '/api/user', identity, customerToken);
      api('GET', '/api/applications?per_page=25', identity, customerToken);
      if (scenarioName !== 'login') {
        for (let appOrdinal = 1; appOrdinal <= applicationsPerAccount; appOrdinal += 1) {
          const application = createAndSubmit(customer, customerToken, identity, appOrdinal);
          applications.push(application);
          if (['staff_review', 'admin_review', 'appointment', 'chat', 'full'].includes(scenarioName)) staffReview(application, customer, identity);
          if (['admin_review', 'appointment', 'chat', 'full'].includes(scenarioName)) adminReview(application, identity);
          if (['appointment', 'chat', 'full'].includes(scenarioName)) acceptAppointment(application, customerToken, identity);
          if (['chat', 'full'].includes(scenarioName)) chat(application, customerToken, identity);
          if (thinkTimeMs > 0) sleep(thinkTimeMs / 1000);
        }
      }
      observe(identity);
    }
    success = true;
  } catch (exception) {
    error = String(exception && exception.message ? exception.message : exception).slice(0, 300);
  }
  const elapsed = Date.now() - started;
  workflowDuration.add(elapsed);
  workflowsCompleted.add(1);
  workflowSuccessRate.add(success);
  if (success) workflowsSucceeded.add(1); else workflowsFailed.add(1);
  console.log(`BTS_RESULT ${JSON.stringify({ synthetic_user: customer.email, scenario: scenarioName, applications, status: success ? 'success' : 'failure', response_code: success ? 200 : 0, duration_ms: elapsed, requests: requestCount - requestsBefore, error })}`);
}

function metricValue(data, metric, value, fallback = 0) {
  return data.metrics[metric] && data.metrics[metric].values && data.metrics[metric].values[value] !== undefined
    ? data.metrics[metric].values[value] : fallback;
}

function summaryPayload(data) {
  return {
    marker: manifest.marker,
    campaign_id: manifest.campaign_id,
    timestamp: new Date().toISOString(),
    configuration: { scenario: scenarioName, pattern, accounts, concurrency, applications_per_account: applicationsPerAccount, duration, ramp_up_seconds: rampUpSeconds, think_time_ms: thinkTimeMs, seed: manifest.seed },
    metrics: {
      workflows_started: metricValue(data, 'bts_workflows_started', 'count'),
      workflows_completed: metricValue(data, 'bts_workflows_completed', 'count'),
      workflows_succeeded: metricValue(data, 'bts_workflows_succeeded', 'count'),
      workflows_failed: metricValue(data, 'bts_workflows_failed', 'count'),
      workflows_per_second: metricValue(data, 'bts_workflows_completed', 'rate'),
      error_rate: 1 - metricValue(data, 'bts_workflow_success_rate', 'rate', 1),
      requests: metricValue(data, 'bts_api_requests', 'count'),
      requests_per_second: metricValue(data, 'bts_api_requests', 'rate'),
      workflow_average_ms: metricValue(data, 'bts_workflow_duration', 'avg'),
      http_latency_ms: {
        min: metricValue(data, 'bts_api_duration', 'min'), max: metricValue(data, 'bts_api_duration', 'max'),
        average: metricValue(data, 'bts_api_duration', 'avg'), p50: metricValue(data, 'bts_api_duration', 'med'),
        p90: metricValue(data, 'bts_api_duration', 'p(90)'), p95: metricValue(data, 'bts_api_duration', 'p(95)'), p99: metricValue(data, 'bts_api_duration', 'p(99)'),
      },
      http_status: { success_2xx: metricValue(data, 'bts_http_2xx', 'count'), client_4xx: metricValue(data, 'bts_http_4xx', 'count'), server_5xx: metricValue(data, 'bts_http_5xx', 'count'), other: metricValue(data, 'bts_http_other', 'count') },
      timeouts: metricValue(data, 'bts_timeouts', 'count'), validation_failures: metricValue(data, 'bts_validation_failures', 'count'), authentication_failures: metricValue(data, 'bts_authentication_failures', 'count'),
      operations: {
        queue_pending: metricValue(data, 'bts_queue_pending', 'value'),
        failed_jobs: metricValue(data, 'bts_queue_failed', 'value'),
        queue_delay_ms: metricValue(data, 'bts_queue_delay_ms', 'value'),
        job_runtime_ms: metricValue(data, 'bts_job_runtime_ms', 'value'),
        reverb_failures: metricValue(data, 'bts_reverb_failures', 'value'),
        process_memory_bytes: metricValue(data, 'bts_process_memory_bytes', 'value'),
        db_connections: metricValue(data, 'bts_db_connections', 'value'),
        db_deadlocks: metricValue(data, 'bts_db_deadlocks', 'value'),
        db_lock_waits: metricValue(data, 'bts_db_lock_waits', 'value'),
      },
    },
    thresholds: Object.fromEntries(Object.entries(data.metrics).filter(([, metric]) => metric.thresholds).map(([name, metric]) => [name, metric.thresholds])),
  };
}

export function handleSummary(data) {
  const summary = summaryPayload(data);
  const latency = summary.metrics.http_latency_ms;
  const markdown = `# BTS Bank synthetic load test\n\n- Marker: **${summary.marker}**\n- Campaign: \`${summary.campaign_id}\`\n- Scenario: \`${scenarioName}\`\n- Accounts / concurrency: ${accounts} / ${concurrency}\n- Workflows: ${summary.metrics.workflows_completed}, failures: ${summary.metrics.workflows_failed}\n- Requests/s: ${Number(summary.metrics.requests_per_second).toFixed(2)}\n- HTTP p50 / p95 / p99: ${Number(latency.p50).toFixed(1)} / ${Number(latency.p95).toFixed(1)} / ${Number(latency.p99).toFixed(1)} ms\n- Error rate: ${(Number(summary.metrics.error_rate) * 100).toFixed(2)}%\n`;
  const csv = `metric,value\nworkflows_started,${summary.metrics.workflows_started}\nworkflows_completed,${summary.metrics.workflows_completed}\nworkflows_failed,${summary.metrics.workflows_failed}\nrequests,${summary.metrics.requests}\nrequests_per_second,${summary.metrics.requests_per_second}\np50_ms,${latency.p50}\np95_ms,${latency.p95}\np99_ms,${latency.p99}\nerror_rate,${summary.metrics.error_rate}\n`;
  const output = __ENV.BTS_OUTPUT_DIR || '.';
  return {
    [`${output}/summary.json`]: JSON.stringify(summary, null, 2),
    [`${output}/summary.md`]: markdown,
    [`${output}/summary.csv`]: csv,
    stdout: markdown,
  };
}
