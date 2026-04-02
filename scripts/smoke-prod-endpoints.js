#!/usr/bin/env node

const https = require('https');
const { URL } = require('url');
const vm = require('vm');

const BASE_URL = process.env.BOBER_BASE_URL || 'https://bober-api.gt.tc';
const USER_AGENT = process.env.BOBER_SMOKE_UA || 'Mozilla/5.0';

function request(url, options = {}) {
    const target = new URL(url);
    const method = String(options.method || 'GET').toUpperCase();
    const body = typeof options.body === 'string' ? options.body : '';
    const headers = Object.assign(
        {
            'user-agent': USER_AGENT,
        },
        options.headers || {}
    );

    if (body !== '' && !headers['content-length']) {
        headers['content-length'] = Buffer.byteLength(body);
    }

    return new Promise((resolve, reject) => {
        const req = https.request(
            {
                protocol: target.protocol,
                hostname: target.hostname,
                port: target.port || undefined,
                path: target.pathname + target.search,
                method,
                headers,
            },
            (res) => {
                let data = '';
                res.on('data', (chunk) => {
                    data += chunk;
                });
                res.on('end', () => {
                    resolve({
                        status: Number(res.statusCode) || 0,
                        headers: res.headers || {},
                        body: data,
                    });
                });
            }
        );

        req.on('error', reject);

        if (body !== '') {
            req.write(body);
        }

        req.end();
    });
}

function appendChallengeQuery(path, step) {
    const target = new URL(path, BASE_URL);
    target.searchParams.set('i', String(step));
    return target.toString();
}

function parseChallengeTriplet(html) {
    const triplet = {};
    for (const key of ['a', 'b', 'c']) {
        const match = String(html || '').match(new RegExp(`${key}=toNumbers\\("([0-9a-f]+)"\\)`));
        triplet[key] = match ? match[1] : '';
    }

    return triplet.a && triplet.b && triplet.c ? triplet : null;
}

function buildAesSolver(sourceCode) {
    const context = {
        slowAES: null,
        console,
    };
    vm.createContext(context);
    vm.runInContext(sourceCode, context, { timeout: 5000 });
    if (!context.slowAES || typeof context.slowAES.decrypt !== 'function') {
        throw new Error('Не удалось инициализировать slowAES.');
    }
    return context.slowAES;
}

function toNumbers(hex) {
    const values = [];
    String(hex || '').replace(/(..)/g, (chunk) => {
        values.push(Number.parseInt(chunk, 16));
        return chunk;
    });
    return values;
}

function toHex(values) {
    return values
        .map((value) => {
            const normalized = Math.max(0, Math.min(255, Number(value) || 0));
            return `${normalized < 16 ? '0' : ''}${normalized.toString(16)}`;
        })
        .join('')
        .toLowerCase();
}

async function resolveChallengeCookie(path, slowAes) {
    const challengeUrl = appendChallengeQuery(path, 1);
    const response = await request(challengeUrl);
    const triplet = parseChallengeTriplet(response.body);
    if (!triplet) {
        return {
            cookie: '',
            url: challengeUrl,
        };
    }

    const cookieValue = toHex(
        slowAes.decrypt(toNumbers(triplet.c), 2, toNumbers(triplet.a), toNumbers(triplet.b))
    );

    return {
        cookie: `__test=${cookieValue}`,
        url: appendChallengeQuery(path, 2),
    };
}

function normalizeBody(body) {
    return body === undefined || body === null ? '' : JSON.stringify(body);
}

function buildSpecs(publicProfileUserId) {
    const profilePath = publicProfileUserId > 0
        ? `/api/leaderboards/player-profile.php?userId=${publicProfileUserId}`
        : '/api/leaderboards/player-profile.php?userId=1';

    return [
        {
            name: 'health',
            path: '/api/health/check.php',
            method: 'GET',
            expectedStatuses: [200, 503],
            expectJson: true,
        },
        {
            name: 'state.sync',
            path: '/api/state/sync.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'state.save',
            path: '/api/state/save.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'catalog.skin',
            path: '/api/catalog/skin-catalog.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'leaderboards.main',
            path: '/api/leaderboards/main.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'leaderboards.player-profile',
            path: profilePath,
            method: 'GET',
            expectedStatuses: [200, 404],
            expectJson: true,
        },
        {
            name: 'auth.session',
            path: '/api/auth/session.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'auth.login',
            path: '/api/auth/login.php',
            method: 'POST',
            body: { login: '', password: '' },
            expectedStatuses: [400],
            expectJson: true,
        },
        {
            name: 'auth.register',
            path: '/api/auth/register.php',
            method: 'POST',
            body: { login: '', password: '' },
            expectedStatuses: [400],
            expectJson: true,
        },
        {
            name: 'auth.logout',
            path: '/api/auth/logout.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'support.tickets',
            path: '/api/support/tickets.php',
            method: 'POST',
            body: { action: 'list_tickets' },
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'sessions.game',
            path: '/api/sessions/game-sessions.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'fly.session',
            path: '/api/fly/session.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'fly.save-run',
            path: '/api/fly/save-run.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'fly.claim-reward',
            path: '/api/fly/claim-reward.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'logs.client-log',
            path: '/api/logs/client-log.php',
            method: 'POST',
            body: { clientLogBatch: [] },
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'logs.anti-cheat-report',
            path: '/api/logs/anti-cheat-report.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.leaderboard',
            path: '/leaderboard.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'compat.sync-state',
            path: '/sync-state.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'compat.save-state',
            path: '/save-state.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.save-energy',
            path: '/save-energy.php',
            method: 'GET',
            expectedStatuses: [410],
            expectJson: true,
        },
        {
            name: 'compat.save-plus',
            path: '/save-plus.php',
            method: 'GET',
            expectedStatuses: [410],
            expectJson: true,
        },
        {
            name: 'compat.save-score',
            path: '/save-score.php',
            method: 'GET',
            expectedStatuses: [410],
            expectJson: true,
        },
        {
            name: 'compat.save-skin',
            path: '/save-skin.php',
            method: 'GET',
            expectedStatuses: [410],
            expectJson: true,
        },
        {
            name: 'compat.support-tickets',
            path: '/support-tickets.php',
            method: 'POST',
            body: { action: 'list_tickets' },
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.game-sessions',
            path: '/game-sessions.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.fly-session',
            path: '/fly-session.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.fly-save-run',
            path: '/fly-save-run.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.fly-claim-reward',
            path: '/fly-claim-reward.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.login',
            path: '/login-t.php',
            method: 'POST',
            body: { login: '', password: '' },
            expectedStatuses: [400],
            expectJson: true,
        },
        {
            name: 'compat.register',
            path: '/register-t.php',
            method: 'POST',
            body: { login: '', password: '' },
            expectedStatuses: [400],
            expectJson: true,
        },
        {
            name: 'compat.logout',
            path: '/logout-t.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
        {
            name: 'compat.session',
            path: '/session-t.php',
            method: 'GET',
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.client-log',
            path: '/client-log.php',
            method: 'POST',
            body: { clientLogBatch: [] },
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.anti-cheat-report',
            path: '/anti-cheat-report.php',
            method: 'POST',
            body: {},
            expectedStatuses: [401],
            expectJson: true,
        },
        {
            name: 'compat.skin-catalog',
            path: '/skin-catalog.php',
            method: 'GET',
            expectedStatuses: [200],
            expectJson: true,
        },
    ];
}

function buildRequestHeaders(spec, cookie) {
    const headers = {};
    if (cookie) {
        headers.cookie = cookie;
    }
    if (spec.method === 'POST') {
        headers['content-type'] = 'application/json';
    }
    return headers;
}

async function resolvePublicProfileUserId(slowAes) {
    const challenge = await resolveChallengeCookie('/api/leaderboards/main.php', slowAes);
    const response = await request(challenge.url, {
        headers: buildRequestHeaders({ method: 'GET' }, challenge.cookie),
    });
    const data = JSON.parse(response.body);
    const first = Array.isArray(data) ? data[0] : null;
    return Math.max(0, Number(first && first.userId) || 0);
}

function isExpectedStatus(spec, status) {
    return Array.isArray(spec.expectedStatuses) && spec.expectedStatuses.includes(status);
}

function summarizeBody(body) {
    return String(body || '').replace(/\s+/g, ' ').slice(0, 180);
}

async function run() {
    const aesSource = (await request(`${BASE_URL}/aes.js`)).body;
    const slowAes = buildAesSolver(aesSource);
    const publicProfileUserId = await resolvePublicProfileUserId(slowAes);
    const specs = buildSpecs(publicProfileUserId);
    let failures = 0;

    console.log(`Smoke base: ${BASE_URL}`);
    console.log(`Public profile probe userId: ${publicProfileUserId || 'fallback'}`);

    for (const spec of specs) {
        try {
            const challenge = await resolveChallengeCookie(spec.path, slowAes);
            const response = await request(challenge.url, {
                method: spec.method,
                headers: buildRequestHeaders(spec, challenge.cookie),
                body: normalizeBody(spec.body),
            });

            const contentType = String(response.headers['content-type'] || '');
            const expectJson = spec.expectJson === true;
            const contentTypeOk = !expectJson || contentType.includes('application/json');
            const statusOk = isExpectedStatus(spec, response.status);
            const ok = statusOk && contentTypeOk;

            if (!ok) {
                failures += 1;
            }

            console.log(
                `${ok ? 'OK ' : 'ERR'} ${spec.name} :: status=${response.status} content-type=${contentType || '-'}`
            );

            if (!ok) {
                console.log(`    preview: ${summarizeBody(response.body)}`);
            }
        } catch (error) {
            failures += 1;
            console.log(`ERR ${spec.name} :: ${error.message}`);
        }
    }

    if (failures > 0) {
        console.error(`Smoke failed: ${failures} route(s) outside expected status/content-type.`);
        process.exitCode = 1;
        return;
    }

    console.log('Smoke passed.');
}

run().catch((error) => {
    console.error(`Fatal smoke failure: ${error.message}`);
    process.exit(1);
});
