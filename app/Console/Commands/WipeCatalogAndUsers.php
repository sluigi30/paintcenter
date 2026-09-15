<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties the catalogue and every account, keeping brands and categories.
 *
 * Run this BEFORE the 2026_09_14 migrations, not after. Those five migrations
 * backfill the new columns from the old ones, and on production that backfill
 * is wrong: 000001 assumes `products.description` holds the product's name,
 * which is true of the dev database and false of production, where it holds
 * real paragraphs. On empty tables every backfill is a no-op, so wiping first
 * removes the problem instead of cleaning up after it.
 *
 * Brands and categories are deliberately NOT touched. Nothing seeds them, so
 * dropping them would mean retyping Davies, BOYSEN, Titan and the category
 * list for no reason. `migrate:fresh` would take them; this does not.
 *
 * Deletes go through the query builder, not Eloquent: a mass Eloquent delete
 * would fire ProductVariantObserver, which writes to `inventory_logs` — a
 * table this command is in the middle of cascading away.
 */
class WipeCatalogAndUsers extends Command
{
    protected $signature = 'wipe:catalog {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all products and users (and everything cascading from them), keeping brands and categories';

    /** Counted before and after, so the operator sees what actually went. */
    private const TABLES = [
        'users', 'products', 'product_variants', 'orders', 'order_items',
        'payments', 'messages', 'cart_items', 'inventory_logs', 'sms_logs',
        'brands', 'categories',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=gray>Database:</> ' . DB::connection()->getDatabaseName()
            . ' <fg=gray>on</> ' . config('database.default')
            . ' <fg=gray>|  env:</> ' . app()->environment());
        $this->newLine();

        $before = $this->counts();
        $this->table(['Table', 'Rows now'], array_map(
            fn ($t) => [$t, $before[$t] ?? '—'],
            self::TABLES,
        ));

        $this->warn('  users and products will be DELETED. orders, order_items, payments,');
        $this->warn('  messages, cart_items, inventory_logs and sms_logs cascade away with them.');
        $this->info('  brands and categories are kept.');
        $this->newLine();

        if (! $this->option('force')) {
            $expected = DB::connection()->getDatabaseName();

            if ($this->ask("Type the database name to confirm (\"{$expected}\")") !== $expected) {
                $this->error('  Name did not match — nothing was deleted.');

                return self::FAILURE;
            }
        }

        // One transaction: a foreign key that refuses halfway through leaves
        // the database as it was, rather than half-wiped.
        DB::transaction(function () {
            // Users first: orders, messages and cart_items cascade from here,
            // which leaves fewer rows for the product cascade to walk.
            DB::table('users')->delete();
            DB::table('products')->delete();

            // Nothing points at these by foreign key, but a session cookie
            // naming a deleted user is just confusion waiting to happen.
            if (DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::table('sessions')->delete();
            }
        });

        $after = $this->counts();

        $this->newLine();
        $this->table(['Table', 'Before', 'After'], array_map(
            fn ($t) => [$t, $before[$t] ?? '—', $after[$t] ?? '—'],
            self::TABLES,
        ));

        $this->newLine();
        $this->info('  Done. Next: php artisan migrate --force, then db:seed --force');
        $this->warn('  The seeder creates both admins with the password "password" — change them.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
