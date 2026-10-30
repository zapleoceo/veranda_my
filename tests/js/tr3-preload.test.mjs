import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('early preload URLs match deferred boot scripts without changing execution order', () => {
  const view = readFileSync(new URL('../../tr3/index.php', import.meta.url), 'utf8');
  const boot = readFileSync(new URL('../../tr3/assets/tr3.boot.js', import.meta.url), 'utf8');
  const preloads = [...view.matchAll(/rel="preload" as="script" href="([^"]+)"/g)].map((match) => match[1]);
  assert.equal(preloads.length, 2);
  for (const url of preloads) {
    assert.ok(boot.includes(`loadScript('${url}')`));
    assert.ok(readFileSync(new URL('../..' + url.split('?')[0], import.meta.url)).length > 0);
    assert.equal(view.includes(`<script src="${url}"`), false, 'preload must not execute app before bootstrap');
  }
});
