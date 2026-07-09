<?php

namespace App\Listeners;

use App\Filament\Resources\InventoryResource;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

class SendStockAlertsOnLogin
{
    /**
     * Titles used by the login briefing — also the key for finding
     * and replacing the previous briefing in the notification bell.
     */
    private const BRIEFING_TITLES = ['Inventory Action Required', 'Inventory Advisory'];

    /**
     * On admin login, ONE briefing of the CURRENT stock situation is
     * surfaced in two places at once:
     *
     *   - an instant popup, so it can't be missed
     *   - the notification bell, where it REPLACES the previous
     *     briefing (never stacks) and is removed once stock is healthy
     *
     * The transition-based alerts (ProductObserver) cover changes
     * that happen while admins are already inside the panel.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! ($user->isAdmin() || $user->isSuperAdmin())) {
            return;
        }

        // Session flash only works for browser logins (the admin panel).
        // API token logins have no session — nothing to show there.
        if (! request()->hasSession()) {
            return;
        }

        // The bell keeps at most ONE briefing, always current: drop
        // the previous one before deciding whether to issue a new one.
        $user->notifications()
            ->whereIn('data->title', self::BRIEFING_TITLES)
            ->delete();

        $active = Product::where('is_archived', false);

        $outOfStock = (clone $active)->outOfStock()->with('brand')->get();
        $lowStock   = (clone $active)->lowStock()->where('stock', '>', 0)->count();

        if ($outOfStock->isEmpty() && $lowStock === 0) {
            return;
        }

        $briefing = fn () => Notification::make()
            ->status($outOfStock->isNotEmpty() ? 'danger' : 'warning')
            ->icon($outOfStock->isNotEmpty() ? 'heroicon-o-shield-exclamation' : 'heroicon-o-chart-bar-square')
            ->title($outOfStock->isNotEmpty() ? self::BRIEFING_TITLES[0] : self::BRIEFING_TITLES[1])
            ->body($this->composeBriefing($user, $outOfStock, $lowStock))
            ->actions([
                Action::make('review_inventory')
                    ->label('Review Inventory')
                    ->button()
                    // Filament 4 binds table filters to the `filters`
                    // query param (#[Url(as: 'filters')] on ListRecords).
                    // Lands on Inventory, where Adjust Stock keeps the
                    // audit trail (InventoryLog::record).
                    ->url(InventoryResource::getUrl('index', [
                        'filters' => ['stock_status' => ['value' => 'attention']],
                    ]))
                    ->markAsRead()
                    ->close(),
            ]);

        // The bell copy (notifyNow: delivered even without a queue worker)
        $user->notifyNow($briefing()->toDatabase());

        // The instant popup, with a dismiss shortcut
        $briefing()
            ->persistent()
            ->actions([
                ...$briefing()->getActions(),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->color('gray')
                    ->close(),
            ])
            ->send();
    }

    /**
     * Executive-style summary, e.g.:
     *
     * "Good morning, Luigi. 1 product has sold out and 15 more have
     *  fallen below their reorder point.
     *  Sold out: Boysen — Plexibond Waterproofing (1L)."
     *
     * Note: Filament renders the body as sanitized HTML (not
     * markdown), so emphasis uses <strong> tags.
     */
    private function composeBriefing(User $user, $outOfStock, int $lowStock): string
    {
        $hour = (int) now()->format('G');

        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };

        $out = $outOfStock->count();

        $summary = match (true) {
            $out > 0 && $lowStock > 0 =>
                "<strong>{$out}</strong> " . Str::plural('product', $out) . ' ' . ($out === 1 ? 'has' : 'have') . ' sold out ' .
                "and <strong>{$lowStock}</strong> more " . ($lowStock === 1 ? 'has' : 'have') . ' fallen below ' .
                ($lowStock === 1 ? 'its' : 'their') . ' reorder point.',

            $out > 0 =>
                "<strong>{$out}</strong> " . Str::plural('product', $out) . ' ' . ($out === 1 ? 'has' : 'have') . ' sold out ' .
                'and ' . ($out === 1 ? 'is' : 'are') . ' unavailable to customers until restocked.',

            default =>
                "<strong>{$lowStock}</strong> " . Str::plural('product', $lowStock) . ' ' . ($lowStock === 1 ? 'has' : 'have') .
                ' fallen below ' . ($lowStock === 1 ? 'its' : 'their') . ' reorder point and may sell out soon.',
        };

        $briefing = "{$greeting}, {$user->first_name}. {$summary}";

        // Name the sold-out items when there are only a few —
        // specifics beat a generic count.
        if ($out > 0 && $out <= 3) {
            $names = $outOfStock
                ->map(fn (Product $product) => trim(
                    ($product->brand?->brand_name ? "{$product->brand->brand_name} — " : '') .
                    $product->description .
                    ($product->size_volume ? " ({$product->size_volume})" : '')
                ))
                ->implode('; ');

            $briefing .= "<br><br><strong>Sold out:</strong> {$names}.";
        }

        return $briefing;
    }
}
