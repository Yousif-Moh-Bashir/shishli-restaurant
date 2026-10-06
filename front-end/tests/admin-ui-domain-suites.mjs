import {spawn} from 'node:child_process'
import {readFile,writeFile,mkdir} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
const root=fileURLToPath(new URL('../',import.meta.url)),output=root+'audit/admin-ui-domain-suites/'
await mkdir(output,{recursive:true})
const results=[]
for(const name of ['lifecycle','payments','notifications']){
 const child=spawn(process.execPath,['--experimental-websocket','tests/'+name+'.browser.mjs'],{cwd:root,windowsHide:true,env:{...process.env,ADMIN_UI_ORIGIN:process.env.ADMIN_UI_ORIGIN||'http://127.0.0.1:5176'},stdio:['ignore','pipe','pipe']})
 let log='';child.stdout.on('data',data=>{log+=data});child.stderr.on('data',data=>{log+=data})
 const code=await new Promise(resolve=>child.on('exit',resolve))
 const report=JSON.parse(await readFile(root+'.'+name+'-check/report.json','utf8'))
 await writeFile(output+name+'.json',JSON.stringify(report,null,2))
 const result={suite:name,exit:code,browser:report.browser?.length,responsive:report.responsive?.length,live:report.live?.length,diagnostics:report.diagnostics?.length,assetFailures:report.assetFailures?.length,failure:report.failure}
 results.push(result);console.log(JSON.stringify(result))
 if(code!==0){await writeFile(output+name+'-failure.log',log);process.exitCode=1}
}
await writeFile(output+'report.json',JSON.stringify(results,null,2))
