import assert from 'node:assert/strict';
import { X509Certificate } from 'node:crypto';
import * as tls from 'node:tls';

const [major, minor, patch] = process.versions.node.split('.').map(Number);
assert(major === 22 && (minor > 23 || (minor === 23 && patch >= 4)), 'Node 22.23.4 or newer on the 22 line is required');
assert.notEqual(process.env.NODE_TLS_REJECT_UNAUTHORIZED, '0', 'Node TLS verification must remain enabled');
assert.equal(typeof tls.getCACertificates, 'function', 'Node must expose its effective default CA store');

const certificates = tls.getCACertificates('default');
assert(certificates.length > 0, 'Node default CA store is empty');
const blocked = new Set(['AAA Certificate Services', 'emSign ECC Root CA - G3']);
for (const pem of certificates) {
    const certificate = new X509Certificate(pem);
    const commonNames = certificate.toLegacyObject().subject.CN;
    for (const commonName of Array.isArray(commonNames) ? commonNames : [commonNames]) {
        assert(!blocked.has(commonName), 'Blocked certificate in the effective Node default CA store');
    }
}
console.log(JSON.stringify({ node: process.versions.node, architecture: process.arch, default_roots: certificates.length, blocked_roots: 0 }));
