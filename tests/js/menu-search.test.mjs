import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const { matches, init } = createRequire(import.meta.url)('../../assets/js/menu-search.js');

function fixture() {
  const dom = new JSDOM(`<div id="menuContent"><input data-menu-search><button data-menu-search-clear></button>
    <p data-menu-search-status data-results="Found: {count}" data-empty="No matches"></p>
    <div class="section"><details data-key="food"><summary><span>Food</span><span class="sum-right">2</span></summary>
      <div class="item"><div class="item-title">Cà phê sữa đá</div><div class="item-desc">Milk coffee</div></div>
      <div class="item"><div class="item-title">Суп</div><div class="item-desc">Курица и рис</div></div>
    </details></div><div class="section"><details data-key="drinks" open><summary><span>Drinks</span><span class="sum-right">1</span></summary>
      <div class="item"><div class="item-title">Tea</div></div></details></div></div>`);
  const content = dom.window.document.querySelector('#menuContent');
  const input = content.querySelector('input');
  const search = init(content);
  const type = (value) => { input.value = value; input.dispatchEvent(new dom.window.Event('input')); };
  return { dom, content, input, search, type, categories: [...content.querySelectorAll('details')] };
}

test('Unicode normalization, token order and literal query', () => {
  assert.ok(matches('Cà phê sữa đá', 'DA ca phe'));
  assert.ok(matches('Ёжик и чай', 'ежик'));
  assert.ok(matches('한국어 메뉴', '메뉴'));
  assert.ok(matches('Milk coffee', 'coffee milk'));
  assert.equal(matches('Milk coffee', 'coffee tea'), false);
  assert.equal(matches('Milk coffee', '<img src=x onerror=alert(1)>'), false);
});
test('live filtering opens matches, hides empty groups, updates counts and restores expansion', () => {
  const f = fixture();
  f.type('курица');
  assert.equal(f.categories[0].open, true);
  assert.equal(f.categories[0].querySelector('.sum-right').textContent, '1');
  assert.equal(f.categories[0].querySelector('.item').hidden, true);
  assert.equal(f.categories[1].parentElement.hidden, true);
  assert.equal(f.content.querySelector('[data-menu-search-status]').textContent, 'Found: 1');
  assert.deepEqual(f.search.openKeys(), ['drinks']);
  f.type('');
  assert.equal(f.categories[0].open, false);
  assert.equal(f.categories[1].open, true);
  assert.equal(f.categories[1].parentElement.hidden, false);
  assert.equal(f.categories[0].querySelector('.sum-right').textContent, '2');
});
test('empty results, whitespace, clear button and keyboard', () => {
  const f = fixture();
  f.type('absent');
  assert.equal(f.content.querySelector('[data-menu-search-status]').textContent, 'No matches');
  f.input.dispatchEvent(new f.dom.window.KeyboardEvent('keydown', { key: 'Escape', cancelable: true }));
  assert.equal(f.input.value, '');
  assert.equal(f.dom.window.document.activeElement, f.input);
  f.type('tea');
  f.content.querySelector('button').click();
  assert.equal(f.search.query(), '');
  f.type('  ');
  assert.equal(f.categories.some((node) => node.hidden), false);
});
test('a newly translated menu initializes with the current search', () => {
  const f = fixture();
  const next = f.content.cloneNode(true);
  init(next, 'tea');
  assert.equal(next.querySelector('input').value, 'tea');
  assert.equal(next.querySelector('details').hidden, true);
  assert.equal(next.querySelectorAll('details')[1].open, true);
});
test('language switch replaces mounted content without fallback navigation', async () => {
  const f = fixture();
  const html = `<html lang="en"><body><details class="lang-menu"><summary>Language</summary><div class="lang-panel"><a href="?lang=vi">VI</a></div></details>${f.content.outerHTML}</body></html>`;
  const dom = new JSDOM(html, {url:'https://example.test/menu', runScripts:'outside-only'});
  dom.window.matchMedia = () => ({matches:false});
  dom.window.requestAnimationFrame = (callback) => callback();
  dom.window.scrollTo = () => {};
  dom.window.fetch = async () => ({ok:true, text:async () => html.replace('lang="en"', 'lang="vi"')});
  dom.window.eval(readFileSync(new URL('../../assets/js/menu-search.js', import.meta.url), 'utf8'));
  dom.window.eval(readFileSync(new URL('../../assets/js/menu-beta.js', import.meta.url), 'utf8'));
  const input = dom.window.document.querySelector('input');
  input.value = 'tea'; input.dispatchEvent(new dom.window.Event('input'));
  dom.window.document.querySelector('a').click();
  await new Promise((resolve) => setTimeout(resolve, 350));
  assert.equal(dom.window.document.querySelectorAll('#menuContent').length, 1);
  assert.equal(dom.window.document.documentElement.lang, 'vi');
  assert.equal(dom.window.document.querySelector('input').value, 'tea');
  assert.equal(dom.window.document.querySelectorAll('details[data-key]')[1].open, true);
  const nextInput = dom.window.document.querySelector('input');
  nextInput.value = ''; nextInput.dispatchEvent(new dom.window.Event('input'));
  assert.equal(dom.window.document.querySelector('details[data-key]').open, false);
  dom.window.close();
});

test('rapid completed language swaps preserve latest mounted menu and search', async () => {
  const f = fixture();
  const html = `<html lang="en"><body><details class="lang-menu"><div class="lang-panel"><a href="?lang=vi">VI</a><a href="?lang=ko">KO</a></div></details>${f.content.outerHTML}</body></html>`;
  const dom = new JSDOM(html, {url:'https://example.test/menu', runScripts:'outside-only'});
  dom.window.matchMedia = () => ({matches:false});
  dom.window.requestAnimationFrame = (callback) => callback();
  dom.window.scrollTo = () => {};
  dom.window.fetch = async (url) => ({ok:true, text:async () => html.replace('lang="en"', `lang="${new URL(url).searchParams.get('lang')}"`)});
  for (const file of ['menu-search.js', 'menu-beta.js']) dom.window.eval(readFileSync(new URL('../../assets/js/' + file, import.meta.url), 'utf8'));
  const links = dom.window.document.querySelectorAll('a');
  links[0].click();
  await new Promise((resolve) => setTimeout(resolve, 30));
  links[1].click();
  await new Promise((resolve) => setTimeout(resolve, 350));
  assert.equal(dom.window.document.documentElement.lang, 'ko');
  assert.equal(dom.window.document.querySelectorAll('#menuContent').length, 1);
  const input = dom.window.document.querySelector('#menuContent input');
  input.value = 'tea'; input.dispatchEvent(new dom.window.Event('input'));
  assert.equal(dom.window.document.querySelector('[data-menu-search-status]').textContent, 'Found: 1');
  assert.equal(dom.window.document.querySelectorAll('.i18n-xfade').length, 0);
  dom.window.close();
});
