# NCM Paint Center — Fresh Device Setup

How to get the system running from a fresh clone. Two repos: this one
(Laravel backend + Filament admin) and `paintcenter-mobile` (Expo app).

## 0. Prerequisites

- PHP 8.3+, Composer, Node.js 20+, MySQL 8
- On Windows, installing **Laragon** provides all of the above
- On the phone: **Expo Go** app (same Wi-Fi network as the PC)

## 1. Clone both repos (as siblings)

```bash
git clone <backend-repo-url> paintcenter
git clone <mobile-repo-url> paintcenter-mobile
```

## 2. Database + .env (the one manual step)

Create the empty database (Laragon → MySQL, HeidiSQL, or):

```bash
mysql -u root -e "CREATE DATABASE paintcenter"
```

`.env.example` already points at MySQL / `paintcenter` / `root` with a blank
password, so if your MySQL matches that you can skip straight to step 3 —
`composer run setup` copies the example for you. Only edit `.env` if your
credentials differ:

```bash
cd paintcenter
copy .env.example .env        # (cp on mac/linux)
```

## 3. One-command setup

```bash
composer run setup
```

Runs: `composer install` → `key:generate` → `migrate` → `db:seed` →
`storage:link` → `npm install` → `npm run build`.

Seeded admin accounts (password: `password`):

- `superadmin@paintcenter.com`
- `admin@paintcenter.com`

## 4. Run it

```bash
composer run dev     # server + queue + logs + vite together
```

Admin panel: <http://localhost:8000/admin>

For phone access, the server must listen on all interfaces (`composer run dev`
uses plain `artisan serve`; if the phone can't connect, run separately):

```bash
php artisan serve --host=0.0.0.0
```

## 5. Mobile app

```bash
cd ../paintcenter-mobile
npm install
npx expo start       # scan the QR with Expo Go
```

**Update the API IP (one line):** open `constants/api.js` and change the
`HOST` line to the new machine's LAN IP (find it with `ipconfig`). Every
screen imports from that file — nothing else to touch.

## Known gotchas (all previously hit — don't rediscover them)

1. **php.exe firewall popup** — click **Allow** when Windows asks. Denying it
   creates permanent BLOCK rules ("CLI") that make the phone time out on the
   API while Metro (:8081) still works. Fix if denied: run elevated
   `Set-NetConnectionProfile -Name '<network>' -NetworkCategory Private`, or
   flip the php.exe firewall rules to Allow.
2. **Network category** — Windows sometimes re-marks the network as Public
   after reboots, re-activating those block rules. Symptom: "worked yesterday,
   changed nothing." Set the network back to Private.
3. **MySQL not started** — Laragon doesn't auto-start by default; start it
   before `composer run setup` or artisan dies with `SQLSTATE[HY000] [2002]`.
4. **Data does not travel** — products/orders live in the database, and
   uploaded product/brand images live in `storage/app/public` (gitignored).
   A fresh device starts with an empty catalog and the two seeded admins.
   To carry real data over: `mysqldump -u root paintcenter > dump.sql` on the
   old machine, `mysql -u root paintcenter < dump.sql` on the new one, and
   copy `storage/app/public/` across by hand.
5. **`storage:link` fails on Windows** — creating the symlink needs elevation.
   Either enable Developer Mode (Settings → System → For developers) or run
   `php artisan storage:link` once from an admin terminal. Without it every
   product image 404s.
6. **No `package-lock.json`** — `npm install` resolves fresh minor versions on
   each machine. If the new device's build behaves differently, that's why;
   commit a lockfile to pin it.

## Docs map

- `CLAUDE.md` — architecture, key patterns, and rules (works as orientation
  for any AI assistant, or any human)
- `CONTEXT.md` — session-by-session changelog with design decisions and
  gotchas learned
