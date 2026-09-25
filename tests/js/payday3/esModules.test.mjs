// payday3 modules must use plain static imports: top-level-await
// dynamic imports (the old ui/cacheBust.js cascade) hung iOS WebKit.
// Also checks every static specifier resolves to an existing file.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../payday3/assets/js');

function walk(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
        e.isDirectory() ? walk(path.join(dir, e.name)) : e.name.endsWith('.js') ? [path.join(dir, e.name)] : []);
}
const files = walk(ROOT);
const src = (f) => fs.readFileSync(f, 'utf8');
const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"\\])\/\/.*$/gm, '$1');

test('payday3 JS: no dynamic import(), no cacheBust', () => {
    assert.ok(files.length > 20);
    for (const f of files) {
        const code = stripComments(src(f));
        assert.doesNotMatch(code, /\bimport\s*\(/, `${path.relative(ROOT, f)}: dynamic import()`);
        assert.doesNotMatch(src(f), /cacheBust/, `${path.relative(ROOT, f)}: cacheBust`);
        assert.doesNotMatch(code, /\bawait\s+_i\(/, `${path.relative(ROOT, f)}: _i() importer`);
    }
    assert.ok(!fs.existsSync(path.join(ROOT, 'ui/cacheBust.js')));
});

test('payday3 JS: every static import is relative, query-less and resolves to a file', () => {
    const re = /^\s*(?:import|export)\s[^;]*?\sfrom\s*['"]([^'"]+)['"]|^\s*import\s*['"]([^'"]+)['"]/gm;
    let n = 0;
    for (const f of files) {
        for (const m of stripComments(src(f)).matchAll(re)) {
            const spec = m[1] ?? m[2];
            n++;
            assert.match(spec, /^\.\.?\//, `${path.relative(ROOT, f)}: non-relative ${spec}`);
            assert.ok(!spec.includes('?'), `${path.relative(ROOT, f)}: query in ${spec}`);
            const target = path.resolve(path.dirname(f), spec);
            assert.ok(fs.existsSync(target), `${path.relative(ROOT, f)}: ${spec} does not exist`);
            assert.ok(target.startsWith(ROOT), `${spec} escapes js root`);
        }
    }
    assert.ok(n > 50, `parsed ${n} imports`);
});
