// `npm audit --audit-level=high`, less a named list of accepted advisories.
//
// Each entry names one advisory by its GHSA id and says why it is accepted.
// Everything else at high or critical still fails the job, including any new
// advisory against a package listed here — the exception is for an advisory,
// never for a package.
//
// An entry nobody reports any more is printed as a warning, so it can be
// deleted rather than linger as a silent hole in the gate.

import { execFileSync } from 'node:child_process';

const ACCEPTED = {
    // braces: stack-exhaustion DoS through deeply nested glob patterns.
    // Every published version is affected and none is patched. It reaches
    // this project only through Tailwind 3's build tooling (chokidar,
    // micromatch, fast-glob), which runs on a developer's machine or CI over
    // patterns this repository writes, and never in a browser or on the
    // server. npm's only fix is Tailwind 4, a breaking upgrade; remove this
    // entry when that lands. Accepted by the owner, October 2026.
    'GHSA-vfj7-8cjw-p6xm': 'braces, via Tailwind 3 build tooling only',
};

const FAILS_AT = new Set(['high', 'critical']);

let report;
try {
    report = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
} catch (error) {
    // npm audit exits non-zero whenever it finds anything; the JSON is still on stdout.
    report = error.stdout;
}

if (!report) {
    console.error('npm audit produced no report.');
    process.exit(1);
}

const { vulnerabilities = {} } = JSON.parse(report);

// The advisories themselves; `via` entries that are strings only point at
// another package whose own entry carries the advisory.
const advisories = new Map();
for (const vulnerability of Object.values(vulnerabilities)) {
    for (const via of vulnerability.via) {
        if (typeof via === 'object') {
            const id = (via.url ?? '').split('/').pop();
            advisories.set(id, { id, name: via.name, severity: via.severity, title: via.title, url: via.url });
        }
    }
}

const failing = [...advisories.values()].filter((a) => FAILS_AT.has(a.severity) && !(a.id in ACCEPTED));
const accepted = [...advisories.values()].filter((a) => a.id in ACCEPTED);
const stale = Object.keys(ACCEPTED).filter((id) => !advisories.has(id));

for (const a of accepted) {
    console.log(`accepted  ${a.severity.padEnd(8)} ${a.id}  ${a.name}: ${ACCEPTED[a.id]}`);
}
for (const id of stale) {
    console.log(`::warning::${id} is accepted in scripts/npm-audit.mjs but no longer reported; delete the entry.`);
}

if (failing.length > 0) {
    for (const a of failing) {
        console.log(`::error::${a.severity} ${a.id} ${a.name}: ${a.title} ${a.url}`);
    }
    process.exit(1);
}

console.log(`No unaccepted high or critical advisories (${advisories.size} reported in all).`);
