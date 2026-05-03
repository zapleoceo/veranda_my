# Links Page — SEO & AI Optimization Design

## Goal
Optimize `/links/` for search engines and LLM-based crawlers while keeping the current UI/UX unchanged.

Primary signals:
- Localized SEO title/description per language
- Clean, consistent canonical + hreflang alternates
- Social preview card (og/twitter image) that matches brand/background
- Rich structured data (JSON-LD) describing the venue and key actions

## Scope
In scope:
- `<title>`, meta description, OpenGraph/Twitter meta
- `hreflang` alternates for `ru/en/vi/ko` + `x-default`
- Canonical strategy
- New `og-image` asset using existing background image
- JSON-LD expansion for Restaurant entity
- Keep existing client-side language switching (no full reload) and make SEO tags update on language switch

Out of scope:
- Changing page background or layout
- Changing menu/reservation destinations
- Adding new pages or routes beyond a static og-image asset for this page

## URL & Language Strategy
We keep shareable URLs per language:
- `/links/?lang=ru`
- `/links/?lang=en`
- `/links/?lang=vi`
- `/links/?lang=ko`

Rules:
- `canonical` points to the language-specific URL when `?lang=` is set.
- `hreflang` includes all 4 language URLs + `x-default` pointing to `/links/`.
- Client-side language switch updates:
  - `document.documentElement.lang`
  - `document.title`
  - `meta[name="description"]`
  - `meta[property="og:title"]`, `meta[property="og:description"]`, `meta[property="og:url"]`
  - `meta[name="twitter:title"]`, `meta[name="twitter:description"]`
  - `link[rel="canonical"]`
  - browser URL via `history.replaceState` (no reload)

Fallback:
- With JS disabled, server-side `?lang=` continues to work.

## Localized SEO Titles
All titles include venue type and location, localized:
- **ru**: `Veranda — ресторан и бар, Нячанг, Вьетнам`
- **en**: `Veranda - restaurant and bar. Nha Trang, Vietnam`
- **vi**: `Veranda - nhà hàng & quầy bar, Nha Trang, Việt Nam`
- **ko**: `Veranda — 레스토랑 & 바, 나트랑, 베트남`

Note: visual brand header stays as-is (VERANDA / RESTAURANT & BAR), not localized.

## Localized Meta Descriptions
Short, action-oriented, with location. One per language:
- ru: mention меню / бронирование / контакты + location
- en/vi/ko: same semantics

Also mirror into `og:description` and `twitter:description`.

## OpenGraph / Twitter Image
Create a dedicated social card image:
- Path: `/links/og-image.svg`
- Size: 1200×630
- Background: existing `/assets/img/links_bg.png`
- Text overlay: `Veranda - restaurant and bar. NhaTrang, Vietnam` (exact phrase as requested for the image)

Meta tags:
- `og:image` → `https://veranda.my/links/og-image.svg`
- `og:image:width` = `1200`
- `og:image:height` = `630`
- `twitter:image` → same
- `twitter:card` remains `summary_large_image`

## Structured Data (JSON-LD)
Expand the existing `Restaurant` JSON-LD with:
- `name`: `Veranda`
- `image`: `https://veranda.my/links/og-image.svg`
- `url`: language-specific URL
- `address`: `Nha Trang, Khánh Hòa, Vietnam` (LocalBusinessPostalAddress)
- `telephone`: `+84396314266`
- `sameAs`: Telegram / WhatsApp / Facebook / Maps
- `hasMenu`: `https://veranda.my/links/menu.php`
- `potentialAction`:
  - `ReserveAction` targeting `https://veranda.my/tr3`

## Versioning / Delivery
Bump static asset versions in `view.php` querystrings for:
- `/assets/css/links_index.css`
- `/links/links_fx.js`

After implementation, push to `main`.

