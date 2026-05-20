<?php

declare(strict_types=1);

namespace App\Order\Services;

use App\Order\Contracts\PosterMenuProviderInterface;
use App\Order\Domain\Category;
use App\Order\Domain\MenuItem;
use App\Order\Domain\ModificationAddOn;
use App\Order\Domain\ModifierGroup;
use App\Order\Domain\ModifierOption;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Reads the live Poster menu (no DB cache). Categories come from
 * menu.getCategories; products + their modifier groups + add-on
 * modifications from menu.getProducts (with `hidden=0`). The two
 * shapes are normalised into our domain DTOs so the rest of the
 * stack never touches Poster's raw response.
 */
final class PosterMenuProvider implements PosterMenuProviderInterface
{
    private int $spotId;

    public function __construct(
        private readonly PosterApiProviderInterface $poster,
    ) {
        $env = $_ENV['POSTER_SPOT_ID'] ?? getenv('POSTER_SPOT_ID');
        $this->spotId = is_numeric($env) ? (int)$env : 1;
        if ($this->spotId <= 0) $this->spotId = 1;
    }

    /** @return Category[] */
    public function fetchCategories(): array
    {
        $rows = $this->poster->client()->request('menu.getCategories', [], 'GET');
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            // Skip deleted / hidden categories — Poster marks them with
            // category_hidden=1 (sometimes 'deleted' too on older shops).
            if ((string)($r['category_hidden'] ?? '0') === '1') continue;
            if ((string)($r['deleted']         ?? '0') === '1') continue;
            $out[] = Category::fromPoster($r);
        }
        usort($out, static fn(Category $a, Category $b) => ($a->sort <=> $b->sort) ?: ($a->id <=> $b->id));
        return $out;
    }

    /** @return MenuItem[] */
    public function fetchActiveProducts(): array
    {
        // hidden=0 already filters most inactive items; we still belt-and-
        // suspenders the response in case Poster's flag is missing.
        $rows = $this->poster->client()->request('menu.getProducts', ['hidden' => 0], 'GET');
        if (!is_array($rows)) return [];

        $items = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if ((string)($r['hidden']      ?? '0') === '1') continue;
            if ((string)($r['deleted']     ?? '0') === '1') continue;
            // Some Poster shops mark soft-removed items via `out_of_menu`
            // / `archive` / `is_deleted` instead of `deleted`. Belt and
            // suspenders — drop anything that looks archived.
            if ((string)($r['out_of_menu'] ?? '0') === '1') continue;
            if ((string)($r['archive']     ?? '0') === '1') continue;
            if ((string)($r['is_deleted']  ?? '0') === '1') continue;

            // Per-spot visibility — Poster's menu.getProducts returns a
            // spots[] array describing per-location overrides; a product
            // can be globally non-hidden yet hidden in our specific spot
            // (the operator removed it from this venue's menu). Honour
            // the spot's `visible` flag when present.
            if (!empty($r['spots']) && is_array($r['spots'])) {
                $thisSpot = null;
                foreach ($r['spots'] as $sp) {
                    if (is_array($sp) && (int)($sp['spot_id'] ?? 0) === $this->spotId) {
                        $thisSpot = $sp;
                        break;
                    }
                }
                if ($thisSpot !== null && (string)($thisSpot['visible'] ?? '1') === '0') continue;
            }

            $id = (int)($r['product_id'] ?? 0);
            if ($id <= 0) continue;

            $items[] = new MenuItem(
                id:             $id,
                categoryId:     (int)($r['menu_category_id'] ?? $r['category_id'] ?? 0),
                name:           trim((string)($r['product_name'] ?? '')),
                priceVnd:       $this->priceVnd($r),
                hidden:         (string)($r['hidden'] ?? '0') === '1',
                photoUrl:       $this->photoUrl($r),
                modifierGroups: $this->parseModifierGroups($r),
                modifications:  $this->parseModifications($r),
                sort:           (int)($r['sort_order'] ?? 0),
            );
        }
        usort(
            $items,
            static fn(MenuItem $a, MenuItem $b) => ($a->sort <=> $b->sort) ?: strcmp($a->name, $b->name),
        );
        return $items;
    }

    /** Poster sends `price` as either a flat string or an object keyed by spot id. */
    private function priceVnd(array $r): int
    {
        $p = $r['price'] ?? null;
        if (is_array($p)) {
            // Prefer this spot's price; fall back to the first available.
            $raw = $p[(string)$this->spotId] ?? reset($p);
        } else {
            $raw = $p;
        }
        if ($raw === null || $raw === '') return 0;
        // Stored as 1/100 VND (legacy cents convention).
        return (int)round(((float)$raw) / 100);
    }

    private function photoUrl(array $r): string
    {
        $candidate = (string)($r['photo'] ?? $r['photo_origin'] ?? '');
        if ($candidate === '') return '';
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) return $candidate;
        return 'https://joinposter.com' . (str_starts_with($candidate, '/') ? '' : '/') . $candidate;
    }

    /** @return ModifierGroup[] */
    private function parseModifierGroups(array $r): array
    {
        // Two shapes from Poster:
        //   - `modifications` on dish-type products → group [{ modificator_id, modificator_name, modificator_selectors[]: {modificator_id, modificator_name, modificator_price[]} }]
        //   - `modificators` array on simple products → flat list of options for ONE implicit group
        $out = [];
        $mods = $r['modifications'] ?? null;
        if (is_array($mods) && $mods) {
            foreach ($mods as $g) {
                if (!is_array($g)) continue;
                $opts = [];
                foreach (($g['modificator_selectors'] ?? []) as $sel) {
                    if (!is_array($sel)) continue;
                    $opts[] = new ModifierOption(
                        id:       (int)($sel['modificator_id'] ?? 0),
                        name:     trim((string)($sel['modificator_name'] ?? '')),
                        priceVnd: $this->priceVnd(['price' => $sel['modificator_price'] ?? 0]),
                    );
                }
                if (!$opts) continue;
                $out[] = new ModifierGroup(
                    id:       (int)($g['modificator_id'] ?? $g['dish_modification_group_id'] ?? 0),
                    name:     trim((string)($g['modificator_name'] ?? $g['name'] ?? '')),
                    required: true,                    // Poster's "modifications" group = required pick
                    options:  $opts,
                );
            }
        }

        $flat = $r['modificators'] ?? $r['modifications_atributes'] ?? null;
        if (is_array($flat) && $flat) {
            $opts = [];
            foreach ($flat as $m) {
                if (!is_array($m)) continue;
                $opts[] = new ModifierOption(
                    id:       (int)($m['modificator_id'] ?? 0),
                    name:     trim((string)($m['modificator_name'] ?? '')),
                    priceVnd: $this->priceVnd(['price' => $m['modificator_price'] ?? 0]),
                );
            }
            if ($opts) {
                $out[] = new ModifierGroup(0, '', true, $opts);
            }
        }

        return $out;
    }

    /** @return ModificationAddOn[] */
    private function parseModifications(array $r): array
    {
        // group_modifications[] — optional add-ons (a dish may have several
        // groups, e.g. "Sauces" and "Toppings"; each option contributes its
        // own price). We flatten across groups since the JS treats them as
        // a single multi-select list grouped by group_name.
        $out = [];
        $groups = $r['group_modifications'] ?? null;
        if (!is_array($groups)) return $out;

        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $gid   = (int)($g['dish_modification_group_id'] ?? $g['group_id'] ?? 0);
            $gname = trim((string)($g['name'] ?? ''));
            foreach (($g['modifications'] ?? []) as $m) {
                if (!is_array($m)) continue;
                $out[] = new ModificationAddOn(
                    id:        (int)($m['dish_modification_id'] ?? $m['id'] ?? 0),
                    groupId:   $gid,
                    groupName: $gname,
                    name:      trim((string)($m['name'] ?? '')),
                    priceVnd:  $this->priceVnd(['price' => $m['price'] ?? 0]),
                );
            }
        }
        return $out;
    }
}
