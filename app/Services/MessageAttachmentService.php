<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sending a message, with or without photos.
 *
 * One entry point for both the API and the admin panel, so a file uploaded by
 * a customer and a file pasted by an admin are validated, named, measured and
 * stored identically.
 *
 * -- Why files are written BEFORE the transaction --------------------------
 * The filesystem is not transactional, so the two halves of a send can always
 * disagree. They are not equally bad, and the ordering here picks which
 * failure is possible:
 *
 *   file written, row missing  -> invisible junk on disk, swept up later
 *   row written, file missing  -> a permanently broken photo in a customer's
 *                                 thread, with nothing to re-upload from
 *
 * So the files go down first, under a path that needs no message id, and the
 * rows follow inside a transaction. If the transaction fails, the catch block
 * deletes the files. If the PROCESS fails - a fatal, an OOM kill, a dropped
 * connection - no catch block runs at all, which is why the
 * messages:prune-orphan-attachments command exists. The catch is the fast
 * path; the sweeper is the guarantee.
 */
class MessageAttachmentService
{
    /** Per message. */
    public const MAX_FILES = 5;

    /** Per FILE, in kilobytes - not per request. */
    public const MAX_FILE_KB = 8192;

    /**
     * Across the whole request. Five files at the per-file cap would be 40 MB,
     * which is over most deployed post_max_size values - see
     * requestWasTruncated() for what that failure actually looks like.
     */
    public const MAX_TOTAL_KB = 20480;

    /**
     * No HEIC, deliberately.
     *
     * It cannot arrive: the app runs every pick through expo-image-manipulator,
     * which emits JPEG. It cannot be measured: this server has gd and no
     * imagick, so getimagesize() returns false on it. And above all it cannot
     * be LOOKED AT - Chrome and Firefox do not render HEIC, so an admin
     * opening that thread in the panel would get a broken image icon. Storing
     * a format nothing in the system can display is worse than refusing it.
     */
    public const ACCEPTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Shared by the API request and the admin panel's Livewire upload. */
    public static function rules(): array
    {
        $mimes = implode(',', self::ACCEPTED_EXTENSIONS);

        return [
            // A photo says enough on its own; a required caption is a caption
            // nobody reads. One or the other must be present.
            'content'       => ['nullable', 'string', 'max:2000', 'required_without:attachments'],
            'attachments'   => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'image', 'mimes:'.$mimes, 'max:'.self::MAX_FILE_KB],
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'attachments.max'          => 'You can attach up to '.self::MAX_FILES.' photos to one message.',
            'attachments.*.mimes'      => 'Photos must be JPG, PNG or WEBP.',
            'attachments.*.image'      => 'Only photos can be attached.',
            'attachments.*.max'        => 'Each photo must be under '.(self::MAX_FILE_KB / 1024).' MB.',
            'content.required_without' => 'Type a message or attach a photo.',
        ];
    }

    /**
     * PHP discards the ENTIRE request body when it exceeds post_max_size - the
     * POST fields and the uploaded files both come back empty. Validation then
     * reports the content field as missing, which is a completely misleading
     * error for "your photos were too big", and no validation rule can catch
     * it because there is nothing left to validate. A Content-Length saying a
     * body was sent, with no body present, is that and nothing else.
     */
    public static function requestWasTruncated(Request $request): bool
    {
        $declared = (int) $request->server('CONTENT_LENGTH', 0);

        return $declared > 0
            && $request->all() === []
            && $request->allFiles() === [];
    }

    /** Total upload size in kilobytes, for the request-wide cap. */
    public static function totalKilobytes(array $files): int
    {
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += $file->getSize() ?: 0;
        }

        return (int) ceil($bytes / 1024);
    }

    /**
     * Where attachments are written. Follows the app default so moving the
     * store to object storage is a config change and nothing else - the disk
     * is recorded on each row, so files already written stay resolvable
     * against the disk they were actually put on.
     *
     * A PRIVATE disk is the better choice here and costs nothing: every read
     * goes through MessageAttachmentController's gate, so these files never
     * need to be reachable over the web at all.
     */
    public static function disk(): string
    {
        // Falls back to the app default when no dedicated disk is configured,
        // so local development needs no bucket.
        return config('filesystems.attachments') ?: config('filesystems.default');
    }

    /**
     * Send. Returns the created message with its attachments loaded.
     *
     * @param  UploadedFile[]  $files
     */
    public static function send(
        int $senderId,
        int $receiverId,
        ?string $content,
        array $files = [],
        string $kind = Message::KIND_CHAT,
    ): Message {
        $stored = self::putFiles($files);

        try {
            return DB::transaction(function () use ($senderId, $receiverId, $content, $stored, $kind) {
                $text = trim((string) $content);

                $message = Message::create([
                    'sender_id'   => $senderId,
                    'receiver_id' => $receiverId,
                    'content'     => $text !== '' ? $text : null,
                    'kind'        => $kind,
                    'timestamp'   => now(),
                    'is_read'     => false,
                ]);

                foreach ($stored as $attributes) {
                    $message->attachments()->create($attributes);
                }

                return $message->load('attachments');
            });
        } catch (\Throwable $e) {
            self::discard($stored);

            throw $e;
        }
    }

    /**
     * Write the files and describe them. Nothing here touches the database, so
     * the paths deliberately do NOT contain a message id - needing one would
     * force the row to be written first, which is the ordering this whole
     * class exists to avoid.
     *
     * @param  UploadedFile[]  $files
     * @return array<int, array<string, mixed>>
     */
    protected static function putFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $disk = self::disk();
        $folder = 'messages/'.Str::ulid();
        $stored = [];

        try {
            foreach (array_values($files) as $index => $file) {
                // Measured from the temp file, before it moves. False for
                // anything gd cannot read, which is fine - the dimensions are
                // a layout hint, not a condition of storing the file.
                $size = @getimagesize($file->getRealPath());

                // Extension from the DETECTED mime, never from the client's
                // filename. The original name is kept for display only.
                $name = Str::ulid().'.'.($file->extension() ?: 'jpg');
                $path = Storage::disk($disk)->putFileAs($folder, $file, $name);

                if ($path === false) {
                    throw new \RuntimeException('Could not store attachment.');
                }

                $stored[] = [
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                    'size'          => $file->getSize(),
                    'width'         => $size[0] ?? null,
                    'height'        => $size[1] ?? null,
                    'sort_order'    => $index,
                ];
            }
        } catch (\Throwable $e) {
            // Half a batch is not a message. Take the rest back down.
            self::discard($stored);

            throw $e;
        }

        return $stored;
    }

    /** @param  array<int, array<string, mixed>>  $stored */
    protected static function discard(array $stored): void
    {
        foreach ($stored as $attributes) {
            try {
                Storage::disk($attributes['disk'])->delete($attributes['path']);
            } catch (\Throwable $e) {
                // Nothing useful to do here - the sweeper will find it. Losing
                // the original exception to a cleanup failure would be worse.
                report($e);
            }
        }
    }
}
