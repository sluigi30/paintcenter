<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proves a disk can actually be written to, read back and deleted.
 *
 * Exists because Laravel Cloud's "Commands" tab only runs NON-interactive
 * commands, so `php artisan tinker` is no use there - it sits waiting for
 * input that never comes. Squeezing the same three calls into
 * `tinker --execute` means fighting shell quoting inside a web textbox, which
 * is its own way to get a misleading answer.
 *
 * Deliberately writes, reads AND deletes: a disk whose credentials are wrong
 * usually fails on the write, but one pointed at the wrong BUCKET fails
 * nothing at all - it just quietly puts the file somewhere else. Printing the
 * bucket and endpoint it actually used is the part that catches that.
 */
class StorageDiagnose extends Command
{
    protected $signature = 'storage:diagnose {disk? : The disk to test (defaults to the attachment disk)}';

    protected $description = 'Write, read back and delete a probe file on a disk';

    public function handle(): int
    {
        $name = $this->argument('disk')
            ?: (config('filesystems.attachments') ?: config('filesystems.default'));

        $this->line("disk:     {$name}");
        $this->line('driver:   ' . (config("filesystems.disks.{$name}.driver") ?? '(disk not defined)'));
        $this->line('bucket:   ' . (config("filesystems.disks.{$name}.bucket") ?? '(n/a)'));
        $this->line('endpoint: ' . (config("filesystems.disks.{$name}.endpoint") ?? '(n/a)'));
        $this->newLine();

        if (! config("filesystems.disks.{$name}")) {
            $this->error("No disk named [{$name}] is configured.");

            return self::FAILURE;
        }

        $path  = 'diagnostics/' . Str::ulid() . '.txt';
        $token = 'ncm-' . Str::random(8);
        $disk  = Storage::disk($name);

        try {
            $disk->put($path, $token);
            $this->info('write:    ok');
        } catch (\Throwable $e) {
            $this->error('write:    FAILED — ' . $e->getMessage());

            return self::FAILURE;
        }

        try {
            $readBack = $disk->get($path);

            $readBack === $token
                ? $this->info('read:     ok')
                : $this->error("read:     MISMATCH — wrote [{$token}], read [{$readBack}]");
        } catch (\Throwable $e) {
            $this->error('read:     FAILED — ' . $e->getMessage());

            return self::FAILURE;
        }

        // A private bucket has no public base URL. If one is configured, the
        // disk is public — which for message attachments is the whole thing we
        // were trying to avoid, so say so loudly rather than passing.
        if ($url = config("filesystems.disks.{$name}.url")) {
            $this->warn("public:   this disk has a public base URL ({$url})");
            $this->warn('          customers\' photos must NOT live on a public disk');
        } else {
            $this->info('public:   no public base URL — private, as expected');
        }

        try {
            $disk->delete($path);
            $this->info('cleanup:  ok');
        } catch (\Throwable $e) {
            $this->warn('cleanup:  could not delete ' . $path . ' — ' . $e->getMessage());
        }

        $this->newLine();
        $this->info("[{$name}] is writable and readable.");

        return self::SUCCESS;
    }
}
