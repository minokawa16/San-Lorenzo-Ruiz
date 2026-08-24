import {spawn} from 'node:child_process';
import {mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';

const chrome='C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const profile=mkdtempSync(join(tmpdir(),'tugon-chrome-'));
const port=9337;
const browser=spawn(chrome,[`--remote-debugging-port=${port}`,'--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore'});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
async function json(url,options){for(let i=0;i<30;i++){try{const r=await fetch(url,options);if(r.ok)return r.json();}catch{}await pause(200);}throw new Error('Chrome DevTools endpoint unavailable');}
let seq=0;const pending=new Map();let ws;
function send(method,params={}){return new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));setTimeout(()=>{if(pending.has(id)){pending.delete(id);reject(new Error('CDP timeout: '+method));}},10000);});}
try{
  const target=await json(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'});ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);}};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Accessibility.enable');
  const cases=[
    ['admin','phase11admin','admin/reports.php'],['admin','phase11admin','admin/audit-logs.php'],
    ['user','phase11user','users/notifications.php'],['user','phase11user','users/my-requests.php'],['user','phase11user','users/make-reservation.php'],['user','phase11user','users/request-blessing.php'],['user','phase11user','users/ai-assistant.php']
  ];
  const widths=[360,390,414,768,1024,1280,1440];let pass=0,fail=0;
  let activeRole='';
  await send('Network.setCookie',{name:'TUGONSESSID',value:'phase11admin',url:'http://127.0.0.1:8099/',path:'/'});
  activeRole='admin';
  for(const path of ['admin/dashboard.php','admin/manage-calendar.php']){
    await send('Page.navigate',{url:'http://127.0.0.1:8099/'+path});
    for(let ready=0;ready<50;ready++){await pause(100);const state=await send('Runtime.evaluate',{returnByValue:true,expression:`({ready:document.readyState,path:location.pathname,sidebar:!!document.querySelector('.admin-sidebar')})`});const value=state.result.value;if(value.path.endsWith('/'+path)&&value.ready==='complete'&&value.sidebar)break;}
    const sidebarStyle=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>{const sidebar=document.querySelector('.admin-sidebar');const brand=sidebar?.querySelector('.sidebar-brand');const logo=sidebar?.querySelector('.brand-logo');const active=sidebar?.querySelector('.nav-link.active');if(!sidebar||!brand||!logo||!active)return{ok:false};const s=getComputedStyle(sidebar),h=getComputedStyle(brand),l=getComputedStyle(logo),a=getComputedStyle(active);return{ok:s.backgroundImage.includes('rgb(46, 58, 45)')&&h.backgroundColor==='rgba(255, 255, 255, 0.035)'&&l.color==='rgb(200, 155, 60)'&&l.backgroundColor==='rgba(200, 155, 60, 0.1)'&&a.backgroundColor==='rgba(200, 155, 60, 0.15)'&&a.borderTopColor==='rgba(200, 155, 60, 0.55)',sidebar:s.backgroundImage,brand:h.backgroundColor,logo:l.backgroundColor,active:a.backgroundColor,border:a.borderTopColor};})()`});
    console.log(`${sidebarStyle.result.value.ok?'PASS':'FAIL'} admin ${path} canonical sidebar ${JSON.stringify(sidebarStyle.result.value)}`);sidebarStyle.result.value.ok?pass++:fail++;
  }
  for(const [role,sid,path] of cases){
    // Set the fixture session only when changing roles. The application may
    // legitimately rotate its session ID; Chrome must retain that new cookie
    // for subsequent pages in the same authenticated journey.
    if(role!==activeRole){
      await send('Network.setCookie',{name:'TUGONSESSID',value:sid,url:'http://127.0.0.1:8099/',path:'/'});
      activeRole=role;
    }
    await send('Emulation.setDeviceMetricsOverride',{width:1024,height:900,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:'http://127.0.0.1:8099/'+path});
    let pageReady=false;
    for(let ready=0;ready<50;ready++){
      await pause(100);
      const state=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>({ready:document.readyState,path:location.pathname,main:!!document.getElementById('main-content')}))()`});
      const value=state.result.value;
      if(value.path.endsWith('/'+path)&&value.ready==='complete'&&value.main){pageReady=true;break;}
    }
    if(!pageReady){
      const diagnostic=await send('Runtime.evaluate',{returnByValue:true,expression:`({url:location.href,title:document.title,text:(document.body?.innerText||'').slice(0,180)})`});
      console.log(`FAIL ${role} ${path} page did not finish loading ${JSON.stringify(diagnostic.result.value)}`);
      fail++;
    }
    for(const width of widths){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
      await pause(120);
      const result=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>{const main=document.getElementById('main-content');const critical=[...document.querySelectorAll('main a[href],main button,main input,main select')].filter(e=>{const s=getComputedStyle(e);return s.display!=='none'&&s.visibility!=='hidden'});return {title:document.title,overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,main:!!main,skip:!!document.querySelector('.tugon-skip-link'),focusable:critical.length,modalOverflow:[...document.querySelectorAll('.modal-content')].some(e=>e.scrollWidth>innerWidth)};})()`});
      const v=result.result.value;const ok=v.main&&v.skip&&v.overflow<=2&&!v.modalOverflow&&v.focusable>0&&!/login/i.test(v.title);console.log(`${ok?'PASS':'FAIL'} ${role} ${path} ${width}px overflow=${v.overflow} controls=${v.focusable}`);ok?pass++:fail++;
    }
    const contrast=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>{function rgb(v){const m=v.match(/rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)/);return m?[+m[1],+m[2],+m[3]]:null}function lum(c){return c.map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0)}let bad=0,checked=0,samples=[];for(const e of document.querySelectorAll('main p,main label,main a,main button,main td,main th,main h1,main h2,main h3')){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden'||!e.textContent.trim())continue;let bg=e;while(bg&&getComputedStyle(bg).backgroundColor==='rgba(0, 0, 0, 0)')bg=bg.parentElement;const f=rgb(s.color),b=rgb(bg?getComputedStyle(bg).backgroundColor:'rgb(255,255,255)');if(!f||!b)continue;const ratio=(Math.max(lum(f),lum(b))+.05)/(Math.min(lum(f),lum(b))+.05);const large=parseFloat(s.fontSize)>=24||(parseFloat(s.fontSize)>=18&&+s.fontWeight>=700);checked++;if(ratio<(large?3:4.5)){bad++;if(samples.length<6)samples.push(e.tagName+'.'+e.className+' '+s.color+'/'+(bg?getComputedStyle(bg).backgroundColor:'white')+' '+ratio.toFixed(2));}}return{bad,checked,samples};})()`});
    const contrastOk=contrast.result.value.checked>0&&contrast.result.value.bad===0;console.log(`${contrastOk?'PASS':'FAIL'} ${role} ${path} text contrast failures=${contrast.result.value.bad}/${contrast.result.value.checked}${contrastOk?'':' '+contrast.result.value.samples.join(' | ')}`);contrastOk?pass++:fail++;
    if(path==='users/request-blessing.php'){
      const otherBlessing=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>{const radio=document.querySelector('input[name="request_type"][value="other_blessing"]');const wrap=document.querySelector('[data-other-request-wrap]');const input=document.getElementById('other_blessing_name');radio?.click();return{ok:!!radio&&!!wrap&&!!input&&!wrap.hidden&&input.required&&input.maxLength===120,label:radio?.closest('label')?.innerText||''};})()`});
      const otherOk=otherBlessing.result.value.ok&&otherBlessing.result.value.label.includes('Other Blessing');console.log(`${otherOk?'PASS':'FAIL'} ${role} ${path} Other Blessing reveals required custom field`);otherOk?pass++:fail++;
    }
    if(role==='admin'){
      const sidebarStyle=await send('Runtime.evaluate',{returnByValue:true,expression:`(()=>{const sidebar=document.querySelector('.admin-sidebar');const brand=sidebar?.querySelector('.sidebar-brand');const logo=sidebar?.querySelector('.brand-logo');const active=sidebar?.querySelector('.nav-link.active');if(!sidebar||!brand||!logo||!active)return{ok:false};const s=getComputedStyle(sidebar),h=getComputedStyle(brand),l=getComputedStyle(logo),a=getComputedStyle(active);return{ok:s.backgroundImage.includes('rgb(46, 58, 45)')&&h.backgroundColor==='rgba(255, 255, 255, 0.035)'&&l.color==='rgb(200, 155, 60)'&&l.backgroundColor==='rgba(200, 155, 60, 0.1)'&&a.backgroundColor==='rgba(200, 155, 60, 0.15)'&&a.borderTopColor==='rgba(200, 155, 60, 0.55)',sidebar:s.backgroundImage,brand:h.backgroundColor,logo:l.backgroundColor,active:a.backgroundColor,border:a.borderTopColor};})()`});
      console.log(`${sidebarStyle.result.value.ok?'PASS':'FAIL'} ${role} ${path} canonical sidebar ${JSON.stringify(sidebarStyle.result.value)}`);sidebarStyle.result.value.ok?pass++:fail++;
    }
    await send('Runtime.evaluate',{expression:`document.body.focus()`});await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab'});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab'});const keyboard=await send('Runtime.evaluate',{returnByValue:true,expression:`document.activeElement!==document.body&&document.activeElement!==document.documentElement`});console.log(`${keyboard.result.value?'PASS':'FAIL'} ${role} ${path} keyboard focus advances`);keyboard.result.value?pass++:fail++;
  }
  const ax=await send('Accessibility.getFullAXTree');const hasMain=ax.nodes.some(n=>n.role?.value==='main');const hasButtons=ax.nodes.some(n=>n.role?.value==='button'&&n.name?.value);console.log(`${hasMain&&hasButtons?'PASS':'FAIL'} accessibility tree exposes main landmark and named buttons`);hasMain&&hasButtons?pass++:fail++;
  console.log(`RESULT pass=${pass} fail=${fail}`);process.exitCode=fail?1:0;
}finally{if(ws)ws.close();browser.kill();await pause(1000);const resolved=resolve(profile);if(resolved.startsWith(resolve(tmpdir()))){try{rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});}catch{}}}
