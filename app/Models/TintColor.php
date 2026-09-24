<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * A colorant preset the customer adds to a mixing base, by the ml.
 *
 * NOT a product: it has no price and no stock. The counter keeps colorant on
 * hand and the customer pays a flat mixing fee per can instead (see
 * config/paint.php `mix.fee`). When the counter runs out of one, the admin
 * archives it; every cart that still names it is refused at checkout rather
 * than filled with something else.
 *
 * Orders never point at this row for their meaning — each order line carries
 * a snapshot of name, hex and ml in `mix_recipe` — so editing a preset later
 * changes future mixes only.
 */
class TintColor extends Model
{
    use TracksActivity;

    public function activityTitle(): string
    {
        return $this->name;
    }

    protected $fillable = [
        'name',
        'hex_code',
        'step_ml',
        'tint_strength',   // calibration — kept off the admin form on purpose
        'sort_order',
        'is_archived',
    ];

    protected $casts = [
        'step_ml' => 'float',
        'tint_strength' => 'float',
        'sort_order' => 'integer',
        'is_archived' => 'boolean',
    ];

    public function setHexCodeAttribute(?string $value): void
    {
        $this->attributes['hex_code'] = strtoupper(trim((string) $value));
    }

    /** Presets the bench offers, in the admin's order. */
    public function scopeAvailable($query)
    {
        return $query->where('is_archived', false)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether $ml is a whole number of this preset's steps. Compared in tenths
     * of a ml as integers: float modulo says 0.3 % 0.1 is not zero.
     */
    public function acceptsAmount(float $ml): bool
    {
        $amount = (int) round($ml * 10);
        $step = (int) round($this->step_ml * 10);

        return $amount > 0 && $step > 0
            && abs($ml * 10 - $amount) < 1e-6
            && $amount % $step === 0;
    }
}
