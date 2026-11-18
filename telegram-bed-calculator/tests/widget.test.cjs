const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const html=fs.readFileSync(path.join(__dirname,'../widget.html'),'utf8');
const script=html.match(/<script>([\s\S]*?)<\/script>/)[1];
const state=(r=1,s=0,q=0)=>({v:1,r,s,q,t:'Модель и размер',p:'17 000 ₽',d:'Линии · 160×200 · 1 шт.',f:'Madera 2 · Молочный',g:[['Модель',[['Линии','m3',1],['Геометрия','m1',0]]]],u:'https://redbeards.ru/hotel-beds/telegram-checkout.php?c=3.160.M.2.0.0.0.1'});
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.style={};this.disabled=false;this.attrs={};this._text='';}
 set textContent(x){this._text=x;this.children=[];}
 get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
 appendChild(e){this.children.push(e);return e;}
 setAttribute(k,v){this.attrs[k]=v;}
 addEventListener(k,f){this.listeners[k]=f;}
 click(){if(!this.disabled)this.listeners.click?.();}
}
function boot(initial=state()){
 const nodes=Object.fromEntries(['tabs','title','groups','price','totalLabel','detail','fabric','foam','back','next','photo','status'].map(x=>[x,new Element(x)]));
 ['back','next','photo'].forEach(id=>nodes[id].disabled=true);
 const document={getElementById:id=>{assert.ok(nodes[id],'Missing node '+id);return nodes[id];},createElement:t=>new Element(t),createTextNode:t=>{const e=new Element('#text');e.textContent=t;return e;}};
 const calls=[],pending=[],events={},opened=[];
 const W={chatData:JSON.stringify(initial),request:a=>{calls.push(a);return new Promise((resolve,reject)=>pending.push({resolve,reject}));},openLink:u=>opened.push(u),onEvent:(k,f)=>events[k]=f,ready:()=>{}};
 vm.runInNewContext(script,{document,TelegramWidget:W,URL});
 return{nodes,calls,pending,opened,event:n=>{W.chatData=JSON.stringify(n);events.chatDataChanged();}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('native widget has no remote resources or form controls',()=>{
 assert.ok(Buffer.byteLength(html)<32768);
 assert.doesNotMatch(html,/<(?:input|select|textarea|iframe|link)\b/i);
 assert.doesNotMatch(html,/<script[^>]+src=/i);
 assert.doesNotMatch(script,/\b(?:fetch|XMLHttpRequest|WebSocket)\s*\(/);
});
test('initial confirmed price and server-controlled choices',()=>{
 const b=boot();assert.equal(b.nodes.price.textContent,'17 000 ₽');
 b.nodes.groups.children[1].children[1].click();
 assert.deepEqual(b.calls,['1:m1']);assert.equal(b.nodes.price.textContent,'17 000 ₽');
});
test('one request per tap; waiting prevents double submits',async()=>{
 const b=boot();b.nodes.next.click();b.nodes.next.click();
 assert.deepEqual(b.calls,['1:n1']);assert.equal(b.nodes.next.disabled,true);
 b.pending[0].resolve({result:JSON.stringify(state(2,1))});await tick();
 assert.equal(b.nodes.next.disabled,false);
});
test('new server revision survives a late reply',async()=>{
 const b=boot();b.nodes.next.click();const latest=state(3,2);latest.p='73 800 ₽';b.event(latest);
 b.pending[0].resolve({result:JSON.stringify(state(2,1))});await tick();
 assert.equal(b.nodes.price.textContent,'73 800 ₽');assert.equal(b.nodes.status.textContent,'');assert.equal(b.nodes.next.disabled,false);
 b.nodes.next.click();assert.deepEqual(b.opened,[latest.u]);
});
test('timeouts and rate limits allow retry',async()=>{
 const b=boot();b.nodes.photo.click();b.pending[0].reject({code:'TIMEOUT'});await tick();
 assert.match(b.nodes.status.textContent,/Повторите/);assert.equal(b.nodes.photo.disabled,false);
 b.nodes.photo.click();assert.deepEqual(b.calls,['1:photo','1:photo']);
 b.pending[1].reject({code:'RATE_LIMITED'});await tick();assert.match(b.nodes.status.textContent,/Слишком быстро/);
});
test('malformed response retains confirmed price and permits retry',async()=>{
 const b=boot();b.nodes.next.click();b.pending[0].resolve({result:'{broken'});await tick();
 assert.equal(b.nodes.price.textContent,'17 000 ₽');assert.equal(b.nodes.next.disabled,false);assert.match(b.nodes.status.textContent,/Повторите/);
 const malformed=state(4,2);malformed.g=[['Bad',null]];b.event(malformed);assert.equal(b.nodes.price.textContent,'17 000 ₽');
});
test('checkout only opens allowed merchant HTTPS address',()=>{
 for(const url of ['https://evil.invalid/','http://redbeards.ru/','https://redbeards.ru@evil.invalid/','https://x:redbeards.ru@redbeards.ru/','https://redbeards.ru:8443/']){
  const n=state();n.u=url;const b=boot(n);assert.equal(b.nodes.next.disabled,true);b.nodes.next.click();assert.deepEqual(b.opened,[]);
 }
});
test('unsupported state fails safely and does not expose checkout',()=>{
 const b=boot({...state(),v:2});assert.equal(b.nodes.next.disabled,true);assert.match(b.nodes.status.textContent,/start/);
});
test('mattress quote is distinguished from a final payable amount',()=>{
 const b=boot(state(1,2,1));assert.equal(b.nodes.totalLabel.textContent,'Известная часть');assert.equal(b.nodes.next.textContent,'Запросить расчёт');
});
