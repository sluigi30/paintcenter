<?php

/**
 * Custom-colour tinting parameters.
 *
 * These are a MODEL of pigment loading, not the dispenser's specification.
 * They live in config so they can be tuned against what the shop's machine
 * actually achieves without touching ColorService. Validate against real
 * mixes before relying on them. See CUSTOM_COLOR.md.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Base selection thresholds (CIELAB)
    |--------------------------------------------------------------------------
    |
    | Which base a colour needs depends on how much colorant it takes, which
    | is a function of perceived lightness (L*) and chroma (C*).
    |
    | Evaluated in order: pastel, then deep, otherwise medium.
    |
    |   pastel  light and muted   — small colorant load, needs the white
    |   deep    dark or saturated — heavy colorant, little or no white or
    |                               the colour cannot reach its target
    |   medium  everything else
    |
    */
    'base' => [
        'pastel' => [
            'code'      => 'P',
            'min_l'     => 80.0,   // lighter than this ...
            'max_c'     => 30.0,   // ... AND more muted than this
        ],
        'deep' => [
            'code'      => 'D',
            'max_l'     => 45.0,   // darker than this ...
            'min_c'     => 50.0,   // ... OR more saturated than this
        ],
        'medium' => [
            'code'      => 'M',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gamut limit
    |--------------------------------------------------------------------------
    |
    | Fluorescent and electric-bright screen colours cannot be produced in
    | latex paint at all. Rejecting them in the picker is the whole point —
    | the alternative is finding out at the counter with the money taken.
    |
    | The ceiling on chroma FALLS AS LIGHTNESS RISES, because that is how
    | paint behaves: to make a colour light you add white, and white
    | desaturates. A flat cap gets this backwards — it refuses a fire-engine
    | red (C* 105 at L* 53, genuinely mixable) while waving through an
    | electric cyan at L* 91.
    |
    |     L* <= knee_l   ->  max_chroma
    |     L* >  knee_l   ->  max_chroma * ((100 - L*) / (100 - knee_l)) ^ falloff
    |
    | The exponent softens the falloff so that light-but-saturated colours
    | which paint really can reach — buttercup yellows above all, since yellow
    | pigment is inherently light — are not swept up with the fluorescents.
    |
    | min_chroma is a floor under that curve. Without it the ceiling reaches
    | exactly zero at L* 100 and pure white — the highest-volume product in
    | the shop — gets refused by its own floating-point residue. The curve
    | touching zero is an artefact of the formula, not a physical limit.
    |
    | This is a heuristic boundary, not a measured gamut hull: some rejected
    | colours may in fact be mixable, and some accepted ones may disappoint.
    |
    */
    'gamut' => [
        'max_chroma' => 110.0,  // ceiling in mid tones
        'knee_l'     => 65.0,   // above this lightness the ceiling falls off
        'falloff'    => 0.7,    // < 1 keeps light saturated colours reachable
        'min_chroma' => 3.0,    // floor: never refuse white and near-whites
    ],

];
