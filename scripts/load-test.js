/*
 * AdVault load test — docs/12-roadmap.md Phase 7:
 * "Load test: 1000 wall renders/min, 100 postbacks/min sustained."
 *
 * ############################################################################
 * #  POINT THIS AT STAGING. NEVER AT PRODUCTION.                             #
 * #                                                                          #
 * #  Every iteration writes rows: a wall render can create fraud events, and  #
 * #  every postback writes an `incoming_postbacks` row before it has          #
 * #  authenticated anything. Five minutes at these rates is ~5 500 rows of    #
 * #  synthetic traffic in the tables the fraud engine and the network         #
 * #  reconciliation both read. On production you would be poisoning your own  #
 * #  conversion data and rate-limiting real publishers off the wall.          #
 * ############################################################################
 *
 * WHY k6: it is a single static binary, so nothing is installed into this
 * project — no composer package, no node_modules, nothing in the deploy.
 * `constant-arrival-rate` holds a *rate* rather than a concurrency level, which
 * is what "1000 renders/min sustained" actually means, and it reports
 * `dropped_iterations` when the target could not be met — a hand-rolled curl
 * loop silently slows down instead and reports a pass.
 *
 *   curl https://github.com/grafana/k6/releases  →  one binary on PATH
 *
 * RUN:
 *   k6 run scripts/load-test.js \
 *     -e BASE_URL=https://wall.staging.advault.io \
 *     -e APP_ID=10001 \
 *     -e APP_SECRET='...' \
 *     -e DURATION_MIN=5 \
 *     -e DRIVER=notik \
 *     -e POSTBACK_SECRET='...'
 *
 * No credential is hardcoded and none is defaulted. APP_SECRET is `apps.secret_key`
 * for APP_ID on the staging box; POSTBACK_SECRET is that network's
 * `credentials.postback_secret`. Postbacks are skipped entirely if the driver
 * and secret are not supplied, so the wall half can be run on its own.
 *
 * WHAT THE STAGING BOX NEEDS FIRST — without these the run measures refusals:
 *
 *   1. The per-IP wall limiter WILL shed this traffic. AppServiceProvider
 *      registers `wall` at 60/min per IP and 600/min per app, and nginx repeats
 *      the 60/min (deploy/nginx/conf.d/advault-limit-zones.conf). One runner is
 *      one IP, so 1000 renders/min is 940 of them 429ing by design, and even
 *      spread across many IPs the per-app limit caps the app at 600/min. The
 *      limits are hardcoded, not env-tunable: raising them for the run is a code
 *      change on staging. Raise both, or run from enough source IPs and raise
 *      only the per-app one.
 *   2. The network row for DRIVER must be `active`, with this runner's egress IP
 *      in `networks.postback_ips` — an empty allowlist rejects, deliberately.
 *   3. The offer catalogue must be synced, or every render is an empty wall and
 *      the test measures the gate rather than the feed.
 *
 * HONEST LIMITS: the postbacks carry generated click_ids that resolve to no
 * click, so they exercise the log write, the IP allowlist, signature
 * verification and parsing — everything up to the conversion — but not the
 * ledger transaction. Measuring that needs click_ids harvested from real
 * `/click` responses, which is a different (and much more destructive) test.
 */

import http from 'k6/http';
import crypto from 'k6/crypto';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/$/, '');
const APP_ID = __ENV.APP_ID || '';
const APP_SECRET = __ENV.APP_SECRET || '';
const DRIVER = __ENV.DRIVER || '';
const POSTBACK_SECRET = __ENV.POSTBACK_SECRET || '';

// Whole minutes, so "achieved rate per minute" is a division and not a parse.
const MINUTES = Number(__ENV.DURATION_MIN || 5);
const WALL_RATE = Number(__ENV.WALL_RATE || 1000);
const POSTBACK_RATE = Number(__ENV.POSTBACK_RATE || 100);

// A real mid-range Android UA: the wall resolves device from it and filters
// offers on the answer, so a default k6 UA would test the unplaced-device path.
const USER_AGENT =
    'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

const wallRequests = new Counter('wall_requests');
const wallErrors = new Rate('wall_errors');
const postbackRequests = new Counter('postback_requests');
const postbackErrors = new Rate('postback_errors');

if (!BASE_URL || !APP_ID || !APP_SECRET) {
    throw new Error('BASE_URL, APP_ID and APP_SECRET are required: k6 run scripts/load-test.js -e BASE_URL=... -e APP_ID=... -e APP_SECRET=...');
}

const postbacksEnabled = DRIVER !== '' && POSTBACK_SECRET !== '';

// 95% of target, because the last few iterations of a run land after the clock
// stops. Anything below this did not sustain the rate.
const wallFloor = Math.floor(WALL_RATE * MINUTES * 0.95);
const postbackFloor = Math.floor(POSTBACK_RATE * MINUTES * 0.95);

const scenarios = {
    wall: {
        executor: 'constant-arrival-rate',
        rate: WALL_RATE,
        timeUnit: '1m',
        duration: `${MINUTES}m`,
        preAllocatedVUs: 40,
        maxVUs: 300,
        exec: 'wall',
    },
};

if (postbacksEnabled) {
    scenarios.postbacks = {
        executor: 'constant-arrival-rate',
        rate: POSTBACK_RATE,
        timeUnit: '1m',
        duration: `${MINUTES}m`,
        preAllocatedVUs: 10,
        maxVUs: 60,
        exec: 'postback',
    };
}

export const options = {
    scenarios,
    thresholds: {
        // The two Phase 7 numbers, as pass/fail conditions on the exit code
        // rather than as something to eyeball in the summary.
        wall_requests: [`count>=${wallFloor}`],
        wall_errors: ['rate<0.01'],
        // k6 could not start an iteration on time — the box is the bottleneck,
        // or the runner is. Either way the rate was not sustained.
        dropped_iterations: ['count==0'],
        'http_req_duration{scenario:wall}': ['p(95)<1000'],
        ...(postbacksEnabled
            ? {
                  postback_requests: [`count>=${postbackFloor}`],
                  postback_errors: ['rate<0.01'],
              }
            : {}),
    },
};

function hex(bytes) {
    let out = '';
    for (let i = 0; i < bytes; i++) {
        out += Math.floor(Math.random() * 256).toString(16).padStart(2, '0');
    }
    return out;
}

/** Shaped like the UUIDv4 the click pipeline issues, so the driver parses it. */
function clickId() {
    return `${hex(4)}-${hex(2)}-4${hex(2).slice(1)}-a${hex(2).slice(1)}-${hex(6)}`;
}

export function wall() {
    // A spread of end users: one userid would collide on the per-user caps in
    // the fraud engine and measure the ban path instead of the render path.
    const userId = `LOADTEST_${__VU}_${__ITER}`;
    const sig = crypto.sha256(`${APP_ID}${userId}${APP_SECRET}`, 'hex');

    const response = http.get(
        `${BASE_URL}/wall?appid=${APP_ID}&userid=${userId}&sig=${sig}`,
        { headers: { 'User-Agent': USER_AGENT }, tags: { name: 'wall' } }
    );

    const ok = check(response, {
        'wall 200': (r) => r.status === 200,
        'wall not rate limited': (r) => r.status !== 429,
    });

    wallRequests.add(1);
    wallErrors.add(!ok);
}

export function postback() {
    // Unique per iteration. A repeated trans_id is caught by the idempotency
    // index and dropped, which would turn this into a benchmark of the
    // duplicate path rather than of the conversion path.
    const transId = `LOADTEST-${Date.now()}-${__VU}-${__ITER}`;
    const payout = '0.42';
    const signature = crypto.md5(`${transId}${payout}${POSTBACK_SECRET}`, 'hex');

    const query = [
        `trans_id=${transId}`,
        `payout=${payout}`,
        `subid1=${clickId()}`,
        'status=1',
        `signature=${signature}`,
        'offer_id=LOADTEST',
        `user_id=LOADTEST_${__VU}`,
    ].join('&');

    const response = http.get(`${BASE_URL}/postback/${DRIVER}?${query}`, {
        tags: { name: 'postback' },
    });

    // 403 = source IP not allowlisted, or the signature recipe does not match
    // this driver. 404 = no network row for DRIVER, or it is not active.
    const ok = check(response, {
        'postback 200': (r) => r.status === 200,
    });

    postbackRequests.add(1);
    postbackErrors.add(!ok);
}

export function handleSummary(data) {
    const count = (metric) => (data.metrics[metric] ? data.metrics[metric].values.count || 0 : 0);
    const rate = (metric) => (data.metrics[metric] ? data.metrics[metric].values.rate || 0 : 0);

    const lines = [''];
    const verdict = (label, achieved, target) =>
        `${achieved >= target * 0.95 ? 'PASS' : 'FAIL'}  ${label}: ${achieved.toFixed(0)}/min achieved, ${target}/min required`;

    lines.push('AdVault load test — docs/12 Phase 7');
    lines.push('='.repeat(60));
    lines.push(verdict('wall renders', count('wall_requests') / MINUTES, WALL_RATE));
    lines.push(`      error rate: ${(rate('wall_errors') * 100).toFixed(2)}%  (429s count as errors — see the header)`);

    if (postbacksEnabled) {
        lines.push(verdict('postbacks', count('postback_requests') / MINUTES, POSTBACK_RATE));
        lines.push(`      error rate: ${(rate('postback_errors') * 100).toFixed(2)}%`);
    } else {
        lines.push('SKIP  postbacks: DRIVER and POSTBACK_SECRET not supplied');
    }

    lines.push(`      dropped iterations: ${count('dropped_iterations')} (any is a rate that was not sustained)`);

    // Absent entirely if not one request was made — a run that died on the
    // first connection should say so, not throw inside its own summary.
    const p95 = data.metrics.http_req_duration ? data.metrics.http_req_duration.values['p(95)'] || 0 : 0;
    lines.push(`      p95 request duration: ${p95.toFixed(0)} ms`);
    lines.push('='.repeat(60));
    lines.push('');

    // k6 exits non-zero on a breached threshold, so the exit code already
    // carries the verdict — this is the human-readable half.
    return { stdout: lines.join('\n') };
}
