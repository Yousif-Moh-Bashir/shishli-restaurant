import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { discovery, records, jsonl, hex } from './backup-fixtures.mjs'

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..')
const bash=process.env.BACKUP_TEST_BASH||'C:/Program Files/Git/bin/bash.exe'
const php=process.env.BACKUP_TEST_PHP||'C:/laragon/bin/php/php-8.3.29-nts-Win32-vs16-x64/php.exe'
const tar=process.env.BACKUP_TEST_TAR||'C:/Program Files/Git/usr/bin/tar.exe'
const run='20261108T220000Z-feedbeef'
const scratch=path.join(root,'back-end/storage/framework/testing/backup-script-tests')
fs.mkdirSync(scratch,{recursive:true})
const posix=s=>s.replaceAll('\\','/').replace(/^([a-z]):/i,(_,d)=>'/'+d.toLowerCase())
const uid=spawnSync(bash,['--noprofile','--norc','-c','id -u'],{encoding:'utf8'}).stdout.trim()
const helper=path.join(root,'docker/shishli-backup-snapshot.php')
const inventoryHelper=path.join(root,'docker/shishli-backup-inventory.php')
function phpCall(code,args=[],input='') {
  return spawnSync(php,['-r',code,...args],{input,encoding:'utf8',maxBuffer:32*1024*1024})
}
function digest(rows) {
  const r=phpCall('require $argv[1];try{echo json_encode(snapshotDigest(explode("\\n",stream_get_contents(STDIN)),str_repeat("a",64)));}catch(Throwable){exit(12);}',[helper],typeof rows==='string'?rows:jsonl(rows))
  assert.equal(r.status,0,r.stderr);return r.stdout
}
function fixture() {
  const base=fs.mkdtempSync(path.join(scratch,'case-'))
  for(const dir of ['backups','project','bin','storage/app/public/products'])fs.mkdirSync(path.join(base,dir),{recursive:true})
  for(const name of ['.env.docker','.env.mysql','compose.yaml'])fs.writeFileSync(path.join(base,'project',name),'fixture-only\n')
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOmcAAAAASUVORK5CYII=','base64')
  fs.writeFileSync(path.join(base,'storage/app/public/products/one.png'),png)
  for(let i=1;i<95;i++)fs.writeFileSync(path.join(base,'storage/app/public/products',i+'.PNG'),png)
  fs.writeFileSync(path.join(base,'storage/hidden-data'),'stable')
  fs.writeFileSync(path.join(base,'storage/.hidden'),'hidden')
  const refs=path.join(base,'images.jsonl');fs.writeFileSync(refs,'["public","products/one.png"]\n')
  let script=fs.readFileSync(path.join(root,'docker/shishli-backup.sh'),'utf8')
  script=script.replace('PROJECT=/opt/shishli',`PROJECT='${posix(path.join(base,'project'))}'`)
    .replace('ROOT=/opt/shishli-backups',`ROOT='${posix(path.join(base,'backups'))}'`)
    .replace('i<60','i<2')
  fs.writeFileSync(path.join(base,'shishli-backup.sh'),script)
  for(const name of ['inventory.php','snapshot.php'])fs.copyFileSync(name==='inventory.php'?inventoryHelper:helper,path.join(base,'shishli-backup-'+name))
  fs.copyFileSync(path.join(root,'docker/shishli-backup-audit.sql'),path.join(base,'shishli-backup-audit.sql'))
  const mock=path.join(root,'docker/tests/backup-mock.mjs').replaceAll('\\','/'), node=posix(process.execPath)
  for(const tool of ['docker','stat','find','df','age'])fs.writeFileSync(path.join(base,'bin',tool),`#!/bin/sh\nexec "${node}" -- "${mock}" ${tool} "$@"\n`,{mode:0o755})
  fs.writeFileSync(path.join(base,'bin/sudo'),'#!/bin/sh\n[ "$1" != -n ] || shift\nexec "$@"\n',{mode:0o755})
  fs.writeFileSync(path.join(base,'bin/id'),'#!/bin/sh\nif [ "$1" = -u ] && [ "${2:-}" = deploy ]; then /usr/bin/id -u; else /usr/bin/id "$@"; fi\n',{mode:0o755})
  fs.writeFileSync(path.join(base,'bin/sleep'),'#!/bin/sh\nexit 0\n',{mode:0o755})
  fs.writeFileSync(path.join(base,'bin/awk'),'#!/bin/sh\ncase "$*" in *MemAvailable*) printf "9999999\\n";; *) exec /usr/bin/awk "$@";; esac\n',{mode:0o755})
  return {base,refs,b:path.join(base,'backups','pre-web-push-'+run)}
}
function stage(f,name,fault='',argument='') {
  const result=spawnSync(bash,['--noprofile','--norc','-c',
    'export PATH="$BACKUP_MOCK_BIN:$PATH"; [[ $(command -v docker) == "$BACKUP_MOCK_BIN/docker" ]] || exit 100; exec /usr/bin/bash "$@"',
    'backup-test',posix(path.join(f.base,'shishli-backup.sh')),name,run,argument],{
    encoding:'utf8',maxBuffer:32*1024*1024,timeout:120000,
    env:{...process.env,PATH:path.join(f.base,'bin')+path.delimiter+process.env.PATH,
      BACKUP_MOCK_ROOT:f.base,BACKUP_MOCK_FAULT:fault,BACKUP_MOCK_UID:uid,
      BACKUP_MOCK_BIN:posix(path.join(f.base,'bin')),
      BACKUP_TEST_PHP:php,BACKUP_TEST_TAR:tar,MSYS_NO_PATHCONV:'1',MSYS2_ARG_CONV_EXCL:'*'},
  })
  assert.ok(!result.error,result.error?.message)
  assert.ok(!(result.stdout+result.stderr).includes('FAKE_PRIVATE_VALUE'),'Secret leaked to terminal')
  return result
}
function ok(f,name,arg='') {const r=stage(f,name,'',arg);assert.equal(r.status,0,r.stdout+r.stderr)}
function commands(f) {
  const p=path.join(f.base,'commands.jsonl')
  return fs.existsSync(p)?fs.readFileSync(p,'utf8').trim().split('\n').map(JSON.parse):[]
}
function safe(f) {assert.deepEqual(commands(f).filter(c=>c.violation),[])}

test('real regex accepts supported suffixes and rejects misleading or newline suffixes',()=>{
  const names=['x.png','x.JPG','x.jpeg','x.png.webp','x.GIF','x.avif','xpng','x.png.exe','x.png\n','x.svg','png','x.webp.old']
  const r=phpCall('require $argv[1];echo json_encode(array_map("backupImageFilename",json_decode(stream_get_contents(STDIN),true)));',[inventoryHelper],JSON.stringify(names))
  assert.equal(r.status,0,r.stderr)
  assert.deepEqual(JSON.parse(r.stdout),[true,true,true,true,true,true,false,false,false,false,false,false])
})
test('real filesystem inventory covers 95 images, hidden files, additions, deletions and mutations',()=>{
  const f=fixture()
  const inspect=()=>phpCall('require $argv[1];try{echo json_encode(backupStorageInventory($argv[2],$argv[3]));}catch(Throwable){exit(12);}',[inventoryHelper,path.join(f.base,'storage'),f.refs])
  const before=inspect();assert.equal(before.status,0,before.stderr)
  const parsed=JSON.parse(before.stdout);assert.equal(parsed.image_files,95);assert.ok(parsed.entries['.hidden'])
  fs.writeFileSync(path.join(f.base,'storage/new'),'new');assert.notEqual(inspect().stdout,before.stdout)
  fs.unlinkSync(path.join(f.base,'storage/new'));assert.equal(inspect().stdout,before.stdout)
  fs.writeFileSync(path.join(f.base,'storage/hidden-data'),'changed');assert.notEqual(inspect().stdout,before.stdout)
  fs.unlinkSync(path.join(f.base,'storage/app/public/products/one.png'));assert.notEqual(inspect().status,0)
})
test('image references reject wrong disks, traversal, missing fields and missing original images',()=>{
  const f=fixture()
  for(const row of [['s3','products/one.png'],['public','../one.png'],['public','/products/one.png'],['public','products\\one.png'],['public','products/missing.png'],['public'],['public','products/one.png\u0000']]){
    fs.writeFileSync(f.refs,JSON.stringify(row)+'\n')
    const r=phpCall('require $argv[1];try{backupStorageInventory($argv[2],$argv[3]);}catch(Throwable){exit(12);}',[inventoryHelper,path.join(f.base,'storage'),f.refs])
    assert.equal(r.status,12)
  }
})
test('structured comparison ignores record order, JSON spacing, encoding and hex letter case',()=>{
  const original=records(), changed=structuredClone(original)
  const rows=changed.filter(r=>r[0]==='row').reverse()
  changed.splice(0,changed.length,['start','1',[]],...original.filter(r=>!['start','end','row'].includes(r[0])),...rows,['end','1',[]])
  for(const row of changed.filter(r=>r[0]==='row'))row[2]=row[2].map(v=>v===null?null:v.toLowerCase())
  assert.equal(digest(original),digest(changed))
  assert.equal(digest(original),digest(jsonl(changed).split('\n').filter(Boolean).map(line=>'  '+line.replace('"row"','"\\u0072ow"')+'  ').join('\n')))
  assert.ok(!digest(original).includes('FAKE_PRIVATE_VALUE'))
})
test('structured comparison detects data, row count, duplicate and schema changes; incomplete streams fail',()=>{
  assert.notEqual(digest(records()),digest(records(true)))
  const duplicated=records();duplicated.splice(-1,0,['row','orders',[hex(1)]]);assert.notEqual(digest(records()),digest(duplicated))
  const schema=records();schema.find(r=>r[0]==='schema'&&r[1]==='columns')[2][3]='varchar';assert.notEqual(digest(records()),digest(schema))
  const r=phpCall('require $argv[1];try{snapshotDigest(explode("\\n",stream_get_contents(STDIN)),str_repeat("a",64));}catch(Throwable){exit(12);}',[helper],jsonl(records().slice(0,-1)))
  assert.equal(r.status,12)
  const empty=records(), nul=records();empty.find(r=>r[0]==='row')[2][0]='';nul.find(r=>r[0]==='row')[2][0]=null
  assert.notEqual(digest(empty),digest(nul))
})
test('plan validates product_images fields against schema discovery, without INSERT or DDL',()=>{
  const p=rows=>spawnSync(php,[helper,'plan'],{input:jsonl(rows),encoding:'utf8'})
  const valid=p(discovery());assert.equal(valid.status,0,valid.stderr)
  assert.ok(valid.stdout.includes('START TRANSACTION READ ONLY'))
  assert.ok(!/^\s*(INSERT|DELETE|UPDATE|DROP|CREATE|ALTER)\b/im.test(valid.stdout))
  assert.notEqual(p(discovery(true)).status,0)
  const nullable=discovery();nullable.find(c=>c[0]==='product_images'&&c[2]==='path')[5]='YES';assert.notEqual(p(nullable).status,0)
})
test('schema assumptions match the reviewed product image migrations',()=>{
  const initial=fs.readFileSync(path.join(root,'back-end/database/migrations/2026_09_25_200335_create_product_images_table.php'),'utf8')
  const complete=fs.readFileSync(path.join(root,'back-end/database/migrations/2026_09_29_170147_complete_product_images_schema.php'),'utf8')
  assert.match(initial,/string\('path', 2048\)/)
  assert.match(initial,/foreignId\('product_id'\)/)
  assert.match(complete,/string\('disk'\)->default\('public'\)/)
})
test('wrong backup owner fails before creating a run directory or contacting Docker',()=>{
  const f=fixture();const r=stage(f,'preflight','owner');assert.notEqual(r.status,0)
  assert.match(r.stderr,/Backup root must be owned/)
  assert.ok(!fs.existsSync(f.b));assert.equal(commands(f).length,0)
})
test('unsafe root directory permissions fail before creating any backup files',()=>{
  const f=fixture();const r=stage(f,'preflight','root-mode');assert.notEqual(r.status,0)
  assert.ok(!fs.existsSync(f.b));assert.equal(commands(f).length,0)
})
test('successful simulated stages require no binary log access and preserve isolated targets without leaking secrets',()=>{
  const f=fixture();ok(f,'preflight');ok(f,'backup','--quiet-confirmed');ok(f,'verify');ok(f,'restore');ok(f,'report')
  assert.ok(fs.existsSync(path.join(f.b,'restore.ok')));assert.ok(fs.existsSync(path.join(f.base,'container.stopped')))
  assert.ok(!commands(f).some(args=>Array.isArray(args)&&args[0]==='exec'&&args.includes('restore')&&args.some(s=>s.includes('exec mysqldump'))))
  const exports=commands(f).filter(args=>Array.isArray(args)&&args.some(s=>s.includes('exec mysqldump')))
  assert.ok(exports.some(args=>args.includes('--no-data')))
  assert.ok(exports.some(args=>args.includes('--databases')))
  for(const args of exports) {
    for(const flag of ['--routines','--triggers','--events'])assert.ok(args.includes(flag),'Required object export missing: '+flag)
    assert.ok(args.some(s=>s.includes('--single-transaction')))
    assert.ok(!args.some(s=>/--skip-(routines|triggers|events)/.test(s)))
  }
  assert.ok(!fs.existsSync(path.join(f.base,'coordinate.counter')))
  const checksums=fs.readFileSync(path.join(f.b,'SHA256SUMS'),'utf8')
  assert.ok(!/binlog/i.test(checksums))
  safe(f)
})
for(const fault of ['schema','privilege','non-innodb','dump','tar','add-file','delete-file','change-file','rows-after']) {
  test('fail closed without approval when injected: '+fault,()=>{
    const f=fixture()
    if(['schema','privilege','non-innodb'].includes(fault))assert.notEqual(stage(f,'preflight',fault).status,0)
    else {ok(f,'preflight');assert.notEqual(stage(f,'backup',fault,'--quiet-confirmed').status,0)}
    assert.ok(fs.existsSync(path.join(f.b,'failed')));assert.ok(!fs.existsSync(path.join(f.b,'backup.ok')))
    const before=commands(f).length;assert.notEqual(stage(f,'restore').status,0);assert.equal(commands(f).length,before);safe(f)
  })
}
test('checksum failure and unsafe SQL permissions prevent restore before resources are created',()=>{
  for(const fault of ['checksum','private-mode']){
    const f=fixture();ok(f,'preflight');ok(f,'backup','--quiet-confirmed')
    if(fault==='checksum')fs.appendFileSync(path.join(f.b,'database.sql.gz'),'corrupt')
    assert.notEqual(stage(f,'verify',fault).status,0)
    assert.ok(!fs.existsSync(path.join(f.base,'container.created')));safe(f)
  }
})
for(const fault of ['collision','network','ready','import','rows-restored']) {
  test('isolated restore failure retains resources and never stops production: '+fault,()=>{
    const f=fixture();ok(f,'preflight');ok(f,'backup','--quiet-confirmed')
    assert.notEqual(stage(f,'restore',fault).status,0)
    assert.ok(!fs.existsSync(path.join(f.b,'restore.ok')));assert.ok(!fs.existsSync(path.join(f.base,'container.stopped')));safe(f)
  })
}
test('report cannot approve a failed run and sealing failure does not mark offsite success',()=>{
  const f=fixture();ok(f,'preflight');ok(f,'backup','--quiet-confirmed');ok(f,'restore')
  assert.notEqual(stage(f,'seal','seal','age1testpublicrecipient').status,0)
  assert.notEqual(stage(f,'report').status,0);safe(f)
})
