import {spawn} from 'node:child_process'
import {readFile,writeFile,mkdir} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
const root=fileURLToPath(new URL('../',import.meta.url)),output=root+'audit/public-restoration-suites/'
await mkdir(output,{recursive:true})
const results=[]
for(const name of ['auth','cart','checkout']){
 const child=spawn(process.execPath,['--experimental-websocket','tests/'+name+'.browser.mjs'],{cwd:root,windowsHide:true,env:{...process.env,PUBLIC_UI_ORIGIN:'http://127.0.0.1:5176',AUTH_SKIP_LIVE:'1',PUBLIC_SKIP_LIVE:'1'},stdio:['ignore','pipe','pipe']})
 let log='';child.stdout.on('data',data=>log+=data);child.stderr.on('data',data=>log+=data)
 const code=await new Promise(resolve=>child.on('exit',resolve))
 let report={};try{report=JSON.parse(await readFile(root+'.'+name+'-check/'+(name==='auth'?'results.json':'report.json'),'utf8'))}catch{}
 if(code===0)await writeFile(output+name+'.json',JSON.stringify(report,null,2));else await writeFile(output+name+'-failure.log',log)
 const result={suite:name,exit:code,fixtures:report.simulated?.length||report.fixtures?.length||report.browser?.length,responsive:report.responsive?.length,diagnostics:report.diagnostics?.length}
 results.push(result);console.log(JSON.stringify(result));if(code!==0)process.exitCode=1
}
await writeFile(output+'report.json',JSON.stringify(results,null,2))
