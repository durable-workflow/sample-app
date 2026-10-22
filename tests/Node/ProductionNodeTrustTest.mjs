import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const verifier = fileURLToPath(new URL('../../docker/verify-node-trust.mjs', import.meta.url));
const syntheticRoot = fileURLToPath(new URL('../fixtures/security/withdrawn-test-root.pem', import.meta.url));
const cleanEnvironment = { ...process.env };
delete cleanEnvironment.NODE_EXTRA_CA_CERTS;
delete cleanEnvironment.NODE_OPTIONS;
delete cleanEnvironment.NODE_TLS_REJECT_UNAUTHORIZED;

function invoke(environment = {}, preload = '') {
    const arguments_ = preload ? ['--import', `data:text/javascript,${encodeURIComponent(preload)}`] : [];
    return spawnSync(process.execPath, [...arguments_, verifier], {
        env: { ...cleanEnvironment, ...environment }, encoding: 'utf8', timeout: 10_000,
    });
}

test('the actual fixed runtime exposes a nonempty clean default store', () => {
    const result = invoke();
    assert.equal(result.status, 0, result.stderr);
    const evidence = JSON.parse(result.stdout);
    assert.equal(evidence.node, process.versions.node);
    assert(evidence.default_roots > 0);
    assert.equal(evidence.blocked_roots, 0);
});

test('a blocked extra root is detected in the actual child default store', () => {
    const result = invoke({ NODE_EXTRA_CA_CERTS: syntheticRoot });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Blocked certificate in the effective Node default CA store/);
});

test('a changed default store is inspected rather than only the bundled roots', () => {
    const certificate = readFileSync(syntheticRoot, 'utf8');
    const result = invoke({}, `import { setDefaultCACertificates } from 'node:tls'; setDefaultCACertificates([${JSON.stringify(certificate)}]);`);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Blocked certificate in the effective Node default CA store/);
});

test('an empty default store cannot produce a false clean result', () => {
    const result = invoke({}, "import { setDefaultCACertificates } from 'node:tls'; setDefaultCACertificates([]);");
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Node default CA store is empty/);
});

test('TLS verification cannot be disabled', () => {
    const result = invoke({ NODE_TLS_REJECT_UNAUTHORIZED: '0' });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Node TLS verification must remain enabled/);
});
