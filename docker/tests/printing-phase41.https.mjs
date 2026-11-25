// Actual local TLS -> reverse proxy -> candidate Nginx -> PHP-FPM -> Laravel routes.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, writeFileSync, readFileSync, existsSync } from 'node:fs'
import { resolve, join } from 'node:path'
const root = resolve(import.meta.dirname, '../..')
const release = join(root, 'back-end/storage/framework/testing/printing-phase41-20261115-r1')
const current = process.argv.includes('--current')
const subnet = current ? '198.51.100' : '192.0.2'
const output = current
  ? mkdtempSync(join(root, 'back-end/storage/framework/testing/printing-tls-'))
  : join(release, 'evidence/https-e2e-r2')
if (!current && existsSync(output)) throw Error('TLS evidence already exists; preserve it before a new run.')
mkdirSync(join(output, 'private'), { recursive: true })
const execute = (args, options = {}) => {
  const result = spawnSync('docker', args, { encoding: 'utf8', timeout: 90000, maxBuffer: 2 * 1024 * 1024, ...options })
  if (result.status !== 0) throw Error('Local TLS command failed: ' + args.slice(0, 3).join(' ') + ' exit=' + result.status)
  return result.stdout
}
assert.match(execute(['info', '--format', '{{.OperatingSystem}}']), /Docker Desktop/i)
const manifest = current ? null : JSON.parse(readFileSync(join(release, 'manifest.json')))
const app = current ? 'shishli-app:local' : 'shishli-printing-phase41-app:' + manifest.source_fingerprint
const nginx = current ? 'shishli-nginx:local' : 'shishli-printing-phase41-nginx:' + manifest.source_fingerprint
execute(['run', '--rm', '--pull=never', '--network', 'none', '--mount', `type=bind,src=${join(output, 'private')},dst=/tls`,
  '--entrypoint', 'sh', app, '-c', 'umask 077; openssl req -x509 -newkey rsa:2048 -nodes -keyout /tls/local.key -out /tls/local.crt -days 2 -subj /CN=printing-edge -addext subjectAltName=DNS:printing-edge >/dev/null 2>&1'])
writeFileSync(join(output, 'trusted.conf'), subnet + '.10/32 1;\n')
const headers = 'proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto $scheme; proxy_set_header X-Forwarded-For $remote_addr; proxy_set_header X-Real-IP $remote_addr; proxy_set_header Forwarded ""; proxy_set_header X-Forwarded-Ssl ""; proxy_set_header X-Forwarded-Port "";'
writeFileSync(join(output, 'edge.conf'), `server { listen 80; listen 443 ssl; server_name printing-edge; ssl_certificate /tls/local.crt; ssl_certificate_key /tls/local.key; location / { proxy_pass http://nginx; ${headers} } location = /__failclosed { proxy_pass http://closed/api/v1/print-agent/claim; ${headers} } }\n`)
writeFileSync(join(output, 'testing.env'), 'APP_ENV=testing\n')
const service = (image, host) => ({ image, pull_policy: 'never', networks: { isolated: { ipv4_address: subnet + '.' + host } } })
writeFileSync(join(output, 'compose.json'), JSON.stringify({
  name: current ? 'shishli-printing-current-tls' : 'shishli-printing-phase41-tls', services: {
    app: { ...service(app, 30), environment: { APP_ENV: 'testing', APP_DEBUG: 'false',
      APP_KEY: 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=', ORDER_PRINTING_ENABLED: 'true', ORDER_PRINTING_ALLOW_LIVE: 'false',
      DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync',
      STAFF_NOTIFICATIONS_ENABLED: 'false' }, tmpfs: ['/var/www/html/storage', '/var/www/html/bootstrap/cache'] },
    nginx: { ...service(nginx, 20), depends_on: ['app'], volumes: ['./trusted.conf:/etc/nginx/snippets/shishli-printing-trusted-proxies.conf:ro'] },
    closed: { ...service(nginx, 21), depends_on: ['app'] },
    'printing-edge': { ...service(nginx, 10), depends_on: ['nginx', 'closed'],
      volumes: ['./edge.conf:/etc/nginx/conf.d/default.conf:ro', './private:/tls:ro'],
      healthcheck: { test: ['CMD-SHELL', 'wget -qO- http://127.0.0.1/up >/dev/null'], interval: '1s', timeout: '3s', retries: 40 } },
    client: { ...service(app, 40), entrypoint: ['sleep', 'infinity'], volumes: ['./private/local.crt:/tls/local.crt:ro'] },
  }, networks: { isolated: { internal: true, ipam: { config: [{ subnet: subnet + '.0/24' }] } } },
}, null, 2))
const dc = ['compose', '--env-file', join(output, 'testing.env'), '-f', join(output, 'compose.json')]
assert.equal(execute([...dc, 'ps', '-aq']).trim(), '')
const php = '$c=curl_init($argv[1]); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>"/tls/local.crt",CURLOPT_CUSTOMREQUEST=>$argv[2],CURLOPT_HTTPHEADER=>json_decode($argv[3],true)]); if($argv[2]==="POST")curl_setopt($c,CURLOPT_POSTFIELDS,"{}"); curl_exec($c); echo json_encode(["status"=>curl_getinfo($c,CURLINFO_HTTP_CODE),"tls_error"=>curl_errno($c)]);'
const cases = [
  ['tls-no-token', 'https://printing-edge/api/v1/print-agent/claim', 'POST', [], 401],
  ...(current ? [
    ['tls-connectivity-no-token', 'https://printing-edge/api/v1/print-agent/connectivity', 'POST', [], 401],
    ['http-connectivity-spoof', 'http://printing-edge/api/v1/print-agent/connectivity', 'POST', ['X-Forwarded-Proto: https'], 403],
  ] : []),
  ['tls-spoof-http', 'https://printing-edge/api/v1/print-agent/claim', 'POST', ['X-Forwarded-Proto: http'], 401],
  ['http-spoof-https', 'http://printing-edge/api/v1/print-agent/claim', 'POST', ['X-Forwarded-Proto: https'], 403],
  ['http-forwarded-ssl', 'http://printing-edge/api/v1/print-agent/claim', 'POST', ['Forwarded: proto=https', 'X-Forwarded-Ssl: on'], 403],
  ['http-duplicate-proto', 'http://printing-edge/api/v1/print-agent/claim', 'POST', ['X-Forwarded-Proto: https', 'X-Forwarded-Proto: https'], 403],
  ['http-spoof-peer', 'http://printing-edge/api/v1/print-agent/claim', 'POST', ['X-Real-IP: ' + subnet + '.10', 'X-Forwarded-For: ' + subnet + '.10', 'X-Forwarded-Proto: https'], 403],
  ['direct-nginx-spoof', 'http://nginx/api/v1/print-agent/claim', 'POST', ['X-Forwarded-Proto: https', 'X-Real-IP: ' + subnet + '.10', 'Forwarded: proto=https'], 403],
  ['direct-nginx-plain', 'http://nginx/api/v1/print-agent/claim', 'POST', [], 403],
  ['empty-trust-list', 'https://printing-edge/__failclosed', 'POST', [], 403],
  ['tls-health', 'https://printing-edge/up', 'GET', [], 200],
  ['http-health', 'http://printing-edge/up', 'GET', ['X-Forwarded-Proto: https'], 200],
  ['tls-admin-unauthorized', 'https://printing-edge/api/v1/admin/orders', 'GET', [], 401],
  ['http-admin-unauthorized', 'http://printing-edge/api/v1/admin/orders', 'GET', ['Forwarded: proto=https'], 401],
]
try {
  execute([...dc, 'up', '-d', '--wait', '--wait-timeout', '65'])
  for (const name of ['nginx', 'closed', 'printing-edge']) execute([...dc, 'exec', '-T', name, 'nginx', '-t'])
  const results = []
  for (const [name, url, method, headers, expected] of cases) {
    const result = JSON.parse(execute([...dc, 'exec', '-T', 'client', 'php', '-r', php, url, method, JSON.stringify(['Accept: application/json', 'Content-Type: application/json', ...headers])]))
    assert.equal(result.tls_error, 0, name + ': transport/TLS failure')
    assert.equal(result.status, expected, name)
    results.push({ name, status: result.status, passed: true })
  }
  writeFileSync(join(output, 'result.json'), JSON.stringify({ passed: results.length, cases: results,
    tls_certificate_validation: true, tls_hostname_validation: true, laravel_trusted_proxies_expansion: false,
    real_laravel_routes: true, tokens_created: false, production_changes: false,
    app_image: execute(['image', 'inspect', '--format', '{{.Id}}', app]).trim(),
    nginx_image: execute(['image', 'inspect', '--format', '{{.Id}}', nginx]).trim() }, null, 2) + '\n')
  console.log(JSON.stringify({ passed: results.length, actual_tls_to_laravel: true, forged_headers_rejected: true, production_changes: false }))
} finally { execute([...dc, current ? 'down' : 'stop', '--timeout', '30']) }
