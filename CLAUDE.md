# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (server + queue + logs + vite together)
composer run dev

# Individual services
php artisan serve          # http://localhost:8000
npm run dev                # Vite asset watcher
php artisan queue:listen
php artisan pail           # tail logs

# Build, test, lint
npm run build
composer run test          # or: php artisan test
./vendor/bin/pint

# First-time setup
composer run setup         # installs deps, migrates, seeds
```

## Architecture

**NCM Paint Center** is a Laravel 13 paint retail platform with two interfaces:
- **Admin panel** at `/admin` — Filament 4.0 for inventory, orders, messages, reports
- **REST API** at `/api` — Sanctum-authenticated endpoints for mobile/web clients

**Related project:** The customer-facing mobile app lives at `C:\laragon\www\paintcenter-mobile`. It consumes this project's REST API (`/api`) and is developed separately. When making API changes (routes, response shapes, auth), consider the impact on that client.

**Stack:** PHP 8.3+, Laravel 13, Filament 4.0, MySQL 8.4 (dev DB per `.env`; tests use SQLite `:memory:`), Vite 8, Tailwind CSS 4, Vonage SMS

## Key Patterns

**Paint color identity = plain `color_code` + `color_name` fields on Product** (e.g. "888" / "Red" — the manufacturer's code customers order by); `hex_code` is only a screen preview. All three nullable — uncoded products (thinners, tools) skip them. The mobile product page shows "Color: 888 · Red" with a screen-color disclaimer when a code exists. (A normalized per-brand color library was built and reverted 2026-07-12 as too complex for now — see CONTEXT.md.)

**Product images are a gallery**: `products.images` is an ordered JSON array (Filament multi-upload, drag-to-reorder, max 8); the FIRST entry is the cover. The model appends a computed `image` (= first of gallery) so single-image consumers (cart, lists, inventory thumbnails) work unchanged. Mobile product page renders a swipeable carousel with dots + counter; falls back to the hex swatch when the gallery is empty.

**Stock, price, and thresholds live on `ProductVariant`, not `Product`.** A product ("Anzahl Urethane, red") owns variants ("1L", "4L", "16L"), each independently priced and stocked. `Product` keeps identity only (brand, category, description, hex, images) and appends computed aggregates for API convenience (`size_volume` comma list, `price` = lowest variant price, `stock` = total, `stock_status` = worst case). Cart, order items, and inventory logs reference `product_variant_id`; order items also snapshot `size_volume` like `unit_price`.

**Inventory changes must go through `InventoryLog::record()`** — takes a VARIANT, atomically updates its stock and creates an audit trail with admin accountability:
```php
InventoryLog::record($variant, 'restock', 50, 'Supplier delivery');
// Valid actions: restock | deduct | order_deduct | adjustment
```

**Products use `is_archived` instead of hard delete** (variants too). The API serves the catalog via `Product::purchasable()` — active product with at least one in-stock active variant.

**SMS notifications** via `app/Services/SmsService` use Vonage (`VONAGE_KEY`, `VONAGE_SECRET`, `VONAGE_SMS_FROM`). Deliveries are logged to the `sms_logs` table.

**Stock alerts** are two coordinated pieces (see CONTEXT.md §6 for detail):
- `app/Observers/ProductVariantObserver.php` — on any variant stock update, alerts all admins via the Filament notification bell, but **only on downward severity transitions** (in stock → low → out); never on restock
- `app/Listeners/SendStockAlertsOnLogin.php` — on admin login, shows an instant popup briefing of current stock state and mirrors it in the bell, replacing the previous briefing (identified by `data->title`) so it never stacks
- Both deep-link to `InventoryResource` — its Stock Status filter's `attention` option is the landing target; **do not remove it**
- Use `$user->notifyNow($notification->toDatabase())`, NOT `sendToDatabase()` — the latter silently queues and requires a running worker
- Filament gotchas: table-filter deep links use `?filters[...]` (not `?tableFilters`); notification bodies render as sanitized HTML (use `<strong>`, not markdown)

## Models (`app/Models/`)

| Model | Notes |
|-------|-------|
| `User` | FilamentUser + Sanctum; serves both admin and customer roles |
| `Product` | Identity only (`hex_code`, image, brand/category); `is_archived`; computed aggregates from variants; scopes `lowStock()`, `outOfStock()`, `purchasable()` |
| `ProductVariant` | One size of a product; owns `price`, `stock`, `low_stock_threshold`; unique (product_id, size_volume); `ProductVariantObserver` fires stock alerts; scopes `active()`, `lowStock()`, `outOfStock()` |
| `Brand` / `Category` | Lookup tables; `hasMany` products |
| `Order` | `order_type`: delivery/pickup; status: pending → processing → shipped → ready_for_pickup → completed/cancelled |
| `OrderItem` | Line items; references Product + ProductVariant; snapshots `size_volume` and `unit_price` |
| `CartItem` | Belongs to User + ProductVariant (one line per user+size, so a paint can be carted in several sizes) |
| `Payment` | Belongs to Order |
| `InventoryLog` | Audit trail; use `record()` static helper (takes a variant); belongs to Product + ProductVariant + admin User |
| `Message` | User-to-user; `sender_id`, `receiver_id`, `is_read` |
| `SmsLog` | Tracks sent/failed SMS; belongs to Order |

## Filament Admin (`app/Filament/`)

Resources auto-discovered from `app/Filament/Resources/`:
- **ProductResource** — hex color picker, image upload, brand/category filters; variants Repeater (size/price/threshold; initial stock is create-only — afterwards stock moves through Inventory); Archive/Unarchive action
- **OrderResource** — status workflow
- **BrandResource / CategoryResource** — simple CRUD
- **InventoryResource** — ProductVariant-backed stock management, one row per size (Adjust Stock / View Logs / Set Alert actions); worst stock sorts first; `attention` filter option is the notification deep-link target

Custom pages: `Dashboard`, `Messages`, `Reports`

Widgets: `StatsOverview` (today's sales 7-day chart, pending orders, low/out-of-stock counts), `LowStockWidget`

## REST API (`routes/api.php`)

**Public:** `POST /api/auth/register|login`, `GET /api/products` (filters: search, brand_id, category_id, price range; `search` matches description OR color_code OR color_name, with Unicode dashes normalized), `GET /api/products/{id}`, `GET /api/brands` (catalog brand grid: only brands with purchasable products, `products_count` = purchasable only, includes `image` logo), `GET /api/brands/{brand}/categories` (category chips for a brand's list, purchasable counts), `GET /api/categories`

The mobile catalog is **brand-first**: landing shows a brand grid (logo tiles; lettered fallback), tapping opens `brand/[id]` with that brand's products; the search bar is global and bypasses the grouping.

**Protected (`auth:sanctum`):** cart CRUD (`POST /cart/add` takes `product_variant_id`; update/remove are keyed by `cart_item_id`), order create/list/show/cancel, messages send/read/thread, `POST /api/auth/logout`, `GET /api/auth/me`

Product JSON includes an `active_variants` array (id, size_volume, price, stock, stock_status) — the mobile size picker reads this; checkout locks and deducts stock per variant.

Controllers in `app/Http/Controllers/Api/` (Auth, Product, Order, Cart, Message).

## Testing

PHPUnit 12 with Feature + Unit suites. Uses SQLite in-memory (`:memory:`) configured in `phpunit.xml`.
