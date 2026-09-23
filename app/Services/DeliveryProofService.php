<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The photo a driver takes when they hand an order over.
 *
 * File mechanics only — storing, describing, deleting. The RULE that a delivery
 * cannot complete without one lives in DeliveryService, where the rest of the
 * delivery rules are, so both the app and the web panel are bound by it.
 *
 * Modelled on MessageAttachmentService, and for one decisive reason: those
 * files are streamed by a CONTROLLER rather than linked from a public storage
 * URL. CLOUD_STORAGE.md records that on Laravel Cloud `FILESYSTEM_DISK=public`
 * accepts uploads but never serves them — which is why product images are
 * broken there. A proof photo built the products.images way would be invisible
 * in production from the day it shipped.
 */
class DeliveryProofService
{
    /** Per photo, in kilobytes. Same ceiling as a message attachment. */
    public const MAX_FILE_KB = 8192;

    /**
     * No HEIC, for the same three reasons as message attachments: this server
     * has gd and no imagick so it cannot be measured, Chrome cannot render it
     * so an admin would see a broken image, and the app re-encodes every
     * capture to JPEG through expo-image-manipulator so it cannot arrive.
     */
    public const ACCEPTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** How long a proof photo is kept before the prune command removes it. */
    public const RETENTION_MONTHS = 12;

    /** Shared by the API request and the panel's Livewire upload. */
    public static function rules(bool $required = true): array
    {
        return [
            'proof' => [
                $required ? 'required' : 'nullable',
                'file',
                'image',
                'mimes:'.implode(',', self::ACCEPTED_EXTENSIONS),
                'max:'.self::MAX_FILE_KB,
            ],
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'proof.required' => 'Take a photo of the delivery before marking it delivered.',
            'proof.image'    => 'The proof must be a photo.',
            'proof.mimes'    => 'Photos must be JPG, PNG or WEBP.',
            'proof.max'      => 'The photo must be under '.(self::MAX_FILE_KB / 1024).' MB.',
        ];
    }

    /**
     * Where proofs are written.
     *
     * A PRIVATE disk is correct and costs nothing: every read goes through
     * DeliveryProofController's gate, so these files never need to be reachable
     * over the web. Falls back to the app default so local development needs no
     * bucket.
     */
    public static function disk(): string
    {
        return config('filesystems.proofs') ?: config('filesystems.default');
    }

    /**
     * Write the file and describe it. Touches no database row.
     *
     * The path contains no order id on purpose — needing one would force the
     * row to be written first, which is the ordering this whole arrangement
     * exists to avoid. A file with no row is invisible junk the prune command
     * sweeps up; a row with no file is a permanently broken proof on a
     * completed order with nothing to re-upload from.
     *
     * @return array<string, mixed>
     */
    public static function put(UploadedFile $file): array
    {
        $disk = self::disk();

        // Extension from the DETECTED mime, never the client's filename.
        $name = Str::ulid().'.'.($file->extension() ?: 'jpg');
        $path = Storage::disk($disk)->putFileAs('delivery-proofs', $file, $name);

        if ($path === false) {
            throw new \RuntimeException('Could not store the delivery photo.');
        }

        return [
            'proof_disk'        => $disk,
            'proof_path'        => $path,
            'proof_mime'        => $file->getMimeType() ?: 'image/jpeg',
            'proof_size'        => $file->getSize(),
            'proof_captured_at' => now(),
        ];
    }

    /** Take a written file back down after a failure further along. */
    public static function discard(array $stored): void
    {
        if (empty($stored['proof_disk']) || empty($stored['proof_path'])) {
            return;
        }

        try {
            Storage::disk($stored['proof_disk'])->delete($stored['proof_path']);
        } catch (\Throwable) {
            // Best effort. The prune command is the guarantee — a fatal or an
            // OOM kill runs no cleanup at all, which is the whole reason it
            // exists.
        }
    }

    /**
     * Forget the photo but remember that there was one.
     *
     * Clears the file and its descriptors, KEEPING proof_captured_at — an old
     * order should read as "photographed on 12 March, since deleted", never as
     * a delivery that was never photographed at all.
     */
    public static function forget(Order $order): void
    {
        self::discard([
            'proof_disk' => $order->proof_disk,
            'proof_path' => $order->proof_path,
        ]);

        $order->update([
            'proof_disk' => null,
            'proof_path' => null,
            'proof_mime' => null,
            'proof_size' => null,
        ]);
    }
}
