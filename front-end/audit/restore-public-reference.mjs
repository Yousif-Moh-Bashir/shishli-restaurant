import {readFile,writeFile} from 'node:fs/promises'
import postcss from 'postcss'
const scope=css=>{const root=postcss.parse(css);root.walkRules(rule=>{if(rule.parent.type==='atrule'&&/keyframes$/.test(rule.parent.name))return;rule.selectors=rule.selectors.map(selector=>{
 if(selector.includes('.site-layout'))return selector
 if(selector===':root')return '.site-layout'
 if(selector==='body')return '.site-layout'
 if(selector.startsWith('html'))return selector.replace(/^html/,'html:has(.site-layout)')
 if(/^\.(menu|product|checkout|login)-page/.test(selector))return ':where(.site-layout)'+selector
 return ':where(.site-layout) '+selector
 });});return root.toString()}
for(const name of ['style','responsive']){const css=await readFile('../Şişli/assets/css/'+name+'.css','utf8');await writeFile('src/assets/css/public-'+name+'.css',scope(css))}
const site=await readFile('src/assets/css/site.css','utf8');const imports=site.slice(0,site.indexOf('/* Address'));
await writeFile('src/assets/css/site.css',imports.replace("'./style.css'","'./public-style.css'").replace("'./responsive.css'","'./public-responsive.css'")+scope(site.slice(site.indexOf('/* Address'))))
const html=await readFile('../Şişli/index.html','utf8');await writeFile('audit/original-home-main.html',html.match(/<main id="main">[\s\S]*?<\/main>/)[0]);
console.log('Original public CSS read directly, scoped copies generated; original Home structure saved for comparison')
