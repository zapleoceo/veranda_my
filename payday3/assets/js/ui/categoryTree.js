// Poster finance categories as a tree — shared by the settings modal
// (whitelist checkboxes) and the create-transaction popup (<select>).
// Pure, no DOM — tested in tests/js/payday3/categoryTree.test.mjs.

'use strict';

/**
 * @param {Record<string, {name?:string, parent_id?:number|string}>} map
 *   Server shape (same as payday2): { <category_id>: { name, parent_id } }.
 * @returns {{roots: object[], byId: Record<number, object>}}
 *   nodes are { id, name, parent_id, children[] }; a node whose parent is
 *   missing becomes a root.
 */
export function buildCategoryTree(map) {
    const byId  = {};
    const roots = [];
    for (const [idStr, data] of Object.entries(map || {})) {
        const id = Number(idStr);
        byId[id] = { id, name: String(data?.name || ''), parent_id: Number(data?.parent_id || 0), children: [] };
    }
    for (const id in byId) {
        const node = byId[id];
        if (node.parent_id && byId[node.parent_id]) byId[node.parent_id].children.push(node);
        else                                         roots.push(node);
    }
    return { roots, byId };
}

/**
 * Depth-first walk, parents before children.
 * @param {object[]} roots
 * @param {(node:object, depth:number) => void} visit
 */
export function walkCategories(roots, visit) {
    const go = (node, depth) => {
        visit(node, depth);
        for (const c of node.children) go(c, depth + 1);
    };
    for (const r of roots) go(r, 0);
}

/** Operator's custom label for a category id ('' when none). */
export function customName(custom, id) {
    const v = custom?.[id] ?? custom?.[String(id)];
    return v != null ? String(v) : '';
}
