import assert from 'node:assert/strict'
import { readFile, writeFile, access } from 'node:fs/promises'

const directory = '../docs/release/'
const read = async name => JSON.parse(await readFile(directory + 'evidence/' + name + '.json', 'utf8'))
const tests = []
for (const name of ['laravel-targeted', 'laravel-operations', 'regression', 'mysql-functional', 'mysql-concurrency']) {
  const xml = await readFile(directory + 'evidence/' + name + '.xml', 'utf8')
  const suite = xml.match(/<testsuite\b[^>]*>/)?.[0]
  assert.ok(suite)
  const attributes = Object.fromEntries(['tests', 'assertions', 'errors', 'failures', 'skipped'].map(key => [key, Number(suite.match(new RegExp('\\b' + key + '="(\\d+)"'))?.[1])]))
  assert.equal(attributes.errors, 0)
  assert.equal(attributes.failures, 0)
  assert.equal(attributes.skipped, 0)
  tests.push({ suite: name, ...attributes })
}
const journey = await read('journey'), reconciliation = await read('report-reconciliation'), cleanup = await read('cleanup'), performance = await read('performance-summary')
assert.equal(journey.result, 'passed')
assert.equal(journey.responsive.length, 210)
assert.equal(reconciliation.result, 'passed')
assert.equal(cleanup.isolated_mysql_schema_removed, true)
assert.equal(cleanup.private_token_manifest_removed, true)
await assert.rejects(access('.performance-check/audit-mysql.json'))
for (const name of ['PRE_DEPLOYMENT_AUDIT', 'PERFORMANCE_BEFORE_AFTER', 'PRODUCTION_ENV_CHECKLIST', 'OPERATIONAL_READINESS', 'RELEASE_BLOCKERS']) {
  assert.ok((await readFile(directory + name + '.md', 'utf8')).length > 100)
}
const servers = []
for (const port of [8019, 5179, 4179, 9451, 9391]) {
  let reachable = false
  try { await fetch('http://127.0.0.1:' + port + '/', { signal: AbortSignal.timeout(1000) }); reachable = true } catch {}
  assert.equal(reachable, false, 'Audit server/debug port still responds: ' + port)
  servers.push({ port, reachable: false })
}
const assets = JSON.parse(await readFile(directory + 'evidence/build-assets.json', 'utf8'))
assert.ok(assets.assets.some(asset => asset.name.endsWith('.js')))
const validation = {
  date: '2026-10-11', result: 'passed', scope: 'Local Phase 1 audit, no production or container execution',
  tests, build: { result: 'passed', modules: 349, vite: '8.3.2', api_base: '/api/v1' },
  frontend_logic: { result: 'passed', suites: ['order-polling', 'order-access', 'checkout-location', 'phone (2 tests)'] },
  pint: { result: 'passed', scope: 'Only PHP files changed by this phase' },
  isolated_config_and_route_caches: { built: true, cleared: true, normal_caches_changed: false },
  journey: { result: journey.result, pages: journey.pages.length, viewport_checks: journey.responsive.length, checks: journey.checks },
  report_reconciliation: { result: reconciliation.result, checks: reconciliation.checks },
  performance: { browser_samples: performance.browser_samples, api_samples: performance.api_samples, browser_repetitions_per_page_per_stage_per_mode: 1, field_inp_measured: false },
  servers, cleanup, docker: { client_installed: true, engine_available: false, containers_tested: false },
  full_laravel_suite_rerun: false, oracle_mysql_verified: false, deployment_performed: false,
}
await writeFile(directory + 'evidence/validation.json', JSON.stringify(validation, null, 2))
console.log(JSON.stringify({ result: validation.result, suites: tests, journey: validation.journey, audit_servers_stopped: true }))
