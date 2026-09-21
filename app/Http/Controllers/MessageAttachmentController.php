<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one message attachment, to someone entitled to see it.
 *
 * Reached two ways and behaving identically down both: an API route under
 * auth:sanctum for the mobile app, and a panel route under the admin panel's
 * own middleware for the Filament Messages page. Same gate, same response -
 * a second entry point is not a second policy.
 *
 * These files are never exposed at a public storage path. They are customers'
 * photographs, and the ids are a plain auto-increment, so anything readable by
 * URL alone is readable by counting.
 */
class MessageAttachmentController extends Controller
{
    /** How long an object-storage URL stays good once handed out. */
    private const SIGNED_URL_TTL_MINUTES = 5;

    public function __invoke(Request $request, MessageAttachment $attachment)
    {
        $user = $request->user();

        $attachment->loadMissing('message');

        // 404, not 403, and on both conditions. A 403 on a row that exists and
        // a 404 on one that does not is a difference anyone can measure by
        // walking the ids, and "attachment 812 exists" is itself something we
        // never agreed to tell them.
        if (! $user || ! $attachment->message || ! $attachment->message->isVisibleTo($user)) {
            throw new NotFoundHttpException;
        }

        // Authorisation is settled above, before anything below can hand out a
        // credential. Worth being explicit about what that does and does not
        // buy on the object-storage path: a signed URL OUTLIVES the check that
        // issued it. Once generated it is a bearer token - copyable out of the
        // browser, shareable, loggable - and this gate has no further say over
        // it until it expires. That is the trade for not proxying every photo
        // through PHP, and the TTL above is the whole of the mitigation. The
        // streaming path below has no such window.
        $disk = $attachment->storage();

        if (config("filesystems.disks.{$attachment->disk}.driver") === 's3') {
            return redirect()->away(
                $disk->temporaryUrl($attachment->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES))
            );
        }

        if (! $disk->exists($attachment->path)) {
            // The row outlived its file. Nothing to serve and nothing to say -
            // the same 404 as a row that was never there.
            throw new NotFoundHttpException;
        }

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            // The mime was detected server-side at upload and the extension
            // derived from it, but this costs nothing and closes the gap
            // between "we decided it was a JPEG" and "the browser agrees".
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline; filename="'.addslashes($attachment->original_name).'"',
            'Cache-Control'          => 'private, max-age=3600',
        ]);
    }
}
