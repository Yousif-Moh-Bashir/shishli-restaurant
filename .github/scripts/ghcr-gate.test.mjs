import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const sha = 'a'.repeat(40)
const repo = 'Yousif-Moh-Bashir/shishli-restaurant'
const good = {
  workflow_id: 7, path: '.github/workflows/ci.yml', event: 'push', head_branch: 'master',
  head_repository: { full_name: repo }, repository: { full_name: repo },
  status: 'completed', conclusion: 'success', head_sha: sha, run_attempt: 1, id: 99,
}
let sequence = 0
async function exercise(change = {}) {
  const directory = mkdtempSync(join(tmpdir(), 'ghcr-gate-'))
  const previousEnv = { ...process.env }
  const originalFetch = globalThis.fetch
  try {
    const run = { ...good, ...change.run }
    const trigger = { ...good, ...change.trigger }
    const eventPath = join(directory, 'event.json')
    const outputPath = join(directory, 'output')
    writeFileSync(eventPath, JSON.stringify({ workflow_run: trigger }))
    writeFileSync(outputPath, '')
    Object.assign(process.env, { GITHUB_EVENT_PATH: eventPath, GITHUB_EVENT_NAME: change.event || 'workflow_run', GITHUB_REPOSITORY: repo, GH_TOKEN: 'test-only', GITHUB_OUTPUT: outputPath })
    globalThis.fetch = async url => {
      const data = url.endsWith('/actions/runs/99') ? run
        : url.endsWith('/actions/workflows/ci.yml') ? { id: 7 }
          : url.endsWith('/git/ref/heads/master') ? { object: { sha: change.master || sha } }
            : url.includes('/actions/workflows/ci.yml/runs?') ? { workflow_runs: [{ id: change.latestRun || 99 }] }
            : { jobs: ['Laravel / PHP 8.3 / MySQL 8.4', 'Vue / Node 22', 'Docker Compose / production builds'].map(name => ({ name, conclusion: change.jobResult || 'success' })) }
      return { ok: !change.apiError, status: change.apiError || 200, json: async () => data }
    }
    await import(`./ghcr-gate.mjs?case=${sequence++}`)
    assert.equal(readFileSync(outputPath, 'utf8'), `sha=${sha}\n`)
  } finally {
    globalThis.fetch = originalFetch
    process.env = previousEnv
    rmSync(directory, { recursive: true, force: true })
  }
}

test('authorizes the exact current successful master push', () => exercise())
for (const [name, change] of [
  ['PR event', { event: 'pull_request' }],
  ['CI triggered by PR', { run: { event: 'pull_request' } }],
  ['failed CI', { run: { conclusion: 'failure' } }],
  ['unfinished CI', { run: { status: 'in_progress' } }],
  ['different commit', { trigger: { head_sha: 'b'.repeat(40) } }],
  ['old master commit', { master: 'b'.repeat(40) }],
  ['old CI attempt', { trigger: { run_attempt: 2 } }],
  ['older CI run for same SHA', { latestRun: 100 }],
  ['different workflow', { run: { workflow_id: 8 } }],
  ['different branch', { run: { head_branch: 'feature/test' } }],
  ['fork CI', { run: { head_repository: { full_name: 'attacker/fork' } } }],
  ['skipped required job', { jobResult: 'skipped' }],
  ['API unavailable', { apiError: 403 }],
]) {
  test(`rejects ${name}`, () => assert.rejects(exercise(change)))
}
