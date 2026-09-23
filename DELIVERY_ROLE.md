# Delivery Driver Role — design note

> **Status (2026-09-23): PHASES 1, 2 AND 3 BUILT.** Driver role, delivery assignment,
> the /driver panel, admin Assign Driver, driver accounts and invites are in
> and covered by 21 tests (`tests/Feature/DeliveryDriverTest.php`); the full
> suite is 326 green. **Phase 2 is in too**: the customer's app names the driver
> with a call button once the order is out, explains a missed delivery that the
> tracker cannot show (it is not a status), and dates the Out for Delivery and
> Delivered steps from the real `picked_up_at` / `delivered_at` rather than
> leaving them blank.
>
> **Phase 3 is in, with one decision reversed.** This note argues below that
> driver screens must NOT be bundled into the customer app. They were anyway,
> on 2026-09-23, deliberately: a second Expo project means a second build
> pipeline, EAS config and store listing for a capstone that already carries an
> AR feature — and, decisively, a separate project could not be verified here
> without installing its dependencies, so it would have been handed over
> unproven. Inside `paintcenter-mobile` the driver screens compile through the
> same Metro bundle as everything else. The cost stands and is not pretended
> away: the customer build contains `app/driver/*`, which no customer can reach
> (every endpoint behind it 403s) but every customer downloads. If the app ever
> ships to a real store, splitting it is the first thing to revisit.
>
> The API under `/api/driver` is shape-independent — it would serve a separate
> app unchanged — so that reversal costs nothing on the server.
>
> Decisions taken during the build, differing from the plan below:
> - **COD is a hard block, by choice.** A driver cannot mark a COD order
>   delivered without confirming collection. The escape hatch is Couldn't
>   Deliver → "Customer could not pay", so no cash means no handover and the
>   order is never stuck. See *COD* below.
> - `StatusChange::succeeded()` rather than `moved()` — the result object
>   already has a static `moved()` constructor.
>
>
> Raised at the capstone panel: the admin is flooded because every delivery
> order's whole lifecycle lands on one desk. The fix is a **driver role** that
> owns the delivery leg, inside the existing Laravel app — **not** a second
> mobile app. Deliveries are handled by the store's own staff; there is no
> third-party courier and no external dispatch API in scope.
>
> Companion to `SMS_INTEGRATION.md` and `CLOUD_STORAGE.md`.

---

## The decision: a second Filament panel, not a second app

```
                    PAINT CENTER
                         |
                 Laravel Application
                         |
          +--------------+--------------+
          |                             |
      /admin                        /driver
          |                             |
  Admin / Super Admin              Driver
          |                             |
  Manage everything          Only assigned deliveries
          |                             |
          +--------------+--------------+
                         |
                    Same database
                         |
                      orders
```

One backend, one database, one auth system, one order table — with two panels
that differ in what they can see and do.

**Why not a third Expo app (yet):**

- A driver's job is four taps: see my deliveries → open one → Picked Up →
  Delivered. That is a list and two buttons. Filament already renders on a
  phone, and a second panel is config, not a codebase.
- The customer app ships publicly. Bundling staff screens into it means every
  customer downloads them, and its auth path hardcodes `role => 'customer'`
  (`AuthController::register`) — it is customer-shaped by construction.
- A separate Expo app is a second build pipeline, EAS config, store listing and
  QA cycle. That is where capstone time goes to die.

**What we give up:** push notifications and background GPS. Neither is needed —
the panel's notification bell already polls every 30s, and `SmsService` exists
for an assignment ping if it is ever wanted.

**The framing for the panel:** the flood is a *routing* problem, not a platform
problem. Deliveries stop landing on the admin's desk because the driver role
owns the last two transitions. That fix is identical whether the driver's screen
is a web page or an app — which is exactly why the app can wait.

`/driver` is a **separate panel**, not a page inside `/admin`. A driver is not a
weaker admin. Sharing the admin panel would mean an accumulating pile of "hide
this button / page / field for drivers", and the driver panel slowly becomes an
awkward copy of the admin panel.

---

## Roles

| Role | Responsibility |
|---|---|
| `super_admin` | Full system control |
| `admin` | Orders, products, customers, reports, messages |
| `driver` | Deliver assigned **delivery** orders. Nothing else |
| `customer` | Shop and track their own orders |

Drivers do **not** touch pickup orders. A pickup is handed over at the counter
by whoever is at the counter; adding a second actor to that flow buys nothing.
The driver panel filters on `order_type = 'delivery'`.

---

## Schema

**`users.role`** gains `'driver'` — an enum `->change()` migration, same shape as
`2026_06_30_000001_add_super_admin_role_to_users_table.php`.

**`orders`** gains the current assignment and the delivery facts:

| Column | Notes |
|---|---|
| `driver_id` | nullable FK → `users`. Who **currently** owns the delivery |
| `assigned_at`, `assigned_by` | mirrors the existing `cancelled_at` / `cancelled_by` pair |
| `picked_up_at` | set when the driver takes the goods |
| `delivered_at` | set on successful handover |
| `delivery_note` | free text — "left with guard", "nobody home" |
| `failed_attempts` | unsigned tinyint, default 0 |
| `cash_collected_at` | COD only; when the driver confirmed collection |

**No `deliveries` table.** `orders` already *is* the thing being delivered.
The split is:

- **`orders.driver_id` = who owns it now**
- **`activity_logs` = what happened historically**

Reassignment therefore needs no history table. `Order` already uses
`TracksActivity`, so a `driver_id` change is auto-diffed into the audit trail
(`driver_id: {old: 3, new: 7}`) with no extra work. Two caveats, both dealt with
in the build:

1. `ActivityLog::log()` returned `null` for anyone who was not an admin, so a
   **driver's own actions were logged nowhere** — backwards, since those are
   the ones most worth attributing. The gate is now `User::isStaff()`.
2. The auto-diff stores raw ids. `DeliveryService::assign()` therefore also
   writes a named entry ("Reassigned from X to Y"), or the activity feed reads
   as `driver_id: 3 -> 7` exactly when someone is working out who had the order.

**No new order status.** See *Failed delivery* below.

---

## The flow, and who owns each step

```
              ADMIN
                |
                v
          Order received  ------------->  PENDING
                |
                v
          Picks and packs ------------->  PROCESSING
                |
        Admin assigns a driver   (no status change)
                |
                v
        +------------------+
        |   DRIVER PANEL   |
        +------------------+
                |
           Picked Up      ------------->  SHIPPED
                |
           Delivered      ------------->  COMPLETED
```

| Step | Who | Status |
|---|---|---|
| Pick and pack | admin | `pending` → `processing` |
| Assign a driver | admin | — |
| "I have the order" | **driver** | `processing` → `shipped` |
| "Delivered" | **driver** | `shipped` → `completed` |

The driver does not control the order lifecycle — only the delivery leg.

**The admin keeps the existing Advance / Move Back buttons as an override.** A
driver's phone dies, a driver quits mid-round, someone records it late: the
store needs a recovery path that does not depend on the driver's device.

---

## COD: cash confirmation is mandatory

`payments.payment_method` allows `cod`, and `OrderController::store` writes
`payment_status => 'pending'` — **nothing in the system ever marks it paid.** A
driver who hands over ₱4,200 of paint and collects cash leaves no record at all.
That is a real gap in the current payment flow, found while planning this.

**Rule: a COD order cannot be marked Delivered without confirming collection.**

```
Payment method: COD
Amount to collect: P4,200

[x] Cash collected

[ Mark as Delivered ]
```

Confirming sets `payment_status = 'paid'`, `payment_date = now()`, and
`cash_collected_at` — the last one so reconciliation can tell *which driver*
took *which* money, which a bare `payment_status` flip cannot.

For non-COD (gcash, card), no cash step:

```
Payment method: GCash

[ Mark as Delivered ]
```

**The no-cash case is not a stuck order — it is a failed delivery.** COD means
cash on delivery: no cash, no handover. A driver facing a customer who cannot
pay uses **Couldn't Deliver → "Customer could not pay"**, the goods come back,
`failed_attempts` increments, and the order stays `shipped` for a retry. So the
mandatory checkbox never traps anybody, and no driver is ever put in a position
where ticking a box they know is false is the only way to close their day.

---

## Failed delivery is NOT a new status

Adding `failed_delivery` to the status list would ripple into customer tracking,
SMS, `Order::STATUS_FLOW_BY_TYPE`, the mobile app's mirrored `FLOW` in
`constants/orders.js`, `OrderObserver`, reports and order filtering — for a
condition that is not a new *place in the journey*, just a fact about the trip.

```
SHIPPED
   |
   +-- Couldn't Deliver
   |        +-- reason required  ->  delivery_note
   |        +-- failed_attempts += 1
   |
   +-- still SHIPPED — retry
```

The order stays `shipped`, keeps `picked_up_at`, and records the attempt.

**`picked_up_at` is never cleared.** The goods did leave the store; that is a
fact that happened, and erasing it means losing track of how long stock has been
off the shelf. A genuine return-to-store is the admin reverting the status by
hand — not a fourth button on the driver's screen.

**Attempts are capped at 3.** On the third failure the driver's Couldn't Deliver
button is withdrawn and the **admin's** bell rings, because the decision is now
the store's. Without a ceiling an undeliverable order sits `shipped` forever and
falls off every work list.

The admin then has two real routes, and both needed a rule changing to work:

- **Cancel.** `OrderCancellationService::CANCELLABLE_STATUSES` is
  `pending, processing` — past that "the goods have moved and cancelling is a
  manual matter", which is right in general and wrong here. An exhausted
  delivery is precisely the case where the store DOES know where the goods are:
  the driver tried three times and was told to bring them back. `canCancel()`
  therefore allows `shipped` **when, and only when, attempts are exhausted**.
  `canCustomerCancel()` is deliberately NOT widened — the customer cannot see
  the counter and the goods are not with them.
- **Reassign.** A reassignment at `shipped` returns the order to `processing`,
  clears `picked_up_at` and resets `failed_attempts` to 0 — because the previous
  driver has already been told to return the items. Without that reset the new
  driver inherits a spent counter (no Couldn't Deliver button at all) and a
  pickup they never made, landing the order in their Out for Delivery tab
  offering only "Delivered" for goods sitting on a shelf at the shop.
  `delivery_note` is kept: "Nobody home" is the most useful thing the next
  driver can know before setting off.

---

## Business logic lives in services, not in Filament

This is what keeps a driver app cheap later.

```
  Driver Web (Filament)  --+
  Admin Web (Filament)   --+-->  DeliveryService  -->  OrderStatusService
  Driver Mobile (later)  --+           |                     |
       via API Controller              |                     +-- the only place
                                       |                         a status is
                                       |                         ever written
                                       +-- assignment, pickup, delivery,
                                           failure, COD collection
```

- **`OrderStatusService::advance() / revert()`** — extracted from
  `OrderResource`, where the logic currently sits inline
  (`advanceStatusAction`, around line 440), including the re-read-before-write
  guard that exists because the orders table polls every 30s and two admins can
  hold the same row. A driver is now a **third** concurrent actor on that row,
  so that guard must be shared, not copied.
- **`DeliveryService`** — assign, reassign, pickup, delivered, failed attempt,
  COD collection. It calls `OrderStatusService` for anything that moves a
  status; it never writes `status` itself.

Same precedent as `OrderCancellationService`, which exists so the mobile cancel
and the admin cancel cannot drift apart.

**Deliberately not a `DriverAssigned` event.** Notification, activity log and
(optional) SMS go inside `DeliveryService::assign()`, not into listeners behind
an event. There is exactly one publisher — the Assign action — so an event buys
decoupling nothing needs, and costs five files to answer "does assigning notify
the driver?". There is also a concrete hazard: this codebase already wrote down
(in `AdminOrderAlertService`) why the new-order bell is *not* an observer —
model events fire inside the transaction, and a rollback has admins chasing an
order that never existed. An event dispatched inside `DB::transaction()` is the
same trap wearing a different hat.

Promote it to an event when a **second** publisher appears — bulk assign,
auto-assign by zone, or a driver self-claiming from a pool. Not before.

---

## Authorization — the sites that assume `role` is admin, super_admin or customer

These are the failures that do not throw. They quietly do nothing, like a
driver's password-reset email that is never sent. Every one is a real line in
the current code and every one needs a test.

| File | Current behaviour | Needed |
|---|---|---|
| `User::canAccessPanel()` | returns `isAdmin() \|\| isSuperAdmin()` | branch on `$panel->getId()` — driver gets `/driver`, admin gets `/admin`, neither gets the other's |
| `User::sendPasswordResetNotification()` | silently returns for non-admins | extend to drivers, or a driver's reset posts nothing with no error |
| `ActivityLog::log()` | returns `null` for non-admins | extend to drivers — otherwise no driver action is ever audited |
| `Message::isVisibleTo()` | `isAdmin()` is false for a driver — correct | **no change** — but assert it. Drivers must never read customer threads |
| `User::admins()` scope | explicit `whereIn('admin','super_admin')` — correct | **no change** — drivers correctly excluded from stock alerts, the new-order bell, and `activeAdmin()`. Lock it with a test |
| `Messages.php` (x2), `ProductVariantObserver.php` | same hardcoded `whereIn` | no change; listed so the next person knows they exist |

**Driver scoping:** a driver sees only orders where `driver_id = auth()->id()`.
A refusal answers **404, not 403** — the same rule already written down for
`MessageAttachmentController`: order ids are a plain auto-increment, so a 403 on
a row that exists is a difference anyone can measure by counting.

**Do not reuse `OrderResource` in the driver panel.** A purpose-built
`DeliveryResource` under `app/Filament/Driver/`. A driver needs customer name,
phone, address, items, total and payment method — not the cancel action, not the
edit form, not revenue. Do not expose the customer's email.

Mirror `->spa()`, `->databaseNotifications()` and
`->databaseNotificationsPolling('30s')` on the driver panel. Do **not** give it
global search over admin resources.

---

## Driver panel — three screens, and no more

**My Deliveries**

```
MY DELIVERIES

TO PICK UP
--------------------------
Order . Sep 23, 2:14 PM
Juan Dela Cruz
123 Rizal St, Brgy. San Jose
COD . P4,200                    [ View ]

OUT FOR DELIVERY
--------------------------
Order . Sep 23, 9:02 AM
Maria Santos
...                             [ View ]
```

**Delivery detail**

```
Order . Sep 23, 2:14 PM

Customer      Juan Dela Cruz
              0917 123 4567          <- tel: link

Address       123 Rizal St, Brgy. San Jose

Items         2 x BOYSEN Latex White 4L
              1 x BOYSEN Latex B-1408 1L

Total         P4,200
Payment       COD

[ PICKED UP ]
```

after pickup:

```
[ DELIVERED ]        [ COULDN'T DELIVER ]
```

**History** — their own completed and failed deliveries, so they can answer for
their own day. Scoped to `driver_id = auth()->id()`, like everything else.

**Order identity:** orders are referred to by **placed-at date and time, never
by id** — a house rule from the customer surfaces, kept here so the driver and
the customer are talking about the same thing when one phones the other.

---

## Admin side

An assignment panel on the order page:

```
Driver
------------------
Not assigned

[ Assign Driver ]
```

after assigning:

```
Driver
------------------
Mark Villanueva
Assigned Sep 23, 2026 2:31 PM

[ Reassign ]
```

- Only **active, non-archived** drivers appear in the picker.
- Show each driver's current active-delivery count in the option label
  (`Mark Villanueva — 3 active`). It is a `count()`, not a state machine, and it
  is most of the value of a real availability system for none of the cost.
- Assigning notifies the driver's bell (`notifyNow`, **never**
  `sendToDatabase` — the latter queues silently and needs a worker nobody is
  running; already documented in CLAUDE.md).
- **Reassigning must also notify the previous driver.** The My Deliveries list
  is scoped on `driver_id`, so a reassigned order simply *vanishes* from their
  screen — while they may still have the cans in the van.

Plus a `DriverResource` under Administration (super admin only), mirroring
`UserResource`'s three-state status (Active / Pending invite / Inactive).

---

## Onboarding drivers

Reuse the existing invitation infrastructure — it is keyed by `user_id` and is
role-agnostic. Two changes:

1. **Rename `AdminInviteService` → `StaffInviteService`.** It is 6 files and the
   invitation is no longer admin-only. **Leave the `admin_invites` table, the
   `AdminInvite` model and `User::adminInvite()` alone** — renaming those is
   ~50 references across 10 files including a migration and a test suite, for
   zero functional change. The name is not what is broken.
2. **Fix the post-accept redirect.** `AcceptInvite` ends on
   `redirect()->intended(Filament::getUrl())`, which with two panels resolves to
   whichever panel registered the route. A driver who has just chosen their
   password would be dropped at `/admin` and bounced straight back out. Make the
   redirect role-aware.

**Remember the rule already learned the hard way:** any page behind a panel's
`routes()` must **also** be listed in `->livewireComponents([...])`, or the
first GET renders and the form submit dies with *"Unable to find component"*.
A `get()` test and `Livewire::test()` both miss this — resolve through
`ComponentRegistry::getClass()` instead.

Note that `MAIL_MAILER` still does not reach a real inbox, so the honest
three-outcome invite reporting (**sent / failed / notMailed**, printing the link
on the last two) applies to driver invites exactly as it does to admin ones.

---

## Deliberately NOT building

| Not building | Why |
|---|---|
| A `deliveries` table | `orders` is already the thing being delivered; `activity_logs` already carries the history |
| A `failed_delivery` status | ripples into the mobile app, SMS, observers, tracker and reports for no gain |
| Driver availability (`available` / `busy` / `off_duty`) | not needed to assign an order. An active driver can be assigned. Do not build logistics software before there is logistics |
| A driver pool / self-claim | admin assigns, one driver per order. A pool is a different table and a race condition |
| A `DriverAssigned` event | one publisher; see *Business logic* above |
| A driver mobile app | Phase 3, only if actually needed |
| Driver GPS / live tracking | out of scope, and nothing in the customer app is asking for it |

---

## Phases

**Phase 1 — the role works**

```
Database          driver role . assignment fields . delivery timestamps
                  . attempt tracking . cash_collected_at
     |
     v
Business logic    OrderStatusService (extracted) . DeliveryService
                  . the authorization checklist, with tests
     |
     v
Filament          /admin   -> Assign Driver, DriverResource, driver invites
                  /driver  -> My Deliveries, Delivery Detail, History
```

**Phase 2 — the customer sees it**

`GET /api/orders/{id}` returns `driver: { name, phone }` once `shipped`; the
mobile order page shows "Mark is delivering your order" with a call button, and
words a failed attempt. Purely additive to the API — no breaking change for the
shipped app. This measurably reduces the "where is my order" messages that are
the *other* half of the admin's flood.

**Phase 3 — BUILT (2026-09-23)**

`/api/driver`, behind `auth:sanctum` + an `EnsureUserIsDriver` gate (403 for the
namespace; an individual delivery that is not yours still 404s, from the scoped
query). Seven endpoints, every one a thin wrapper over `DeliveryService`:
`GET deliveries`, `GET deliveries/summary`, `GET deliveries/{order}`,
`GET failure-reasons`, and `POST .../pick-up | deliver | fail`. Responses are
SHAPED, not model dumps — a driver gets the customer's name, number, address
and the items, never their email, and never which admin assigned the run.

In the app: `app/driver/` (list with the same three tabs as the panel, and a
detail screen whose buttons are driven by the server's `can_pick_up` /
`can_deliver` / `can_report_fail` rather than re-deriving the rule). Login and
cold start route by role through `lib/homeRoute.js`.

Two things worth keeping in mind:
- The failure-reason list is FETCHED from the server, so the app and the panel
  cannot drift into different words for the same thing — unlike
  `STATUS_FLOW_BY_TYPE`, which is mirrored by hand.
- `Alert.prompt` is **iOS-only**. The free-text failure reason is an inline
  `TextInput`; an `Alert.prompt` there would silently do nothing on Android and
  leave a driver unable to report anything the presets do not cover.

**Was: only if needed**

```
Laravel API
     +-- Admin Web
     +-- Driver Web
     +-- Customer Mobile
     +-- Driver Mobile App   -->  same DeliveryService / OrderStatusService
```

Phase 1's service extraction is what makes Phase 3 additive rather than a
rewrite.

---

## Phase 4 — Proof of Delivery, and Driver Navigation

> **Status: PLANNED (2026-09-23). No code written yet.**
> Decisions taken: the photo is **always required**; the map is **driver
> navigation only**.

### 4a. Proof-of-delivery photo

**The pattern to copy is `MessageAttachmentService`, not `products.images`.**
That is not a style preference, it is the difference between a feature that
works in production and one that does not. `CLOUD_STORAGE.md` records that on
Laravel Cloud `FILESYSTEM_DISK=public` accepts uploads but **never serves the
images** — which is why product photos are broken there today. Message
attachments are unaffected because they are streamed by a CONTROLLER
(`MessageAttachmentController`) rather than linked from a public storage URL.
A proof photo served the product-images way would be invisible on Cloud from
day one.

**Schema** — columns on `orders`, not a new table. One photo, taken once, at
handover; the same reasoning that kept the driver assignment on `orders`:

| Column | Notes |
|---|---|
| `proof_disk` | stored PER ROW, so an eventual move to object storage leaves old photos resolvable |
| `proof_path` | ULID path, needs no order id |
| `proof_mime`, `proof_size` | what was accepted, for serving and for audit |
| `proof_captured_at` | when the driver took it |

Reassignment never collides with this: a proof only exists once an order is
`completed`, and a completed order is not assignable.

**Rules, inherited wholesale from the attachment work:**
- `jpg / jpeg / png / webp`, max 8 MB (`MessageAttachmentService::MAX_FILE_KB`).
- **No HEIC.** This server has gd and no imagick so it cannot be measured, and
  Chrome cannot render it — an admin would get a broken image icon. The app
  re-encodes every capture to JPEG through `expo-image-manipulator`, as it
  already does for message photos, so it cannot arrive.
- **The file is written BEFORE the transaction.** A file with no row is
  invisible junk swept up later; a row with no file is a permanently broken
  proof on a completed order with nothing to re-upload from. A catch block
  deletes on a failed transaction; a `deliveries:prune-orphan-proofs` command
  is the actual guarantee, because a fatal runs no catch block.

**The proof must be attached BEFORE the status moves, not merely before the
transaction.** `deliver()` calls `OrderStatusService::advance()`, which fires
`OrderObserver` — the customer's message and the SMS dispatch — and that call
deliberately sits OUTSIDE any transaction because every queue connection is
`after_commit => false`. Write the file, then advance, and a later failure
leaves exactly the state this feature exists to prevent: an order marked
completed, a customer already texted, and no proof. So:

```
guards (ownership, status, COD cash)
      |
write file
      |
update order -> proof_* columns      <- still `shipped`, harmless intermediate
      |
OrderStatusService::advance()         <- completed, proof already attached
      |
transaction -> delivered_at + payment
```

The worst case becomes a `shipped` order carrying an unused proof: invisible,
recoverable, and nothing false has been said to anyone. An order can never be
`completed` without proof metadata, which is the invariant the rule asks for.

**Retention: 12 months** (decided 2026-09-23). A proof photo is somebody's front
door, sometimes their face, and one accumulates per delivery forever; the
serving gate settles WHO may look and says nothing about HOW LONG. Twelve months
outlasts any realistic dispute about a delivery that happened without keeping
photographs of customers' homes indefinitely. A scheduled
`deliveries:prune-delivery-proofs` deletes the file and clears
`proof_disk/path/mime/size` — but **keeps `proof_captured_at`**, so an old order
still records that a photo was taken and when, rather than reading as a delivery
that never had one.

**Capture** is CAMERA ONLY (`launchCameraAsync`), not the gallery. A photo
chosen from a camera roll is not evidence of anything.

**Where the requirement lives.** `DeliveryService::deliver()` refuses without a
stored proof, so both the driver app and the `/driver` web panel are bound by
it — one rule, two clients, exactly as with COD cash.

**The escape hatch is the admin override, and that is deliberate.** A required
photo means a driver with a dead camera cannot close their round. Rather than
weaken the rule with a "skip" the drivers would learn to press, the existing
admin **Advance** action stays outside `DeliveryService` — a store that can
vouch for a delivery nobody photographed can still complete the order, and
`ActivityLog` records WHICH admin did so. Evidence is required of the driver;
the store can take responsibility instead. Same shape as COD resolving through
Couldn't Deliver.

**Serving** — a single-action `DeliveryProofController`, registered on both an
API route and the admin panel route (like `MessageAttachmentController`), same
gate both ways: the customer who owns the order, any admin, or the driver who
captured it. **404, not 403**, on refusal.

Shown on: the customer's order detail once completed, the admin's order page,
and the driver's own history.

### 4b. Driver Navigation

**The blocker is the data, not the library.** There are no coordinates anywhere
in this schema — no `latitude`/`longitude` in any migration or model — and the
addresses customers actually enter look like this:

```
Pilar, Bataan
```

That is a municipality. Geocoding it returns the town centroid, potentially
kilometres from the house, so a pin drawn from it would be **confidently
wrong** — worse than no pin, because a driver would trust it.

So Phase 4 does the honest version: a **Navigate** button on the driver's
delivery screen (app and web panel) that hands the address string to the phone's
own map app via
`https://www.google.com/maps/search/?api=1&query=<urlencoded address>`. No
library, no API key, no coordinates, works on both platforms, and degrades to
the browser. It is exactly as accurate as the address is — which is the point:
it does not manufacture precision the data does not have.

**NOT building now**, and why:
- **In-app map with a pin** (`react-native-maps` + a Google Maps Android key) is
  pointless until addresses carry coordinates. The real prerequisite is a
  **location picker at checkout** so customers drop their exact spot — that is
  its own feature, and the one worth doing if a map is wanted for real.
- **Live driver tracking** stays where it already is in this document: under
  *Deliberately NOT building*.

**Improving the address matters more than the map**, and the fix is TWO fields,
not a structured seven.

Structure buys no navigation accuracy on its own — a map search takes one
string, and concatenating six fields produces the same query as one well-written
line. A customer who types "Pilar" into a free-text box types "Pilar" into a
Street field too. There is also a migration problem: `users.address` is already
free text and checkout pre-fills from it, and "Pilar, Bataan" cannot be parsed
into seven fields, so two representations would coexist indefinitely.

And for this store the usual field list is inverted: the postal code for Pilar
is 2101 and tells a driver nothing, while *near ABC store, green gate* is how a
provincial PH delivery is actually found.

```
Delivery address
[ 123 Mabini St., Brgy. Poblacion, Pilar, Bataan ]   <- placeholder shows the shape

Landmark / directions  (optional)
[ Near ABC Store, green gate ]
```

The landmark is genuinely different data — instructions for a human, not part of
an address — so it gets its own column, is shown to the driver, and is NEVER fed
to the map query. Most of the benefit, a fraction of the checkout friction, and
consistent with the existing instinct against blocking checkout (`address` is
nullable at registration precisely so pickup-only customers are not stopped).

### Roadmap after this

```
4a  Proof of Delivery        required camera photo
4b  Driver Navigation        open the address in the phone's map app
--  Precise Delivery Location (future, only if a real map is wanted)
      structured-enough address -> customer location picker
      -> latitude / longitude -> in-app map
```

The picker is a PREREQUISITE for a trustworthy pin, not an enhancement of one.

---

## Phase 5 — Precise Delivery Location

> **Status: BUILT (2026-09-23). Verified on a device** — the pin captures the
> exact location and the driver's Navigate lands on it.
> Prompted by a real failure: a genuine street address was typed at checkout
> and Navigate still dropped the driver in a different part of the barangay.
> That is the predicted outcome of 4b — a map search is only as good as the
> string, and "Brgy. Poblacion, Pilar" is a polygon, not a doorstep.
>
> Decisions: **GPS first, a map picker later**; a poor fix **warns but saves**;
> the permission is **optional and never nagged**.

### The insight that makes this cheap

The driver side needs **no map library at all**. Once an order carries
coordinates, the Navigate link changes target and nothing else does:

```
today       maps/search/?api=1&query=Pilar,+Bataan       -> town centre
with a pin  maps/search/?api=1&query=14.6742,120.5681    -> the doorstep
```

Google Maps still does the routing. The whole feature is therefore about
getting coordinates ONTO the order; `mapUrlFor()` gains one branch and a
fallback for every order placed before this existed.

### Schema

On `orders`, mirrored on `users` so a pin is remembered the way the address now
is (see the persistence note in the address work — without that, a customer
re-pins every single order and stops bothering):

| Column | Notes |
|---|---|
| `delivery_lat`, `delivery_lng` | `decimal(10,7)` — 7 dp is ~1 cm, far beyond GPS |
| `location_accuracy` | metres, as reported by the fix |
| `location_pinned_at` | when it was captured |

### The capture, and why GPS before a map

`expo-location` only: one button, labelled **"Use my current location"** — what
it does, not what it is for. No API key, no Google Cloud project, no billing, no
new native module beyond the Expo one. `reverseGeocodeAsync` confirms the fix in
words and needs no key on Android.

**No accuracy is promised.** It varies by device, by weather and by whether
somebody is standing under a concrete roof, so the app stores the accuracy the
fix REPORTS and warns when it is poor, rather than assuming a figure. Every
surface quotes the stored number.

**Accuracy is not correctness, and that is the harder problem.** A reported ±8 m
says how precisely the PHONE was located. It says nothing about whether the
phone was at the delivery address. A contractor ordering paint for a job site
while sitting in their office gets a high-accuracy pin on completely the wrong
building, and every check in this design passes it.

So the confirmation must ask the right question — not "is this accurate?" but
**"Is this where the order should be delivered?"** — and must show the
reverse-geocoded address, so somebody ordering for a site, for a parent, or for
a client can SEE that it names their own office and say no.

That case is also why the map picker is not a nicety. For a paint store,
ordering to a site other than where you are standing is ordinary, and GPS
cannot serve it at all. The picker is **additive** — schema, API, driver
surfaces and the Navigate branch are identical — so a Leaflet-in-a-WebView
picker (react-native-webview is already a dependency, OSM tiles need no key)
drops in as a second way to set the same two numbers.

### Rules

- **The pin never replaces the text address.** A customer may decline the
  permission; the address is what a human reads on the order and what an admin
  sees; and a wrong pin with no address is unrecoverable. The driver gets
  address + landmark + pin, in that order of prominence.
- **Accuracy is stored and SHOWN.** A GPS fix taken indoors can come back as a
  ±500 m cell-tower estimate, which is barely better than the town centre. A
  poor fix warns — "only accurate to ±480 m, try again outdoors?" — and still
  saves if the customer insists, because refusing would leave someone in a
  concrete building unable to pin at all. The driver sees the figure, so a
  vague pin is never mistaken for a precise one. This is the same rule that
  stopped us geocoding "Pilar, Bataan" in 4b: never present precision the data
  does not have.
- **Staleness is visible.** `location_pinned_at` lets checkout ask "Pinned 3
  months ago — still right?" A pin saved before somebody moved house is worse
  than no pin.
- **Never block checkout, and never nag.** Declining the permission leaves the
  customer exactly where they are today — a text address, which is how every
  order has been placed until now. Consistent with `address` being nullable at
  registration so pickup-only customers are not stopped. Beyond the usual
  reason (a refused permission must not cost an order), somebody ordering for
  SOMEBODY ELSE is right to decline: their location is not the delivery's, and
  the most useful thing the app can do is let them skip it.
- **Privacy.** Coordinates are captured only on a delivery checkout, and read
  only by the assigned driver and admins — the same gate the delivery photo
  uses. They are the customer's own, so they go in the customer's payload.

### Surfaces

- **Checkout** — the button, the confirmation line, the accuracy warning.
- **Driver app and web panel** — Navigate targets the pin; a small "±12 m"
  note beside it; falls back to the address search when there is no pin.
- **Admin order page** — shows whether a pin exists and how good it was, so a
  "driver could not find it" complaint can be checked rather than guessed at.

---

## Tests to write in Phase 1

- A driver cannot reach `/admin`; an admin cannot reach `/driver`.
- A driver opening an order assigned to someone else gets **404**, not 403.
- A driver never appears in `User::admins()` — so no stock alerts, no new-order
  bell, never chosen as `activeAdmin()`.
- A driver cannot read a customer message thread (`Message::isVisibleTo`).
- A driver's password reset is actually sent; a customer's still is not.
- A driver's Delivered action **is** written to `activity_logs`.
- A COD order cannot reach `completed` without `cash_collected_at`.
- A non-COD order can.
- Two actors advancing the same order: the second is told it already moved, not
  pushed a second step along.
- `failed_attempts` stops at 3 and alerts the admin.
- The driver panel never lists a `pickup` order.

---

## Open questions

- **How many drivers does the store actually run?** One changes nothing; five
  makes the active-delivery count in the assign picker worth more than it costs.
- **Should assignment send an SMS**, or is the bell enough? The bell is free and
  the driver is on the panel anyway; SMS costs a send per assignment. Still
  open, and still recommended AGAINST.

### Also built (2026-09-24)

- **Delivery performance** report section (`DeliveryMetrics`, off by default):
  deliveries completed, first-time rate, retries, average time on the road, and
  a per-driver table with COD collected. **Anchored to `delivered_at`, not the
  house `created_at`** — it measures work done in the window rather than orders
  placed in it, so it deliberately does not reconcile to the period's order
  count. That is stated on the report itself.
- The driver's name on the customer's Orders LIST cards, not just the detail.
- **Does a driver ever take payment for a non-COD order** that failed online?
  Assumed no — out of scope, and it would mean a driver touching `payments` for
  a method they were not handed.
