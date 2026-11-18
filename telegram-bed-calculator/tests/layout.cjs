const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const html=fs.readFileSync(path.join(__dirname,'../widget.html'),'utf8');
const base={v:1,r:1,s:0,n:2,q:0,t:'Модель и размер',p:'70 800 ₽',d:'Каретная стяжка · 160×200 · 2 шт.',f:'Bavaro 26 · Чёрный',u:'https://redbeards.ru/hotel-beds/telegram-checkout.php?c=7.160.B.26.1.1.1.2',g:[]};
const states=[
 {...base,g:[['Модель',[['Линии','m3',0],['Геометрия','m1',0],['Каретная стяжка','m7',1],['Линии с кантом','m0',0]]],['Спальное место, см',[90,120,140,160,180,200].map(s=>[String(s),'s'+s,s===160]),'sizes'],['Количество',[['−','q1',0],['2 шт.','noop',1,null,1],['+','q3',0]],'sizes']]},
 {...base,s:1,t:'Ткань и цвет',g:[['Коллекция',[['Madera','fM',0],['Bavaro · +1 500 ₽','fB',1]]],['Bavaro 26 · Чёрный',[['20','c20',0,'#a8a8a8'],['22','c22',0,'#999999'],['23','c23',0,'#555555'],['28','c28',0,'#494040'],['26','c26',1,'#292929'],['2','c2',0,'#eeeeee'],['3','c3',0,'#d2c5ba'],['37','c37',0,'#bcaa95']],'colors'],['Палитра',[['←','p1',0],['3 из 4','noop',0,null,1],['→','p3',0]],'sizes']]},
 {...base,s:2,t:'Основание и опции',g:[['Основание',[['Деревянное','b0',0],['Подъёмное','b1',1]]],['Дополнительно',[['✓ Ножки · +2 000 ₽','l0',1],['✓ Более мягкое изголовье · +3 000 ₽','h0',1],['Подобрать матрас · по запросу','x1',0]],'stack']]},
 {...base,s:2,q:1,t:'Основание и опции',g:[['Основание',[['Деревянное','b0',0],['Подъёмное','b1',1]]],['Дополнительно',[['✓ Ножки · +2 000 ₽','l0',1],['✓ Более мягкое изголовье · +3 000 ₽','h0',1],['✓ Матрас · по запросу','x0',1]],'stack']]}
];
const palettes={light:{bg:'#ffffff',text:'#171717',hint:'#686868',button:'#3390ec',buttonText:'#ffffff',secondary:'#f1f1f4',line:'#d5d5d5'},dark:{bg:'#202020',text:'#eeeeee',hint:'#b8b8b8',button:'#6ab2f2',buttonText:'#111111',secondary:'#303030',line:'#555555'}};
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const errors=[],measurements=[],failures=[];
 try{
  for(const [theme,p] of Object.entries(palettes))for(const width of [200,240,280,320,360,430,600])for(const state of states){
   const page=await browser.newPage({viewport:{width,height:1000},colorScheme:theme});
   page.on('pageerror',e=>errors.push(String(e)));
   const inject='<style>:root{--tg-theme-bg-color:'+p.bg+';--tg-theme-text-color:'+p.text+';--tg-theme-hint-color:'+p.hint+';--tg-theme-button-color:'+p.button+';--tg-theme-button-text-color:'+p.buttonText+';--tg-theme-secondary-bg-color:'+p.secondary+';--tg-theme-section-separator-color:'+p.line+'}body{background:var(--tg-theme-bg-color)}</style><script>window.TelegramWidget={chatData:JSON.stringify('+JSON.stringify(state)+'),onEvent(){},ready(){},request(){return Promise.reject({code:"TEST"})},openLink(){}};</script>';
   await page.setContent(html.replace('<head>','<head>'+inject));
   const box=await page.locator('main').boundingBox();const metrics=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,height:document.querySelector('main').getBoundingClientRect().height}));
   measurements.push({theme,width,step:state.s,quote:state.q,...metrics});
   if(metrics.overflow)failures.push({reason:'Horizontal clipping',...measurements.at(-1)});
   if(box.height>600)failures.push({reason:'Telegram height limit',...measurements.at(-1)});
   if(width>=320&&box.height>560)failures.push({reason:'Comfort height',...measurements.at(-1)});
   for(const button of await page.locator('button:visible').all()){const b=await button.boundingBox();assert.ok(b.x>=-1&&b.x+b.width<=width+1,'Button overflow');if(b.y+b.height>600)failures.push({reason:'Clipped button',...measurements.at(-1)});}
   await page.close();
  }
  assert.deepEqual(errors,[]);console.log(JSON.stringify({cases:measurements.length,maxHeight:Math.max(...measurements.map(x=>x.height)),failures}));assert.deepEqual(failures,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
