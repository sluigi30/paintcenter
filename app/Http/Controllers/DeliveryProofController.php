<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a delivery's proof photo.
 *
 * Reached by an API route (Sanctum) and a panel route, same gate both ways, so
 * the app and the admin panel can never end up with different rules about who
 * may look at a photograph of somebody's front door. Exactly the arrangement
 * MessageAttachmentController uses, for the same reason.
 *
 * NEVER served from a storage path. Beyond the access question, that is what
 * keeps this working in production at all: CLOUD_STORAGE.md records that on
 * Laravel Cloud `FILESYSTEM_DISK=public` accepts uploads but never serves
 * them, which is why product images are broken there. Streaming through here
 * sidesteps it entirely, and lets the disk be private.
 */
class DeliveryProofController extends Controller
{
    /** How long an object-storage URL stays good once handed out. */
    private const SIGNED_URL_TTL_MINUTES = 5;

    public function __invoke(Request $request, Order $order)
    {
        $user = $request->user();

        // 404, not 403, and on every condition. Order ids are a plain
        // auto-increment, so a 403 on a row that exists and a 404 on one that
        // does not is a difference anyone can measure by walking the ids — and
        // "order 812 has a delivery photo" is itself something we never agreed
        // to tell them.
        if (! $user || ! $order->proof_path || ! $this->mayView($user, $order)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk($order->proof_disk);

        if (! $disk->exists($order->proof_path)) {
            // Pruned after the retention window, or swept as an orphan. The
            // order still records that a photo was taken (proof_captured_at);
            // the file simply is not there any more.
            throw new NotFoundHttpException;
        }

        // A signed URL OUTLIVES the check that issued it — once generated it
        // is a bearer token, copyable and shareable, and this gate has no
        // further say until it expires. That is the trade for not proxying
        // every photo through PHP; the TTL is the whole mitigation. The
        // streaming path below has no such window.
        if (config("filesystems.disks.{$order->proof_disk}.driver") === 's3') {
            return redirect($disk->temporaryUrl(
                $order->proof_path,
                now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            ));
        }

        return $disk->response($order->proof_path, null, [
            'Content-Type'  => $order->proof_mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * The customer whose order it is, any admin, or the driver who carried it.
     *
     * A driver keeps access to their OWN deliveries after the fact — it is
     * their record of a job they did, and it is the same photo they took. No
     * other driver can see it.
     */
    private function mayView($user, Order $order): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        if ($user->isDriver()) {
            return $order->driver_id === $user->id;
        }

        return $order->user_id === $user->id;
    }
}
