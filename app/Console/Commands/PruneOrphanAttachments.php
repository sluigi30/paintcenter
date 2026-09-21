<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use App\Services\MessageAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes attachment files that no row points at.
 *
 * This is not a tidiness chore, it is the actual cleanup guarantee. Two paths
 * leave files behind and neither can be closed in application code:
 *
 * 1. A send that dies between writing the files and committing the rows.
 *    MessageAttachmentService catches what it can, but a fatal error, an OOM
 *    kill or a dropped connection runs no catch block at all.
 *
 * 2. A DELETE that cascades in the DATABASE. `message_attachments` cascades
 *    from `messages`, which cascades from `users`. A database-level cascade
 *    fires no Eloquent events, so the model hook that deletes files never
 *    runs - delete a customer and every row vanishes while every file stays,
 *    silently, with nothing left to find them by.
 *
 * Runs against one disk at a time, because rows record the disk they were
 * written to and a store part-way through a move to object storage has live
 * files on both.
 */
class PruneOrphanAttachments extends Command
{
    protected $signature = 'messages:prune-orphan-attachments
                            {--disk= : Disk to sweep (defaults to the attachment disk)}
                            {--hours=24 : Only delete files older than this}
                            {--dry-run : List what would be deleted and stop}';

    protected $description = 'Delete message attachment files that no database row refers to';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: MessageAttachmentService::disk();
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $storage = Storage::disk($disk);

        // No directory check first: object storage has no real directories,
        // so an empty listing is the only portable way to ask.
        $files = $storage->allFiles('messages');

        if ($files === []) {
            $this->info("Nothing to sweep on [{$disk}].");

            return self::SUCCESS;
        }

        // Only the paths on THIS disk. A path present on another disk's rows
        // is not a reference to this disk's copy of it.
        $known = MessageAttachment::where('disk', $disk)
            ->pluck('path')
            ->flip();

        $cutoff = now()->subHours($hours)->getTimestamp();
        $deleted = 0;
        $bytes = 0;

        foreach ($files as $path) {
            if ($known->has($path)) {
                continue;
            }

            // A file younger than the cutoff may belong to a send that is
            // still in flight - the files are written before the rows, so
            // there is always a window where an orphan is not an orphan.
            if ($storage->lastModified($path) > $cutoff) {
                continue;
            }

            $size = $storage->size($path);

            if ($dryRun) {
                $this->line("would delete  {$path}  (".$this->humanBytes($size).')');
            } else {
                $storage->delete($path);
                $this->line("deleted  {$path}");
            }

            $deleted++;
            $bytes += $size;
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} orphaned file(s), ".$this->humanBytes($bytes)." on [{$disk}].");

        if (! $dryRun && $deleted > 0) {
            $this->pruneEmptyFolders($storage);
        }

        return self::SUCCESS;
    }

    /** Each send gets its own folder, so a swept send leaves an empty one. */
    private function pruneEmptyFolders($storage): void
    {
        foreach ($storage->directories('messages') as $directory) {
            if ($storage->allFiles($directory) === []) {
                $storage->deleteDirectory($directory);
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return round($value, 1).' '.$unit;
            }

            $value /= 1024;
        }

        return "{$bytes} B";
    }
}
