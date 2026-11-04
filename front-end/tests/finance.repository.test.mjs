import assert from 'node:assert/strict'
import { test } from 'node:test'
import { execFileSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const git = args => execFileSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/').replace(/\/$/, '')}`, '-c', 'core.quotepath=false', ...args], { cwd: root, encoding: 'utf8' })
test('browser sessions and actual environment files remain outside the tracked release', { skip: !existsSync(new URL('../../.git', import.meta.url)) }, () => {
  const profiles = ['front-end/.delivery-ux-check/live/profile', 'front-end/.delivery-ux-check/profile', 'Şişli/.preview/chrome-check', 'Şişli/.preview/chrome']
  assert.equal(git(['ls-files', '--', ...profiles]).trim(), '')
  const tracked = git(['ls-files', '-z']).split('\0').filter(Boolean)
  assert.deepEqual(tracked.filter(p => /(^|\/)\.env(?:\..*)?$/.test(p) && !p.endsWith('.example')), [])
  for (const profile of profiles) {
    assert.ok(git(['check-ignore', '--no-index', '--', `${profile}/Default/Web Data`]).trim())
  }
})
