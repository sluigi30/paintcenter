# NCM Paint Center — Session Context

> Last updated: 2026-07-25

---

## What Was Built

### 1. Mobile App Reference
Added to `CLAUDE.md`: the customer-facing mobile app lives at `C:\laragon\www\paintcenter-mobile` and consumes this project's REST API.

---

### 2. Super Admin Role

**Migration:** `database/migrations/2026_06_30_000001_add_super_admin_role_to_users_table.php`
- Adds `super_admin` to the `role` enum (alongside `admin`, `customer`)

**`app/Models/User.php`**
- `isSuperAdmin()` / `isAdmin()` helpers
- `canAccessPanel()` blocks customers AND archived admins

**`app/Filament/Resources/UserResource.php`** — Administration group
- Only visible/editable by super_admin (`canViewAny`, `canCreate`, `canEdit` all gate on `isSuperAdmin()`)
- Shows only `role = 'admin'` users (super_admin cannot see/edit themselves here)
- Deactivate/Activate toggle instead of Delete

**`app/Filament/Resources/UserResource/Pages/CreateUser.php`**
- Forces `role = 'admin'` on every create — cannot create another super_admin via UI

**Seeded accounts** (password: `password`)
- `superadmin@paintcenter.com` → `super_admin`
- `admin@paintcenter.com` → `admin`

---

### 3. Archive Pattern (no hard delete)

**Migration:** `database/migrations/2026_06_30_000002_add_is_archived_to_brands_categories_users_table.php`
- Adds `is_archived boolean default false` to `brands`, `categories`, `users`
- Products already had it

**Pattern applied to:** BrandResource, CategoryResource, UserResource
- Archive/Unarchive toggle action (row action + edit page action)
- Status badge column (Active / Archived)
- SelectFilter for status
- API filters `is_archived = false` on brands and categories (`ProductController@brands/categories`)

---

### 4. Styling Upgrades

**`app/Providers/Filament/AdminPanelProvider.php`**
- Primary colour: `#ea580c` (orange) — **superseded by NCM brand red, see §7**
- Font: `Inter`
- SPA mode enabled
- Sidebar collapsible on desktop
- Global search: `Ctrl+K` / `Cmd+K`
- Navigation groups: **Store Management**, **Operations**, **Administration**

**Unique icons per resource**

| Resource | Icon |
|---|---|
| Products | `heroicon-o-swatch` |
| Brands | `heroicon-o-building-storefront` |
| Categories | `heroicon-o-squares-2x2` |
| Orders | `heroicon-o-clipboard-document-list` |
| Inventory | `heroicon-o-inbox-stack` |
| Admin Accounts | `heroicon-o-user-group` |
| Messages | `heroicon-o-chat-bubble-left-right` |
| Reports | `heroicon-o-chart-bar` |

---

### 5. Reports Page

**`app/Filament/Pages/Reports.php`**
- **SQLite/MySQL compatibility** — `DB::getDriverName()` switches between `strftime()` and `DATE_FORMAT()`
- **Reactive charts** — `$this->dispatch('rpt:data', ...)` fires at end of `loadReports()`; JS listens with `window.addEventListener('rpt:data', ...)`
- **Chart.js** — destroy-recreate pattern using `Chart.getChart(id)?.destroy()` to survive Livewire re-renders
- **Period comparison** — toggle + compare date range; `pctChange()` helpers for KPI deltas
- Helpers: `periodLabel()`, `comparePeriodLabel()`, `dayCount()`

**`resources/views/filament/pages/reports.blade.php`**
- Screen toolbar with quick presets (7d / 30d / 90d / MTD / Last Month / YTD) + custom date range
- 4 KPI cards with % change vs prior period
- Revenue Over Time chart (Chart.js line) + Orders by Status doughnut
- Top Products, Revenue by Category, Delivery vs Pickup
- Recent Orders table, Inventory Summary with stock activity log

**Print / Export PDF**
- Button calls `window.print()`
- **Dark mode print fix** — 3-layer CSS kill switch inside `@media print`:
  1. `color-scheme: light` on `html`
  2. Override all `--gray-*` and `--color-*` Filament CSS variables to light values on `html, html.dark, *`
  3. `background: white; color: #111827` on all Filament page containers

  > Filament 4.0 dark mode uses `.dark` class on `<html>` with Tailwind 4 `--gray-*` CSS variables. Overriding them with `!important` inside `@media print` cascades to all `var()` usages including inline styles.

- **Professional 9-section layout** (print-only, screen content hidden with `no-print`):
  1. Executive Summary — KPI boxes, orange top border
  2. Period Comparison table — orange header row (only if compare enabled)
  3. Sales Performance — date-by-date data table
  4. Top Products by Revenue
  5. Orders by Status
  6. Revenue by Category
  7. Delivery vs Pickup
  8. Inventory Snapshot — stat boxes + stock log
  9. Recent Orders — full table
  - Footer: confidentiality notice + timestamp
  - Page break between sections 7 and 8

---

### 6. Stock Alert Notification System (2026-07-04)

Professional low-stock / out-of-stock alerting for admins, in two coordinated parts.

**Migration:** `database/migrations/2026_07_04_000001_create_notifications_table.php`
- Standard Laravel `notifications` table — backs Filament's notification bell

**`app/Providers/Filament/AdminPanelProvider.php`**
- `->databaseNotifications()` + `->databaseNotificationsPolling('30s')` — bell icon in topbar with unread badge; polls every 30s so alerts appear without refresh

**`app/Observers/ProductObserver.php`** (registered via `#[ObservedBy]` on `Product`) — **moved to `ProductVariantObserver` in the variants refactor, see §10; same transition logic, per size**
- Fires on every product update from ANY source: `InventoryLog::record()`, API order deduct/cancel (`increment`/`decrement` on model instances fire events), Filament edits
- Severity model: in_stock(0) → low_stock(1) → out_of_stock(2); notifies **only on downward transitions** — repeated sales while already low do NOT re-alert; restocking never alerts
- Sends to all active `admin` + `super_admin` users
- **Restock action button** deep-links to Inventory with the product pre-searched (`?search=<description>`)

**`app/Listeners/SendStockAlertsOnLogin.php`** (auto-discovered on `Illuminate\Auth\Events\Login`)
- On admin login, ONE "briefing" of the CURRENT stock state goes to two places:
  - instant session-flashed popup (`Notification->send()` survives the login redirect)
  - the notification bell, where it **replaces** the previous briefing (matched by title via `data->title`) — never stacks; removed entirely when stock is healthy
- Executive copy: time-of-day greeting + first name, correct pluralization, names sold-out items when ≤ 3
- Titles: `Inventory Action Required` (danger, any sold out) / `Inventory Advisory` (warning, low only)
- **Review Inventory** button deep-links to Inventory filtered to `attention`
- Skips non-admins and requests without a session (API logins don't fire the event anyway — AuthController doesn't use `Auth::attempt`)

**`app/Filament/Resources/InventoryResource.php`**
- Stock Status filter gained `attention` option (Low + Out combined) — **the landing target for notification deep links; do not remove**
- "Adjustments" (log count) column removed to avoid x-axis scroll — full history still via View Logs action

**`app/Filament/Resources/ProductResource.php`**
- New Stock Level filter (Needs Attention / Out of Stock / Low Stock / Healthy)

---

### 7. NCM Brand Red Theme (2026-07-11)

Rebrand from orange to the storefront's red, admin panel first (public welcome page + mobile app still orange — pending).

**Palette:** `#b91c1c` primary (deep signage red) · `#dc2626` bright accent / gradient top · `#991b1b` hover/dark · `#fef2f2` light tint · shadows `rgba(185,28,28,…)`

- **`AdminPanelProvider`** — `primary => Color::hex('#b91c1c')`; `danger => Color::Rose` so destructive actions stay distinguishable from the now-red primary
- **`reports.blade.php`** — all hardcoded orange hexes swapped (filter console, active buttons, Export gradient, chart line, print letterhead/section headers/KPI borders; `prt-orange-hd` class renamed `prt-red-hd`)
- **`messages.blade.php`** — badges, sent bubbles, Send button
- Semantic colors intentionally kept: green success, amber low-stock warning, red "down" arrows

---

### 8. Messages Page Redesign + Theme Token Fix (2026-07-11)

**Root bug:** the old page used `rgba(255,255,255,.1)` borders — invisible in light mode. Worse, `reports.blade.php` referenced CSS vars (`--color-background-secondary`, `--color-border-tertiary`, …) that were **never defined anywhere** — borders silently fell back to `currentColor`.

- `messages.blade.php` rebuilt with scoped theme tokens on `#msg-root` (light defaults, `html.dark` overrides): avatar initials on red gradient, active conversation with red left-accent bar, unread pills, thread header with customer name, chat-style bubbles (sent = red gradient), red-focus composer, empty states, responsive <768px. All Livewire behavior (5s poll, auto-scroll, enter-to-send) unchanged.
- `reports.blade.php` now defines those `--color-*` tokens at the top of its `<style>` (`:root` light + `html.dark` overrides); print overrides them anyway.

---

### 9. Reports Refinements (2026-07-11)

**Chart reliability** — line graph intermittently blank on filter changes. Four stacked causes, all fixed in `reports.blade.php`:
1. Livewire morphs touched the canvases → both canvases now wrapped in `wire:ignore` divs with fixed heights (`maintainAspectRatio: false`)
2. Redraw event raced the DOM morph → renderer draws inside `requestAnimationFrame`
3. SPA mode re-runs the inline script per visit → renderer defined ONCE behind `window.__rptCharts` guard; instances kept on `window` for reliable destroy-recreate
4. CDN Chart.js could load after first render → renderer polls (50ms × 80) for `window.Chart` + canvases before drawing

**Data bugs:**
- 31-day range grouped monthly (one dot): Carbon 3 `diffInDays()` returns a FLOAT against `endOfDay` timestamps (30.99…), tipping the `<= 31` daily check. Now uses integer `dayCount()`.
- Days with no sales were missing → `fillSeries()` zero-fills every day/month in the range (also keeps compare series index-aligned). Labels now "Jun 11" / "Jun 2026".
- Axes: `beginAtZero`, Orders axis `precision: 0`, x-axis `maxTicksLimit: 12`.

**UX:**
- Quick Range presets removed (`$preset`, `applyPreset()` deleted); single "Date Range" picker remains; changing dates now auto-realigns the comparison window (`autoSetComparePeriod()` on both `updatedDateFrom/To`)
- **Print signatories** — "Certification & Approval" block before the footer: *Prepared by* (auto-filled logged-in admin + role + date), *Reviewed by* (Operations Manager), *Approved by* (General Manager / Proprietor); `page-break-inside: avoid`

---

### 10. Product Variants — Per-Size Price & Stock (2026-07-11)

**The big one.** Paint sizes ("1L, 4L, 16L") were a comma string on `products.size_volume` with ONE price and ONE stock pool. Mobile picked a size but checkout ignored it entirely (order items had no size column; cart merged different sizes into one line).

**Migration:** `2026_07_11_000001_create_product_variants_and_convert_inventory.php`
- Creates `product_variants` (product_id, size_volume, price, stock, low_stock_threshold, is_archived; unique product+size)
- Drops `size_volume`/`price`/`stock`/`low_stock_threshold` from `products`
- `cart_items`: drops `selected_size`, adds `product_variant_id`
- `order_items`: adds `product_variant_id` + `size_volume` snapshot (like `unit_price`)
- `inventory_logs`: adds nullable `product_variant_id`
- **Prototype data wipe** (owner-approved): cleared products/orders/cart/logs/payments/sms; users, brands, categories preserved

**Model layer:**
- `ProductVariant` — price/stock/threshold owner; `display_name` ("Brand — Desc (4L)"); scopes `active()`, `lowStock()`, `outOfStock()`; observed by `ProductVariantObserver` (same downward-transition alert logic as old ProductObserver, per size)
- `Product` — identity only; appends computed `size_volume` (comma list), `price` (min), `stock` (sum), `stock_status` (worst case) so existing API consumers keep working; scopes became `whereHas('variants', …)`; new `purchasable()` = active + any in-stock active variant
- `InventoryLog::record(ProductVariant $variant, …)` — **signature changed**, logs carry both product_id and variant_id

**API:**
- Products index/show include `active_variants` array; catalog filtered by `purchasable()`; price filters match ANY active variant
- Cart: `POST /cart/add` takes `product_variant_id`; update/remove keyed by `cart_item_id` (route-model-bound, ownership-checked) — fixes size-collision bug; summary rows expose `size_volume`, per-variant price, `available_stock`
- Checkout: `lockForUpdate()` on the VARIANT, per-size stock check/deduct, size snapshot into order items; cancel restores variant stock

**Filament:**
- ProductResource: variants Repeater (size/price/threshold; initial stock create-only — `disabledOn('edit')`); table shows size badges, price range, total stock; `getEloquentQuery()` eager-loads variants
- InventoryResource: **model switched to ProductVariant** — one row per size; same Adjust/Logs/Set Alert actions; `attention` deep-link filter preserved; brand/category filters via `whereHas('product', …)`
- StatsOverview + LowStockWidget + login briefing + Reports inventory stats: all count/query variants

**Mobile (`paintcenter-mobile`):**
- `product/[id].jsx` — picker reads `active_variants`; per-size price inside each chip; sold-out sizes disabled; price/stock header follows selection ("from ₱…" before); qty capped at variant stock; posts `product_variant_id`
- `cart.jsx` — keyed by `cart_item_id`, size badge per line, + button clamped to `available_stock`
- Home list unchanged — computed `size_volume`/`price` appends keep it working

**Verified:** tinker end-to-end (rolled back) — aggregates, per-size restock + audit trail, alert transitions (4 admin notifications on threshold cross), catalog visibility (product visible until ALL sizes out). Test suite passes.

---

### 11. Product Color Code — simple version (2026-07-12)

Products carry the manufacturer color identity as **plain nullable fields**: `color_code` ("888") + `color_name` ("Red"), migration `2026_07_12_000002_add_color_code_to_products.php`. Admin form has the two text inputs + the ColorPicker relabeled "Screen Preview Color" (helper text teaches the eyedropper-on-brand-chart trick); products table shows a Code badge. Mobile product page shows "Color: 888 · Red" + "screen colors are indicative" disclaimer when a code exists. Fields flow through the API automatically (real columns).

### 12. Product Image Gallery (2026-07-12)

`products.image` (single) → `products.images` (ordered JSON array), migration `2026_07_12_000003_convert_product_image_to_gallery.php` (existing images carried over as one-item galleries). First entry = cover; model appends computed `image` accessor so every single-image consumer (CartController summary, mobile home list, admin ImageColumns incl. `product.image` in Inventory) works unchanged. Admin: `FileUpload::multiple()->reorderable()->maxFiles(8)`. Mobile product page: `FlatList` `pagingEnabled` carousel with dot indicators + "2/5" counter, hex-swatch fallback when no images. Deliberately JSON-on-product, not a `product_images` table — same "simple now" call as the color fields.

### 13. Brand-First Catalog + Smarter Search (2026-07-13)

Professor-suggested catalog structure: brands are the parent grouping.

- `brands.image` (logo) added — `2026_07_13_000001_add_image_to_brands_table.php`; BrandResource gets logo upload + column
- `GET /api/brands` now serves the catalog grid: active brands **with ≥1 purchasable product**, `products_count` counts purchasable only, ordered by name
- Mobile catalog landing = 2-col brand grid (logo tile or red lettered fallback + "N products"); tap → new `app/brand/[id].jsx` (brand-filtered product list, paginated). Search bar stays **global** — typing swaps the grid for product results; deliberately not brand-scoped
- Search upgrades: API `search` matches description OR `color_code` OR `color_name`; mobile search is debounced 350ms (fixes the per-keystroke spam + retry loop)
- **Gotcha found:** color codes pasted from manufacturer sites carry Unicode dashes (U+2011 etc.) — "B‑1408" stored ≠ "B-1408" searched. Fix: `Product::normalizeColorCode()` mutator on save + same normalization on the search input; existing row repaired
- Category chips inside the brand screen: `GET /api/brands/{brand}/categories` (purchasable counts per category); horizontal chip bar with "All (N)" + per-category counts, red active state, resets pagination on switch

> **Reverted feature (2026-07-12):** the full Brand Color library (per-brand shade-card table, library-authoritative hex with cascade, inline create from product form, BrandColorResource) was built, verified, and reverted the same day — owner judged it too complicated for now. The simple fields above replaced it. If revisited: the design and verified implementation details are in this session's history; checkpoint `a552ec8` was the revert target. Migration paths: the plain fields backfill trivially into a library table (group by brand + code).

---

### 14. Mobile AR — Live "Paint the Wall" Filter, then reverted to Viro (2026-07-25)

**Goal:** let a customer point their phone at a wall and preview a product's paint color on it. Two approaches exist in the mobile app; this session built the first, hit device limits, and reverted to the second per owner's call.

**Approach A — Live CV "fake AR" filter (BUILT, works, but choppy).** A Snapchat-style filter, NOT true AR — no ARCore needed, runs on any camera phone.
- Pipeline (all on-device, `app/ar/live-filter.jsx`): VisionCamera Skia frame processor → `vision-camera-resize-plugin` downscales each frame to the model input → `react-native-fast-tflite` runs a wall-segmentation model → Skia composites the recolor with `BlendMode.Color` (paint hue/sat + wall's own luminance ⇒ shadows/texture preserved) + a blur for soft edges.
- **Model:** `assets/models/wall_seg.tflite` (~3 MB, ADE20K-derived DeepLab, 3 classes bg/wall/ceiling, from GitHub `bilalsaif7576/Wall-Ceiling-Segmentation-Deeplabv3-Model-`). **Non-commercial license — fine for the capstone WITH attribution, must swap for a permissive/own model before any commercial ship.** Input & output BOTH **uint8** `[1,224,224,3]` (fully quantized) — feed raw 0..255 (no /255 normalization); argmax over 3, wall = index 1.
- Features shipped: live recolor from the product `hex_code`, color swatch row, and a **strictness slider** (confidence threshold on the wall class; "High"=195 default) that trades false-positives (big flat white furniture reads as wall) against wall coverage. Quality is good on textured/colored walls; imperfect on plain white walls and can catch large flat furniture — the accuracy ceiling of a 3 MB real-time model.
- **Native build gotchas (all "pin the stable major, not the bleeding-edge one `expo install` grabs"):** `react-native-vision-camera` **v5 breaks** Expo config-plugin loading → pin **v4.7.3**. `react-native-fast-tflite` **v3 (Nitro) breaks** asset loading in dev (`createModel: Value is undefined`) → pin **v1.6.1** (remove `react-native-nitro-modules`). `@shopify/react-native-skia` frame processors need **Android minSdk 26** (HardwareBuffers) → `expo-build-properties` plugin sets it. Load the model via **expo-asset** (`Asset.fromModule(require).downloadAsync()` → `loadTensorflowModel({url: localUri})`); the bare `require()` path resolves to a broken `localhost` dev URL. `.tflite` added to `metro.config.js` `assetExts`. The harmless `<Canvas onLayout>` LogBox warning is silenced via `LogBox.ignoreLogs`.
- **Unsolved blocker = choppy ~1 fps** (a full CNN runs on every frame). Every smoothness lever failed on the test device: **NNAPI** delegate → fails to build interpreter; **GPU** (`android-gpu`, `enableAndroidGpuLibraries`) → also fails; **background threading** via VisionCamera `runAsync` → `"Regular javascript function cannot be shared"`, a **worklets-core vs reanimated Babel-plugin conflict** that `processNestedWorklets:true` did NOT fix. So the live filter is stuck at synchronous-CPU choppiness on this hardware. Owner disliked the imprecision/choppiness vs. a reference photo-based visualizer (which is precise because it segments a *frozen* frame once — declined since it breaks the live-AR illusion).

**Approach B — Real ARCore AR via ViroReact (REVERTED TO, but hardware-blocked).**
- `app/ar/preview.jsx` (fully built): `ViroARSceneNavigator` + `ViroARPlaneSelector` vertical planes → tap a wall → fill with a color material; opacity control + color modal. Uses `@reactvision/react-viro` 2.56.0.
- **Current wiring:** product page AR button (`app/product/[id].jsx`) now points to **`/ar/preview`** (Viro). The Approach-A screen (`live-filter.jsx`) is kept **unlinked as a fallback**.
- **Blocked on hardware:** true AR on Android REQUIRES ARCore ⇒ an **ARCore-certified device**. Test device (Redmi Note 13 Pro+ / `2311DRK48G`, Android 15) has ARCore *services* installed (v1.55) but that ≠ certified, and Viro crashed on it. **Paused until an ARCore-certified phone is available.**
- **When resuming:** the likely remaining blocker is **Viro + New Architecture** (`newArchEnabled=true`; Viro's Fabric support is fragile — `preview.jsx` already carries Fabric workaround comments), NOT ARCore. Plan: on a certified device, run app → tap AR button → if it crashes, pull the logcat trace and diagnose the Viro/New-Arch failure.
- **AR platform reality (for the writeup/defense):** real 3D AR = ARCore (Android) / ARKit (iPhone+Mac) / paid SLAM (8th Wall). All free Android AR (Viro/Unity/WebXR) wrap ARCore ⇒ same device gate. The only device-agnostic option is the CV segmentation filter (Approach A) — which is *simulated* AR.

---

## Filament 4 Gotchas Learned (2026-07-04)

- **`Notification::sendToDatabase()` silently queues** (`DatabaseNotification implements ShouldQueue`; `QUEUE_CONNECTION=database`) — alerts never appear unless a worker runs. Use `$user->notifyNow($notification->toDatabase())` for must-deliver alerts.
- **Table filter deep links use `?filters[...]`** — `ListRecords` binds `#[Url(as: 'filters')]` to `$tableFilters`. `?tableFilters[...]` does nothing. Table search binds to `?search=`.
- **Notification body renders as sanitized HTML, not markdown** — `**bold**` shows literally; use `<strong>` / `<br>`.
- **`Notification->send()`** pushes to `session('filament.notifications')` — works from event listeners across the login redirect.
- **Dev DB is MySQL** (`.env`), not SQLite — `database/database.sqlite` is a stale leftover. Tests still use SQLite `:memory:`. Laragon MySQL 8.4 has binlog ON (ROW/FULL) — before-images of accidental writes are recoverable via `mysqlbinlog --base64-output=decode-rows -v`.
- **Throwaway tinker scripts that mutate real data** must wrap changes in `DB::beginTransaction()`/`rollBack()` (or restore in `finally`) — a mid-script crash otherwise leaves data corrupted.

---

## Gotchas Learned (2026-07-11)

- **Carbon 3 `diffInDays()` returns a float** — against `endOfDay()` timestamps a "31-day" range computes as ~31.99, silently breaking integer threshold comparisons. Compute day counts from bare dates.
- **Chart.js + Livewire + Filament SPA needs the full defense stack**: `wire:ignore` canvas wrappers, run-once `window`-guarded renderer, readiness polling for the CDN, `requestAnimationFrame` before drawing. Any one missing → "sometimes blank" charts. (Memory: `feedback-livewire-chartjs`.)
- **Custom CSS vars must actually be defined** — blade pages referenced `--color-background-secondary` etc. that existed nowhere; borders fell back to `currentColor` (and `rgba(255,255,255,.1)` borders are invisible in light mode). Define tokens per page: `:root` light + `html.dark` overrides.
- **Filament `disabledOn('edit')` on a Repeater field** = create-only input; disabled fields aren't dehydrated in v3+/v4, so existing values survive edits untouched. Used for variant initial stock.
- **Denying the Windows Firewall popup for `php.exe` creates permanent BLOCK rules** (named "CLI", Public profile). Symptom: phone reaches Metro (:8081, node.exe) but times out on the API (:8000, php.exe) — and it "breaks without changing anything" the day Windows re-categorizes the network as Public. Fix (elevated): `Set-NetConnectionProfile -Name '<network>' -NetworkCategory Private` or flip the php.exe rules to Allow.
- **Mobile home screen retries failed fetches in a tight loop** and the search fires per keystroke — needs backoff + debounce (pending).

---

## Key Technical Notes

- **Stock/price/thresholds live on `ProductVariant`** — `InventoryLog::record($variant, …)` is the only sanctioned way to move stock; see CLAUDE.md
- **Products use `is_archived`** not hard delete (variants too) — catalog served via `Product::purchasable()`
- **Filament 4.0 dark mode** is the `.dark` class on `<html>`, using `--gray-*` CSS variables from Tailwind 4
- **Chart.js 4.4.1** loaded from CDN in reports.blade.php (no npm package)
- **Dev DB is MySQL, tests are SQLite `:memory:`** — always write raw SQL with both dialects in mind (`DB::getDriverName()` branch)
- **Mobile app rebranded to NCM red (2026-07-12):** all 15 screens swapped `#f97316`→`#b91c1c` and `#fff7ed`→`#fef2f2`; no shared color constant yet — colors are inline per screen (a `constants/colors.js` would be a nice refactor)
- **Mobile API URL centralized (2026-07-13):** `constants/api.js` is the single source (`HOST` line) — all 8 fetching files import from it; SETUP.md updated. Search is debounced.
- **Pending / next session:**
  - **OWNER ACTION: commit + push both repos** (brand catalog, category chips, color search + dash fix, gallery, red mobile theme, constants/api.js, SETUP.md, docs — all uncommitted)
  - **OWNER ACTION: obtain an ARCore-CERTIFIED device for the real-AR (Viro) path** (see §14). Test device Redmi Note 13 Pro+ (`2311DRK48G`) is NOT confirmed certified and Viro crashed on it. Check candidates against Google's supported list (developers.google.com/ar/devices). Fallbacks if no certified device: ship the CV live-filter (Approach A, choppy) or iPhone/ARKit via borrowed Mac. On a certified device, next debug target is Viro + New Architecture, not ARCore.
  - Hosting plan chosen: **Laravel Cloud** (30 days free + $5 credit; time it ~2 wks before defense). Prep needed from me: Postgres branch for Reports date SQL (`to_char`), storage → bucket config, env checklist. Vercel ruled out (serverless PHP: no disk/MySQL/queue)
  - Web welcome page still orange; mobile `constants/colors.js` extraction; order-items relation manager in OrderResource
