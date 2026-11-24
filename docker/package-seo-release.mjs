import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { constants, copyFileSync, mkdtempSync, mkdirSync, readFileSync, rmSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { tmpdir } from 'node:os'
import { assertLinuxLineEndings } from './check-linux-line-endings.mjs'

const [ref, archive, ...extra] = process.argv.slice(2)
if (!ref || !archive?.endsWith('.tar.gz') || extra.length) {
  throw Error('Usage: node docker/package-seo-release.mjs REF OUTPUT.tar.gz')
}

const root = resolve('.')
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`,
  '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', ...args], { cwd: root })
const commit = git('rev-parse', '--verify', '--end-of-options', `${ref}^{commit}`).toString().trim()
const paths = ['.dockerignore', 'back-end', 'docker/nginx', 'docker/php', 'front-end/index.html',
  'front-end/package-lock.json', 'front-end/package.json', 'front-end/public', 'front-end/src', 'front-end/vite.config.js']
const entries = git('ls-tree', '-r', '-z', commit, '--', ...paths).toString().split('\0').filter(Boolean)
for (const entry of entries) {
  const separator = entry.indexOf('\t')
  const metadata = entry.slice(0, separator)
  const path = entry.slice(separator + 1)
  if (!/^100(?:644|755) blob /.test(metadata) ||
      path.toLowerCase().split('/').some(segment => segment === 'database.sqlite' || segment === '.env' ||
        (segment.startsWith('.env.') && !segment.endsWith('.example')))) {
    throw Error(`Unexpected release input: ${path}`)
  }
}

const scratch = mkdtempSync(join(tmpdir(), 'shishli-seo-package-'))
try {
  const stagedArchive = join(scratch, 'source.tar.gz')
  const extracted = join(scratch, 'source')
  mkdirSync(extracted)
  git('archive', '--format=tar.gz', `--output=${stagedArchive}`, commit, '--', ...paths)
  execFileSync('tar', ['-xzf', stagedArchive, '-C', extracted], { stdio: 'inherit' })
  const count = assertLinuxLineEndings(extracted)
  if (!count) throw Error('No Linux build files found in source archive')
  copyFileSync(stagedArchive, resolve(archive), constants.COPYFILE_EXCL)
  const sha256 = createHash('sha256').update(readFileSync(stagedArchive)).digest('hex')
  console.log(JSON.stringify({ commit, archive, sha256, checkedFiles: count }))
} finally {
  rmSync(scratch, { recursive: true, force: true })
}
