#!/usr/bin/env node
/**
 * Run Lighthouse over the pages that matter and assert the thresholds in
 * lighthouse-budget.json — §10.1.
 *
 * `npm run lighthouse` existed and CI never ran it, so nothing stopped an
 * accessibility or SEO regression reaching the site. The first run of this
 * found two: a "Learn More" link that tells a screen-reader user nothing,
 * and a footer heading that skipped a level on any page with no h2 of its
 * own.
 *
 * Usage:  node scripts/lighthouse-check.mjs [baseUrl]
 * Default base URL is http://127.0.0.1:8000.
 */

import { execFileSync } from 'node:child_process'
import { readFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const base = (process.argv[2] ?? 'http://127.0.0.1:8000').replace(/\/$/, '')
const budget = JSON.parse(readFileSync('lighthouse-budget.json', 'utf8'))
const out = mkdtempSync(join(tmpdir(), 'lh-'))

/**
 * Run one audit, retrying once if Chrome would not start.
 *
 * **This cannot hide a regression, and that is the whole reason it is
 * safe.** A page that scores below its threshold exits *zero* and writes a
 * report — the thresholds below are checked against that report, and a
 * failure there never reaches this function. The only thing that throws
 * here is the audit failing to happen at all: Chrome not launching, npx
 * not fetching, the runner dying. So a retry re-attempts a measurement
 * that was never taken; it never re-rolls one that came back bad.
 *
 * Added because that is exactly what happened: "Unable to connect to
 * Chrome" on the first URL, with the site up and serving `/en` in 500 ms,
 * and orphaned chrome and crashpad processes at cleanup. Headless Chrome
 * not starting on a shared runner is a known, occasional thing, and one
 * retry is the difference between a gate people trust and a gate people
 * learn to re-run by hand — which AGENTS.md notes is worse than no gate,
 * because a gate people ignore stops being read at all.
 *
 * One retry, not three: if Chrome cannot start twice, something is wrong
 * that waiting will not fix, and the job should say so.
 */
function audit(url, file) {
  const run = () => execFileSync(
    'npx',
    [
      '--yes', 'lighthouse@' + budget.lighthouseVersion, url,
      '--chrome-flags=--headless --no-sandbox --disable-gpu --disable-dev-shm-usage',
      '--only-categories=' + Object.keys(budget.categories).join(','),
      '--output=json', '--output-path=' + file, '--quiet',
    ],
    {
      stdio: ['ignore', 'ignore', 'inherit'],
      // GitHub's ubuntu-latest runner ships Chrome and chrome-launcher
      // finds it. A sandbox or a container may not, so an explicit
      // CHROME_PATH is honoured rather than required.
      env: process.env,
    },
  )

  try {
    run()
  } catch (first) {
    process.stderr.write(`  Lighthouse did not run for ${url}; retrying once.\n`)

    // Synchronous on purpose: the whole script is, and a crashed Chrome
    // leaves a crashpad handler behind that wants a moment to go.
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 5000)

    run()
  }
}

const failures = []
const warnings = []
const rows = []

for (const path of budget.urls) {
  const url = base + path
  const file = join(out, path.replace(/\W+/g, '_') + '.json')

  process.stderr.write(`Auditing ${url}\n`)

  audit(url, file)

  const report = JSON.parse(readFileSync(file, 'utf8'))
  const row = { url: path }

  for (const [name, rule] of Object.entries(budget.categories)) {
    const score = report.categories[name]?.score ?? 0
    row[name] = Math.round(score * 100)

    if (score >= rule.min) continue

    // Name the audits that caused it, so the failure is actionable from
    // the log rather than only from the uploaded report.
    const guilty = report.categories[name].auditRefs
      .filter((ref) => (ref.weight ?? 0) > 0)
      .map((ref) => report.audits[ref.id])
      .filter((audit) => audit.score !== null && audit.score < 1)
      .map((audit) => `      ${audit.id}: ${audit.title}`)

    const message = [
      `${path} — ${name} scored ${Math.round(score * 100)}, below ${Math.round(rule.min * 100)}`,
      ...guilty,
    ].join('\n')

    ;(rule.level === 'warn' ? warnings : failures).push(message)
  }

  // The §10.1 metrics the plan named and nothing ever reported.
  for (const [id, rule] of Object.entries(budget.metrics ?? {})) {
    const audit = report.audits[id]

    if (!audit || audit.numericValue == null) continue

    row[rule.label] = audit.displayValue ?? audit.numericValue

    if (audit.numericValue <= rule.max) continue

    const message =
      `${path} — ${audit.title} is ${audit.displayValue}, over ${rule.max}${rule.unit}`

    ;(rule.level === 'warn' ? warnings : failures).push(message)
  }

  const bytes = Math.round(report.audits['total-byte-weight']?.numericValue ?? 0)
  row.KB = Math.round(bytes / 1024)

  if (bytes > budget.maxBytes) {
    failures.push(
      `${path} — weighs ${Math.round(bytes / 1024)} KB, over the ` +
      `${Math.round(budget.maxBytes / 1024)} KB budget. Usually an unoptimised image.`,
    )
  }

  rows.push(row)
}

console.table(rows)

for (const warning of warnings) {
  console.warn('\n::warning::' + warning)
}

if (failures.length > 0) {
  for (const failure of failures) {
    console.error('\n::error::' + failure)
  }

  process.exit(1)
}

console.log('\nEvery page is within budget.')
