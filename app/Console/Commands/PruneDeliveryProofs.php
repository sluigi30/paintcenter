<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\DeliveryProofService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes delivery photos past their retention window, and sweeps orphans.
 *
 * TWO jobs, because there are two ways a file outlives its usefulness:
 *
 *   RETENTION — a proof photo is somebody's front door, sometimes their face,
 *   and one accumulates per delivery forever. Twelve months outlasts any
 *   realistic dispute about a delivery that happened. The row KEEPS
 *   proof_captured_at, so an old order reads as "photographed on 12 March,
 *   since deleted" rather than as a delivery nobody ever photographed.
 *
 *   ORPHANS — DeliveryService writes the file before the row, deliberately, so
 *   a crash between the two leaves a file nothing points at. The catch block
 *   handles the tidy failures; a fatal or an OOM kill runs no catch block at
 *   all, which is why this exists. Same arrangement as
 *   messages:prune-orphan-attachments.
 */
class PruneDeliveryProofs extends Command
{
    protected $signature = 'deliveries:prune-delivery-proofs
                            {--days= : Override the retention window, in days}
                            {--orphans-only : Sweep unreferenced files and keep every photo}
                            {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Delete delivery proof photos past their retention window, and sweep orphaned files';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! $this->option('orphans-only')) {
            $this->pruneExpired($dry);
        }

        $this->sweepOrphans($dry);

        return self::SUCCESS;
    }

    private function pruneExpired(bool $dry): void
    {
        $days = $this->option('days')
            ? (int) $this->option('days')
            : DeliveryProofService::RETENTION_MONTHS * 30;

        $cutoff = now()->subDays($days);

        $orders = Order::whereNotNull('proof_path')
            ->where('proof_captured_at', '<', $cutoff)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No proof photos past the retention window.');

            return;
        }

        $this->info("{$orders->count()} proof photo(s) older than {$days} days.");

        foreach ($orders as $order) {
            $this->line('  '.($dry ? '[dry] ' : '').'order '.$order->getKey().' — '.$order->proof_path);

            if (! $dry) {
                // Clears the file and the descriptors; keeps proof_captured_at.
                DeliveryProofService::forget($order);
            }
        }
    }

    /**
     * Files on the proofs disk that no order points at.
     *
     * The referenced set is read from the database in one pass rather than
     * queried per file — a store with a year of deliveries has thousands of
     * photos, and a query each would make this command slower than the problem.
     */
    private function sweepOrphans(bool $dry): void
    {
        $disk = DeliveryProofService::disk();
        $storage = Storage::disk($disk);

        if (! $storage->directoryExists('delivery-proofs')) {
            return;
        }

        $referenced = Order::whereNotNull('proof_path')
            ->where('proof_disk', $disk)
            ->pluck('proof_path')
            ->flip();

        $orphans = 0;

        foreach ($storage->files('delivery-proofs') as $path) {
            if ($referenced->has($path)) {
                continue;
            }

            $orphans++;
            $this->line('  '.($dry ? '[dry] ' : '').'orphan — '.$path);

            if (! $dry) {
                $storage->delete($path);
            }
        }

        $this->info($orphans === 0 ? 'No orphaned proof files.' : "{$orphans} orphaned file(s).");
    }
}
