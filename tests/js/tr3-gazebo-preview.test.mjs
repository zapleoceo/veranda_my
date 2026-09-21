import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const { positionPreview, previewSource, init } = createRequire(import.meta.url)('../../tr3/assets/gazebo-preview.js');

test('all photographed tables map to their own furniture and umbrella variants', () => {
  const groups = {garden:[4,5,8], 'garden-parasol':[7],counter:[10,11], 'counter-parasol':[12,13], 'small-dark':[14,15], 'small-gray':[16], wood:[17,18,19,20,21]};
  for (const [type, numbers] of Object.entries(groups)) for (const n of numbers)
    assert.equal(previewSource(String(n)), '/tr3/assets/preview-'+type+'-v1.png');
  for (const n of [1,2,3,6,9]) assert.equal(previewSource(n), '/tr3/assets/gazebo-preview-v1.png');
  for (const n of ['Room',22,0,'',null,'../../x',17.5]) assert.equal(previewSource(n),null);
});

test('moving between furniture types changes image and an error does not disable other previews', () => {
  const h=harness();
  h.fire('focusin',{target:h.table}); h.flush();
  h.table.dataset.schemeNum=17;
  h.fire('focusin',{target:h.table}); h.flush();
  assert.equal(h.portal.children[0].src,previewSource(17));
  h.events.get('img:error')(); assert.equal(h.portal.hidden,true);
  h.table.dataset.schemeNum=7;
  h.fire('focusin',{target:h.table}); h.flush();
  assert.equal(h.portal.children[0].src,previewSource(7));
  assert.equal(h.portal.hidden,false);
  h.table.dataset.schemeNum=22;
  h.fire('focusin',{target:h.table}); h.flush();
  assert.equal(h.portal.hidden,true);
});

test('preview stays in the viewport at every map edge and on small screens', () => {
  for (const viewport of [{width:1440,height:900},{width:390,height:640},{width:240,height:180}]) {
    for (const anchor of [{left:0,right:80,top:0,height:60},{left:viewport.width-70,right:viewport.width,top:viewport.height-60,height:60}]) {
      const box=positionPreview(anchor,viewport);
      assert.ok(box.left>=12 && box.top>=12);
      assert.ok(box.left+box.width<=viewport.width-12);
      assert.ok(box.top+box.height<=viewport.height-12);
    }
  }
});

function harness() {
  const events=new Map();
  const node=tag=>({tag,children:[],style:{},attributes:{},hidden:false,
    appendChild(child){this.children.push(child);},setAttribute(k,v){this.attributes[k]=v;},getAttribute(k){return k==='src'?this.src:this.attributes[k];},remove(){this.removed=true;},
    addEventListener(type,fn){events.set(this.tag+':'+type,fn);},removeEventListener(type){events.delete(this.tag+':'+type);}});
  const body=node('body');
  const doc=Object.assign(node('doc'),{body,documentElement:{lang:'ru'},getElementById(){return null;},createElement:node,querySelector(){return modal?{}:null;}});
  let modal=false,scheduled=null,observe;
  const win=Object.assign(node('window'),{innerWidth:1000,innerHeight:700,matchMedia(){return {matches:true};},setTimeout(fn){scheduled=fn;return 1;},clearTimeout(){scheduled=null;},MutationObserver:class {constructor(fn){observe=fn;}observe(){}disconnect(){}}});
  const table={dataset:{schemeNum:1},isConnected:true,closest(selector){return selector==='[hidden]'?null:this;},contains(other){return other===this;},getBoundingClientRect(){return {left:50,right:100,top:100,height:60};}};
  const fire=(type,event={})=>events.get('doc:'+type)?.(event);
  const controller=init(doc,win);
  const portal=body.children[0];
  return {controller,portal,table,fire,events,doc,win,flush(){scheduled?.();},mutate(records=[]){observe(records);},setModal(){modal=true;}};
}

test('preview lazy loads on mouse intent, keeps clicks untouched and dismisses on Escape',()=>{
  const h=harness();
  const image=h.portal.children[0];
  assert.equal(image.src,undefined);
  h.fire('pointerover',{pointerType:'touch',target:h.table});h.flush();
  assert.equal(image.src,undefined);
  h.fire('pointerover',{pointerType:'mouse',target:h.table});h.flush();
  assert.equal(image.src,'/tr3/assets/gazebo-preview-v1.png');
  assert.equal(h.portal.hidden,false);
  assert.equal(h.portal.children[1].textContent,'Иллюстрация');
  h.fire('keydown',{key:'Escape'});assert.equal(h.portal.hidden,true);
  h.fire('focusin',{target:h.table});h.flush();assert.equal(h.portal.hidden,false);
  // Handler must not call preventDefault/stopPropagation or replace booking.
  h.fire('click',{preventDefault(){assert.fail('booking blocked');},stopPropagation(){assert.fail('booking stopped');}});
  assert.equal(h.portal.hidden,true);
  h.controller.destroy();assert.equal(h.portal.removed,true);
});

test('touch focus stays quiet and rerender, scroll or modal dismiss pending/visible previews',()=>{
  const h=harness();
  h.fire('pointerdown',{pointerType:'touch'});
  h.fire('focusin',{target:h.table});h.flush();assert.equal(h.portal.hidden,true);
  h.fire('keydown',{key:'Tab'});h.fire('focusin',{target:h.table});h.flush();assert.equal(h.portal.hidden,false);
  h.fire('scroll');assert.equal(h.portal.hidden,true);
  h.fire('focusin',{target:h.table});h.flush();h.table.isConnected=false;h.mutate();assert.equal(h.portal.hidden,true);
  h.table.isConnected=true;h.fire('focusin',{target:h.table});h.flush();h.setModal();h.mutate();assert.equal(h.portal.hidden,true);
});


test('preview caption follows the current language on subsequent intent', () => {
  const h=harness();
  const caption=h.portal.children[1];
  assert.equal(caption.getAttribute('data-i18n'),'preview_illustration');
  h.doc.documentElement.lang='vi';
  h.win.STR={preview_illustration:'Hình minh họa'};
  h.fire('focusin',{target:h.table});h.flush();
  assert.equal(caption.textContent,'Hình minh họa');
  h.doc.documentElement.lang='en';
  h.win.STR={preview_illustration:'Illustration'};
  h.fire('focusin',{target:h.table});h.flush();
  assert.equal(caption.textContent,'Illustration');
});
