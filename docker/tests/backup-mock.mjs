// Test-only Docker/tool replacement. No Docker client, daemon or SSH is invoked.
import fs from 'node:fs'
import path from 'node:path'
import { spawnSync } from 'node:child_process'
import { discovery, records, jsonl } from './backup-fixtures.mjs'
const [tool,...args] = process.argv.slice(2)
const root = process.env.BACKUP_MOCK_ROOT
const fault = process.env.BACKUP_MOCK_FAULT || ''
const run = '20261108T220000Z-feedbeef'
const app = 'a'.repeat(64), mysql = 'b'.repeat(64)
const name = 'shishli-restore-'+run
const b = path.join(root,'backups','pre-web-push-'+run)
const win = s => s.replace(/^\/([a-z])\//i,(_,d)=>d.toUpperCase()+':/').replaceAll('\\','/')
const unix = s => s.replaceAll('\\','/').replace(/^([a-z]):/i,(_,d)=>'/'+d.toLowerCase())
const log = entry => fs.appendFileSync(path.join(root,'commands.jsonl'),JSON.stringify(entry)+'\n')
const output = s => process.stdout.write(String(s))
function bad(message = 'FAKE_PRIVATE_VALUE: simulated failure') {process.stderr.write(message+'\n');process.exit(31)}
function count(key) {
  const file=path.join(root,key+'.counter')
  const value=fs.existsSync(file)?Number(fs.readFileSync(file)):0
  fs.writeFileSync(file,String(value+1));return value+1
}
function invoke(exe, argv, input) {
  const r=spawnSync(exe,argv,{input,encoding:null,maxBuffer:32*1024*1024})
  if(r.stdout)process.stdout.write(r.stdout)
  if(r.stderr)process.stderr.write(r.stderr)
  if(r.status!==0)process.exit(r.status||1)
}
function mountValues(argv) {
  return argv.flatMap((arg,i)=>arg==='--mount'?[Object.fromEntries(argv[i+1].split(',').map(x=>x.includes('=')?x.split(/=(.*)/s).slice(0,2):[x,true]))]:[])
}
if(tool==='stat') {
  const format=args[1], target=win(args.at(-1))
  if(format==='%u')output(fault==='owner'&&target===win(path.join(root,'backups'))?'0':process.env.BACKUP_MOCK_UID)
  else if(format==='%a')output(fault==='root-mode'&&target===win(path.join(root,'backups'))?'755':target.endsWith('.env.docker')||target.endsWith('.env.mysql')?'600':'700')
  else output(target+' '+(fs.existsSync(target)?fs.statSync(target).size:0)+' bytes\n')
  process.exit(0)
}
if(tool==='find') {
  if(fault==='private-mode' && fs.existsSync(path.join(b,'database.sql.gz')))output(path.join(b,'database.sql.gz')+'\n')
  process.exit(0)
}
if(tool==='df'){output('Filesystem 1024-blocks Used Available Capacity Mounted\nfixture 99999999 1 99999998 1% /\n');process.exit(0)}
if(tool==='age') {if(fault==='seal')bad();const target=win(args[args.indexOf('-o')+1]);fs.writeFileSync(target,'TEST CIPHERTEXT ONLY');process.stdin.resume();process.exit(0)}
if(tool!=='docker')bad('Unknown mock tool')
log(args)
const mounts=mountValues(args)
for(const m of mounts) {
  if(m.type==='volume' && ['shishli_storage','shishli_mysql','shishli_redis'].includes(m.src) && !m.readonly) {
    log({violation:'PRODUCTION_WRITABLE_MOUNT'});bad()
  }
}
if(['restart','rm','prune','kill'].includes(args[0]) || args.includes('prune')) {log({violation:'DESTRUCTIVE_COMMAND'});bad()}
if(args[0]==='compose') {output(args.at(-1)==='app'?app:mysql);process.exit(0)}
if(args[0]==='inspect') {
  const target=args.at(-1), format=args[args.indexOf('-f')+1]
  if(format==='{{.Image}}')output('sha256:'+(target===app?app:mysql))
  else if(format.includes('Health.Status'))output('healthy')
  else if(format.includes('Config.Labels'))output(run)
  else if(format.includes('NetworkMode'))output(fault==='network'?'shishli_private':'none')
  else if(format.includes('/var/www/html/storage'))output('volume:shishli_storage')
  else if(format.includes('if eq .Destination'))output('volume:shishli_mysql')
  else output('volume:'+name+'-mysql:/var/lib/mysql')
  process.exit(0)
}
if(args[0]==='container'&&args[1]==='inspect')process.exit(fault==='collision'||fs.existsSync(path.join(root,'container.created'))?0:1)
if(args[0]==='volume') {
  const target=args.at(-1)
  if(['shishli_storage','shishli_mysql','shishli_redis'].includes(target)){log({violation:'PRODUCTION_VOLUME_MUTATION'});bad()}
  if(args[1]==='inspect')process.exit(1)
  if(args[1]!=='create')bad()
  fs.mkdirSync(path.join(root,'volumes',target),{recursive:true});output(target);process.exit(0)
}
if(args[0]==='stop') {
  if(args.at(-1)!==name){log({violation:'PRODUCTION_STOP'});bad()}
  fs.writeFileSync(path.join(root,'container.stopped'),'yes');process.exit(0)
}
if(args[0]==='exec') {
  if(args.at(-1)==='mysqldump' || args.includes('--version')) {output('mysqldump fixture 8.4');process.exit(0)}
  const script=args[args.indexOf('-c')+1]||''
  if(script.includes('printf "%s"')){output('shishli');process.exit(0)}
  const target=args.includes(app)?app:args.includes(mysql)?mysql:name
  const isRestore=target===name
  if(script.includes('exec mysqldump')) {
    if(fault==='privilege'&&args.includes('--no-data'))bad()
    if(fault==='dump'&&!args.includes('--no-data'))bad()
    output('-- cosmetic dump fixture\nCREATE DATABASE shishli;\nUSE shishli;\n-- FAKE_PRIVATE_VALUE\n');process.exit(0)
  }
  const stdin=fs.readFileSync(0,'utf8')
  if(target===mysql && /^\s*(CREATE|INSERT|DROP|TRUNCATE|ALTER)\b/im.test(stdin)){log({violation:'PRODUCTION_SQL_IMPORT'});bad()}
  if(isRestore&&args.includes('SELECT 1')){if(fault==='ready')bad();output('1\n');process.exit(0)}
  const query=args.includes('-e')?args[args.indexOf('-e')+1]:stdin
  if(query.includes('engine<>')){output(fault==='non-innodb'?'1\n':'0\n');process.exit(0)}
  if(/log_bin|BINARY LOG STATUS|MASTER STATUS|gtid_executed/i.test(query)) {
    log({violation:'BINARY_LOG_PRIVILEGE_REQUEST'});bad('Binary log access is denied in all backup test fixtures')
  }
  if(query.includes('information_schema.COLUMNS c')) {output(jsonl(discovery(fault==='schema')));process.exit(0)}
  if(query.includes("JSON_ARRAY('start'")) {
    const n=count('snapshot');output(jsonl(records((fault==='rows-after'&&n===3)||(fault==='rows-restored'&&isRestore))));process.exit(0)
  }
  if(query.includes('JSON_ARRAY(disk,path)')){output('["public","products/one.png"]\n');process.exit(0)}
  if(query.includes('invalid_product_category')){output('products\t1\nusers\t1\ninvalid_product_category\t0\n');process.exit(0)}
  if(isRestore && stdin.includes('CREATE DATABASE')){if(fault==='import')bad();fs.writeFileSync(path.join(root,'import.ok'),'yes');process.exit(0)}
  bad('Unhandled mock SQL request')
}
if(args[0]==='run') {
  if(args.includes('-d')) {fs.writeFileSync(path.join(root,'container.created'),'yes');process.exit(0)}
  const entry=args[args.indexOf('--entrypoint')+1]
  if(entry==='php') {
    if(args.includes('/review/snapshot.php')) {
      const mode=args.at(-1), helper=path.join(b,'snapshot.php'), input=fs.readFileSync(0)
      if(mode==='plan')invoke(process.env.BACKUP_TEST_PHP,[helper,'plan'],input)
      else invoke(process.env.BACKUP_TEST_PHP,[helper,'digest',path.join(b,'reference.key')],input)
      process.exit(0)
    }
    if(args.includes('/review/inventory.php')) {
      const volume=mounts.find(m=>m.type==='volume').src
      const source=volume==='shishli_storage'?path.join(root,'storage'):path.join(root,'volumes',volume)
      if(volume==='shishli_storage' && count('inventory')===2) {
        if(fault==='add-file')fs.writeFileSync(path.join(source,'new-hidden-file'),'new')
        if(fault==='delete-file')fs.unlinkSync(path.join(source,'hidden-data'))
        if(fault==='change-file')fs.writeFileSync(path.join(source,'hidden-data'),'changed')
      }
      const code='require $argv[1];try{echo json_encode(backupStorageInventory($argv[2],$argv[3]));}catch(Throwable){fwrite(STDERR,"fixture inventory failure");exit(1);}'
      invoke(process.env.BACKUP_TEST_PHP,['-r',code,path.join(b,'inventory.php'),source,path.join(b,'images.jsonl')]);process.exit(0)
    }
    output('{"image_files":95}\n');process.exit(0)
  }
  if(entry==='tar') {
    if(args.includes('-czf')) {
      if(fault==='tar')bad()
      invoke(process.env.BACKUP_TEST_TAR,['-czf','-','-C',unix(path.join(root,'storage')),'.']);process.exit(0)
    }
    const destination=mounts.find(m=>m.dst==='/restore').src
    invoke(process.env.BACKUP_TEST_TAR,['--force-local','-xzf',unix(path.join(b,'storage.tar.gz')),'-C',unix(path.join(root,'volumes',destination))]);process.exit(0)
  }
}
bad('Unhandled Docker fixture command')
