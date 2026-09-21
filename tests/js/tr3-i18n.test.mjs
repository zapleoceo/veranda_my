import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
const app=readFileSync(new URL('../../tr3/assets/app.js',import.meta.url),'utf8');

test('language refresh preserves selected date, duration values and translates attributes',()=>{
  class Element {
    constructor(id,attributes={}){this.id=id;this.attributes=attributes;}
    getAttribute(key){return this.attributes[key];}
    setAttribute(key,value){this.attributes[key]=value;}
  }
  const dateButton=new Element('resDateBtn',{'data-i18n':'pick_date'});
  const close=new Element('close',{'data-i18n-aria-label':'close'});
  const name=new Element('reqName',{'data-i18n-placeholder':'your_name'});
  const nodes={resDateBtn:dateButton,resDate:{value:'2026-10-03'},reqDuration:{value:'120',options:[{value:'90'},{value:'120'}]},reqStartIso:{value:'2026-10-03T21:00:00'}};
  let endArguments;
  const translations={close:'Đóng',your_name:'Tên của bạn',h_short:'g',pick_date:'Chọn ngày'};
  const context={HTMLElement:Element,UI_LANG:'vi',UI_LOCALE:'vi-VN',Intl,t:key=>translations[key]||key,
    swapText(){assert.fail('selected date must not enter delayed generic text animation');},fmtCashDate:value=>'formatted:'+value,
    setEndTimeLabel:(...args)=>{endArguments=args;},
    document:{documentElement:{},querySelectorAll(selector){return selector==='[data-i18n]'?[dateButton]:selector==='[data-i18n-aria-label]'?[close]:[name];},getElementById:id=>nodes[id]||null}};
  const start=app.indexOf('  const applyI18n =');
  const end=app.indexOf('  const switchLang =',start);
  runInNewContext(app.slice(start,end)+'\napplyI18n();',context);
  assert.equal(dateButton.textContent,'formatted:2026-10-03');
  assert.equal(close.getAttribute('aria-label'),'Đóng');
  assert.equal(name.getAttribute('placeholder'),'Tên của bạn');
  assert.deepEqual(nodes.reqDuration.options.map(o=>o.textContent),['1,5 g','2 g']);
  assert.equal(nodes.reqDuration.value,'120');
  assert.deepEqual(endArguments,['2026-10-03T21:00:00','120']);
});

test('booking end time uses current language without changing its calculation',()=>{
  const start=app.indexOf('    const parseIsoLocal =');
  const end=app.indexOf('    const syncStartIsoAndEnd =',start);
  const output={};
  for(const prefix of ['до','until','đến']) {
    const context={reqEndTime:output,t:()=>prefix,Date};
    runInNewContext(app.slice(start,end)+"\nsetEndTimeLabel('2026-10-03T21:00:00',120);",context);
    assert.equal(output.textContent,prefix+' 23:00');
    runInNewContext("setEndTimeLabel('',0);",context);
    assert.equal(output.textContent,prefix+' —');
  }
});
