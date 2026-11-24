import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { assertLinuxLineEndings } from '../check-linux-line-endings.mjs'

test('release gate checks LF for shell, shebang and container files', () => {
  const root = mkdtempSync(join(tmpdir(), 'shishli-lf-test-'))
  function write(path, bytes) {
    const target = join(root, path)
    mkdirSync(dirname(target), { recursive: true })
    writeFileSync(target, bytes)
  }

  try {
    write('docker/php/entrypoint.sh', '#!/bin/sh\nexec "$@"\n')
    write('tools/release', '#!/usr/bin/env bash\nexit 0\n')
    write('docker/php/Dockerfile', 'FROM scratch\n')
    write('docker/compose.local.yaml', 'services: {}\n')
    write('windows.cmd', '@echo off\r\n')
    assert.equal(assertLinuxLineEndings(root), 4)

    write('docker/php/entrypoint.sh', '#!/bin/sh\r\nexec "$@"\n')
    write('tools/release', '#!/usr/bin/env bash\r\nexit 0\r\n')
    write('docker/compose.local.yaml', 'services: {}\r')
    assert.throws(() => assertLinuxLineEndings(root), error => {
      assert.match(error.message, /entrypoint\.sh/)
      assert.match(error.message, /tools[\\/]release/)
      assert.match(error.message, /compose\.local\.yaml/)
      assert.doesNotMatch(error.message, /windows\.cmd/)
      return true
    })
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('packaging rejects CRLF stored in a Git commit before publishing the archive', () => {
  const root = mkdtempSync(join(tmpdir(), 'shishli-package-test-'))
  const output = join(root, 'release.tar.gz')
  const packager = fileURLToPath(new URL('../package-seo-release.mjs', import.meta.url))
  const git = (...args) => execFileSync('git', ['-c', 'core.autocrlf=false',
    '-c', 'user.name=Release Test', '-c', 'user.email=release-test@example.test', ...args], { cwd: root })
  try {
    git('init', '-q')
    git('config', 'core.autocrlf', 'true')
    const entries = ['.dockerignore', 'back-end/.env.production.example', 'docker/nginx/Dockerfile',
      'docker/php/entrypoint.sh', 'front-end/index.html', 'front-end/package-lock.json',
      'front-end/package.json', 'front-end/public/robots.txt', 'front-end/src/main.js', 'front-end/vite.config.js']
    for (const path of entries) {
      mkdirSync(dirname(join(root, path)), { recursive: true })
      writeFileSync(join(root, path), '\n')
    }
    const entrypoint = join(root, 'docker/php/entrypoint.sh')
    writeFileSync(entrypoint, '#!/bin/sh\r\nexit 0\r\n')
    git('add', '.')
    git('commit', '-qm', 'Fixture with CRLF')
    const rejected = spawnSync(process.execPath, [packager, 'HEAD', output], { cwd: root, encoding: 'utf8' })
    assert.equal(rejected.status, 1, rejected.stderr)
    assert.match(rejected.stderr, /Expected LF line endings/)
    assert.match(rejected.stderr, /entrypoint\.sh/)
    assert.equal(existsSync(output), false)

    writeFileSync(entrypoint, '#!/bin/sh\nexit 0\n')
    git('add', 'docker/php/entrypoint.sh')
    git('commit', '-qm', 'Fix fixture line endings')
    const accepted = execFileSync(process.execPath, [packager, 'HEAD', output], { cwd: root, encoding: 'utf8' })
    assert.match(JSON.parse(accepted).sha256, /^[a-f0-9]{64}$/)
    assert.equal(existsSync(output), true)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
