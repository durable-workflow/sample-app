import assert from 'node:assert/strict';
import { X509Certificate } from 'node:crypto';
import { rootCertificates } from 'node:tls';

const [major, minor, patch] = process.versions.node.split('.').map(Number);
assert(major === 22 && (minor > 23 || (minor === 23 && patch >= 4)), 'Node 22.23.4 or newer on the 22 line is required');
const blocked = new Set(['CN=AAA Certificate Services', 'CN=emSign ECC Root CA - G3']);
assert(rootCertificates.length > 0, 'Node root store is empty');
for (const pem of rootCertificates) {
    const subject = new X509Certificate(pem).subject.split('\n');
    assert(!subject.some(component => blocked.has(component)), 'Blocked certificate in Node embedded trust');
}
console.log(`Verified Node ${process.versions.node} embedded CA roots`);
