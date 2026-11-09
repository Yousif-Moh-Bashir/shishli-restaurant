// Build a local, explicit overlay from the reviewed production base. Never deploy.
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { resolve, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { isDeepStrictEqual } from 'node:util'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const base = 'b3502bb2c6e7fe400e00c9352c90373968e5a7bd'
const outputName = process.argv[2] || 'web-push-pilot-release'
if (!/^web-push-pilot-release(?:-[a-z0-9-]+)?$/.test(outputName)) throw Error('Invalid local release directory name.')
const output = join(root, 'back-end/storage/framework/testing', outputName)
function git(...args) {
  const result = spawnSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, ...args], { cwd: root, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
  if (result.status !== 0) throw Error('Git read failed: ' + args[0])
  return result.stdout
}
const baseline = path => git('show', `${base}:${path}`)
const sha = data => createHash('sha256').update(data).digest('hex')
function replaceOnce(text, old, replacement) {
  if (text.split(old).length !== 2) throw Error('Release anchor mismatch; review required.')
  return text.replace(old, replacement)
}
function write(path, data) { mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, data) }
function tar(...args) {
  const result = spawnSync('tar', args, { cwd: root, encoding: 'utf8' })
  if (result.status !== 0) throw Error('Archive operation failed: ' + result.stderr)
}
if (git('rev-parse', 'HEAD').trim() !== base) throw Error('Local HEAD differs from reviewed production base.')
if (existsSync(output)) throw Error('Release output already exists; choose a fresh reviewed output directory.')
const files = new Map()
for (const path of [
  'back-end/app/Services/Notifications/StaffWebPushPilot.php',
  'back-end/app/Services/Notifications/StaffWebPushTransport.php',
  'back-end/app/Services/Notifications/StaffPushEndpoints.php',
  'back-end/app/Http/Controllers/Api/V1/Admin/StaffWebPushPilotController.php',
  'back-end/app/Console/Commands/TestStaffWebPushPilot.php',
  'back-end/app/Jobs/SendStaffWebPushPilot.php',
  'back-end/config/staff_web_push_pilot.php',
  'front-end/src/views/admin/WebPushPilotView.vue',
  'front-end/src/api/staffWebPushPilot.api.js',
  'front-end/public/sw.js', 'front-end/public/manifest.webmanifest',
  'docker/compose.web-push-pilot.yaml', 'back-end/composer.json', 'back-end/composer.lock',
]) files.set(path, readFileSync(join(root, path)))
const oldLock = JSON.parse(baseline('back-end/composer.lock'))
const newLock = JSON.parse(files.get('back-end/composer.lock'))
const newPackages = new Map([...newLock.packages, ...newLock['packages-dev']].map(p => [p.name, p]))
for (const p of [...oldLock.packages, ...oldLock['packages-dev']]) {
  if (!isDeepStrictEqual(p, newPackages.get(p.name))) throw Error('Existing locked package changed; review required.')
}
const oldComposer = JSON.parse(baseline('back-end/composer.json'))
oldComposer.require['minishlink/web-push'] = '~11.0'
if (!isDeepStrictEqual(oldComposer, JSON.parse(files.get('back-end/composer.json')))) throw Error('Unrelated Composer change.')
let path = 'back-end/routes/api.php', text = baseline(path)
let anchor = "    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', EnsureAdminAccess::class, RecordAdminActivity::class])->group(function (): void {"
const block = String.raw`
        Route::prefix('web-push-pilot')->name('web-push-pilot.')->middleware(['permission:orders.view', 'throttle:10,1'])->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\StaffWebPushPilotController::class, 'index'])->name('index');
            Route::post('/subscriptions', [\App\Http\Controllers\Api\V1\Admin\StaffWebPushPilotController::class, 'store'])->name('subscribe');
            Route::delete('/subscriptions/{subscription}', [\App\Http\Controllers\Api\V1\Admin\StaffWebPushPilotController::class, 'destroy'])->whereUuid('subscription')->name('unsubscribe');
        });`
files.set(path, Buffer.from(replaceOnce(text, anchor, anchor + block)))
path = 'front-end/src/router/index.js'; text = baseline(path)
anchor = text.split('\n').find(line => line.includes("name: 'admin.order',"))
files.set(path, Buffer.from(replaceOnce(text, anchor, anchor + "\n      { path: 'web-push-pilot', name: 'admin.web-push-pilot', component: () => import('../views/admin/WebPushPilotView.vue'), meta: { adminTitle: 'تجربة إشعارات الجهاز', permission: 'orders.view' } },")))
path = 'front-end/index.html'; text = baseline(path); anchor = '  <meta name="theme-color" content="#351017">'
files.set(path, Buffer.from(replaceOnce(text, anchor, anchor + '\n  <link rel="manifest" href="/manifest.webmanifest">')))
path = 'docker/nginx/default.conf'; text = baseline(path)
const blocks = readFileSync(join(root, path), 'utf8').split(/\r?\n/).filter(line => line.includes('location = /sw.js ') || line.includes('location = /manifest.webmanifest '))
if (blocks.length !== 2) throw Error('Expected two exact static locations.')
anchor = text.split('\n').find(line => line.includes('location = /index.html '))
files.set(path, Buffer.from(replaceOnce(text, anchor, blocks.join('\n') + '\n' + anchor)))
// The reviewed production PHP image and upstream PHP 8.3 FPM already provide curl.
// Preserve the baseline Dockerfile; do not recompile the existing extension.
const manifest = { base_commit: base, automatic_notifications: false, migrations: [], files: [] }
const patches = []
for (const [name, data] of [...files].sort(([a], [b]) => a.localeCompare(b))) {
  write(join(output, 'overlay', name), data)
  let old = '', existed = false; try { old = baseline(name); existed = true } catch {}
  write(join(output, 'baseline', name), old)
  const result = spawnSync('git', ['diff', '--no-index', '--', 'baseline/' + name, 'overlay/' + name], { cwd: output, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
  if (![0, 1].includes(result.status)) throw Error('Review diff failed.')
  patches.push(result.stdout.replaceAll('a/baseline/', 'a/').replaceAll('b/overlay/', 'b/'))
  manifest.files.push({ path: name, sha256: sha(data), expected_before_sha256: existed ? sha(Buffer.from(old)) : null })
}
write(join(output, 'manifest.json'), JSON.stringify(manifest, null, 2))
write(join(output, 'review.patch'), patches.join(''))
const candidate = join(output, 'candidate'); mkdirSync(candidate, { recursive: true })
const archive = join(output, 'base.tar')
git('archive', '--format=tar', '--output=' + archive, base, 'back-end', 'front-end', 'docker', 'compose.yaml', '.dockerignore')
tar('-xf', archive, '-C', candidate)
for (const [name, data] of files) write(join(candidate, name), data)
const test = 'back-end/tests/Feature/StaffWebPushPilotTest.php'
write(join(candidate, test), readFileSync(join(root, test)))
const bundle = join(output, 'web-push-pilot-overlay.tar.gz')
tar('-czf', bundle, '-C', output, 'overlay', 'manifest.json', 'review.patch')
write(join(output, 'SHA256SUMS'), sha(readFileSync(bundle)) + '  web-push-pilot-overlay.tar.gz\n')
console.log(JSON.stringify({ release_directory: output, deployment_files: files.size, base_commit: base, contains_environment_files: false, contains_migrations: false }))
