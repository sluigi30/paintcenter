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
    /*
    |--------------------------------------------------------------------------
    | Mixing (customer-composed colours)
    |--------------------------------------------------------------------------
    |
    | The shop has no tinting machine. A custom colour is produced by pouring
    | pint cans of real stocked paint into a base can, so every ingredient is
    | a variant with its own stock and the achievable colour is bounded by
    | what is on the shelf. See MIXING.md.
    |
    | The prediction is single-constant Kubelka-Munk per RGB channel:
    |
    |     K/S   = (1 - R)^2 / 2R          per component, per channel
    |     K/S_m = SUM( volume_fraction * K/S * strength )
    |     R_m   = 1 + K/S_m - sqrt(K/S_m^2 + 2*K/S_m)
    |
    | It runs on GAMMA-ENCODED sRGB, not linearised reflectance. That is
    | physically the wrong space, and it is deliberate: linearising makes the
    | model's known over-darkening WORSE, not better. Measured, one black pint
    | in 4L of white predicts L* 44 gamma-encoded against L* 35 linearised,
    | where the real mix is nearer L* 70. Both are too dark; the cheaper one
    | is also the closer one, and tint_strength is what actually corrects it.
    |
    */
    'mix' => [

        // A pint is not a litre. size_volume is free text, so the word is
        // matched and mapped here rather than parsed as a number.
        'pint_liters' => 0.473,

        // What counts as a pint can. Used by BOTH the PHP accessor and
        // the SQL that filters the ingredient picker, so the list the
        // customer is offered cannot drift from what the endpoint will
        // accept. MySQL 8 and PCRE agree on the \b boundary; verified against
        // 'Pint', 'pt', '1 pt' matching and 'Pinto', 'ptx' not.
        'pint_pattern' => '\\b(pint|pt)\\b',

        // Reflectance clamp for K/S, which divides by R.
        //
        // The floor is physical, not numerical: no real paint reflects less
        // than about 2%. Without it an ingredient entered as #000000 is an
        // IDEAL black of infinite absorption, and one pint of it drags any
        // mix to pure black — the prediction stops being about paint. At 0.02
        // that same pint in 4L of white gives #252525: still too dark, but
        // recognisably a mix. Every value in the documented reach table is
        // identical at this floor.
        'reflectance_floor' => 0.02,
        'reflectance_ceil'  => 0.999999,

        // Per-ingredient calibration bounds. Zero is excluded on purpose: an
        // ingredient that contributes no colour is still paid for by the
        // customer and still poured in by the counter, which is exactly the
        // kind of silent nonsense that produces a wrong can. Anything below
        // about 0.1 is indistinguishable from absent and has the same problem.
        'strength_min' => 0.10,
        'strength_max' => 9.99,

        // How many DISTINCT tints one recipe may name. A guard against a
        // pathological request, not a design opinion.
        'max_tints' => 8,

    ],

    /*
    |--------------------------------------------------------------------------
    | Reachability (solving a target colour against stock)
    |--------------------------------------------------------------------------
    |
    | Given a colour the customer wants, MixSolver searches recipes over what
    | is actually on the shelf. The caps below bound that search.
    |
    | THEY ARE A HEURISTIC. Neither prune is proven to preserve the best
    | recipe — they are derived from a time budget and their cost is MEASURED
    | against an exhaustive reference solver in tests. Raising a cap widens
    | the search and can only improve the best colour found; lowering one
    | trades accuracy for latency. Do not treat these as physical constants
    | the way base/ and gamut/ above are. See REACHABILITY.md.
    |
    */
    'reachable' => [

        // How many bases, ranked nearest-first, the search actually explores.
        'base_cap' => 10,

        // How many tints per base, ranked by how much they move the mix
        // TOWARD the target.
        'tint_cap' => 8,

        // Pints of any one tint. Bounded again by that variant's stock.
        'max_per_tint' => 4,

        // Distinct tints in one proposed recipe. NOT a combinatorics limit —
        // config('paint.mix.max_tints') lets a customer hand-build more. This
        // is what the SOLVER proposes, kept small so the counter's sheet stays
        // readable and batch error does not compound. See MIXING.md.
        'max_distinct_tints' => 2,

        // Recipes within this much ΔE of the best found are treated as
        // indistinguishable, and the cheapest of them wins. A fourth pint
        // bought to gain 0.3 ΔE is real money for a difference nobody can see
        // — and one the prediction cannot honestly resolve while tint_strength
        // is uncalibrated.
        'tie_tolerance' => 1.0,

        // What the customer is told about the gap. Upper bound of each band,
        // in ΔE2000; anything above the last one is "the closest we can pour".
        //
        // These describe the PREDICTION, not the poured can. No band may ever
        // be worded as a guarantee.
        'bands' => [
            'match' => 2.0,   // "We can mix this"
            'close' => 5.0,   // "Very close"
            'near'  => 10.0,  // "Close, but visibly different"
        ],

    ],

    'gamut' => [
        'max_chroma' => 110.0,  // ceiling in mid tones
        'knee_l'     => 65.0,   // above this lightness the ceiling falls off
        'falloff'    => 0.7,    // < 1 keeps light saturated colours reachable
        'min_chroma' => 3.0,    // floor: never refuse white and near-whites
    ],

];
