import { execFileSync } from 'node:child_process'
import { openSync, closeSync, lstatSync, readFileSync, readdirSync, readSync } from 'node:fs'
import { basename, join, relative, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const shellExtension = /\.(?:sh|bash|dash|ash|ksh|zsh|bats)$/i
const shellShebang = /^#![^\r\n]*[\s/](?:sh|bash|dash|ash|ksh|zsh|bats)(?=\s|$)/
const containerFile = /^(?:(?:.+\.)?Dockerfile(?:\..+)?|.*\.dockerignore|(?:docker-)?compose[^/]*\.(?:ya?ml|json))$/i
const containerConfig = /^docker\/.*\.(?:conf|ini)$/i
const skippedDirectories = new Set(['.git', 'node_modules', 'vendor'])

function hasShellShebang(file) {
  const prefix = Buffer.alloc(256)
  const fd = openSync(file, 'r')
  try {
    const size = readSync(fd, prefix)
    return shellShebang.test(prefix.toString('utf8', 0, size))
  } finally {
    closeSync(fd)
  }
}

export function linuxFiles(root, paths) {
  return paths.filter(path => {
    const file = join(root, path)
    const stat = lstatSync(file, { throwIfNoEntry: false })
    return stat?.isFile() && (shellExtension.test(path) || containerFile.test(basename(path)) ||
      containerConfig.test(path.replaceAll('\\', '/')) || hasShellShebang(file))
  })
}

export function gitPaths(root) {
  const args = ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, 'ls-files', '-z', '--cached', '--others', '--exclude-standard']
  return [...new Set(execFileSync('git', args, { cwd: root }).toString('utf8').split('\0').filter(Boolean))]
}

function treePaths(root, dir = root) {
  return readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const path = join(dir, entry.name)
    if (entry.isDirectory() && !skippedDirectories.has(entry.name)) return treePaths(root, path)
    return entry.isFile() ? [relative(root, path)] : []
  })
}

export function assertLinuxLineEndings(root, paths = treePaths(root)) {
  const files = linuxFiles(root, paths)
  const invalid = files.filter(path => readFileSync(join(root, path)).includes(13))
  if (invalid.length) throw Error(`Expected LF line endings:\n${invalid.sort().map(path => `  ${path}`).join('\n')}`)
  return files.length
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const args = process.argv.slice(2)
    if (args.length && (args.length !== 2 || args[0] !== '--directory')) {
      throw Error('Usage: node docker/check-linux-line-endings.mjs [--directory PATH]')
    }
    const root = resolve(args[1] ?? '.')
    const count = assertLinuxLineEndings(root, args.length ? undefined : gitPaths(root))
    console.log(`LF check passed for ${count} Shell and Docker files.`)
  } catch (error) {
    console.error(error.message)
    process.exitCode = 1
  }
}
