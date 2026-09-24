<?php

/**
 * Tint-recipe mixing: a base can with colorant presets added by the ml.
 *
 * These are OPERATIONAL values, tuned against what the shop actually pours —
 * not physical constants. See MIXING.md.
 *
 * (The dispenser settings — P/M/D base thresholds and a latex gamut — and the
 * pint-pour search caps were retired 2026-09-24 with the designs they served;
 * see MIXING_PINTS.md and CUSTOM_COLOR.md for why each existed.)
 */
return [

    'mix' => [

        /*
        |----------------------------------------------------------------------
        | Predicting the colour
        |----------------------------------------------------------------------
        |
        | Single-constant Kubelka-Munk per RGB channel (ColorService::mix, and
        | its mirror in the app's lib/paintMix.js):
        |
        |     K/S   = (1 - R)^2 / 2R          per component, per channel
        |     K/S_m = SUM( volume_fraction * K/S * strength )
        |     R_m   = 1 + K/S_m - sqrt(K/S_m^2 + 2*K/S_m)
        |
        | It runs on GAMMA-ENCODED sRGB, not linearised reflectance. That is
        | physically the wrong space, and it is deliberate: linearising makes
        | the model's known over-darkening WORSE. Each colorant's tint_strength
        | is what actually corrects it — calibrated against real mixed cans.
        |
        */

        // The floor is physical, not numerical: no real paint reflects less
        // than about 2%. Without it a colorant entered as #000000 is an IDEAL
        // black of infinite absorption and any trace of it drags a mix to pure
        // black — the prediction stops being about paint.
        'reflectance_floor' => 0.02,
        'reflectance_ceil'  => 0.999999,

        // "Pint" is a can size on some shelves, not a number, so the volume
        // parser maps the word. 0.473 L US; 0.568 L imperial.
        'pint_liters' => 0.473,

        /*
        |----------------------------------------------------------------------
        | What a recipe may be
        |----------------------------------------------------------------------
        */

        // DISTINCT colorants one recipe may name. A guard against a
        // pathological request; the solver itself proposes at most three.
        'max_tints' => 8,

        // How much colorant one litre of base accepts when neither the can nor
        // its base type says. About 6% by volume, typical of a white base.
        'default_max_tint_ml_per_liter' => 60.0,

        /*
        |----------------------------------------------------------------------
        | Base types
        |----------------------------------------------------------------------
        |
        | A mixing base is chosen by TYPE in the admin, never by typing a hex:
        | the type fixes its preview colour, how strongly colorant shows in it,
        | and how much colorant it takes. One place to calibrate, and no base
        | can be given a wrong colour by hand.
        |
        |   hex             the untinted base, DRIED over white. A deep base
        |                   has little white pigment and dries near-translucent,
        |                   so it is a pale grey — not bright white.
        |   tint_response   how much harder colorant pulls in this base than in
        |                   a white one. Physically: the base's scattering
        |                   relative to white, inverted — less white pigment
        |                   scatters less, so the same 20 ml shows deeper. It
        |                   multiplies every colorant's K/S contribution, and it
        |                   is what makes a deep base reach deep colours in the
        |                   prediction. A hex alone cannot say this.
        |   max_tint_ml_per_liter  default colorant capacity; a can may override.
        |
        | STARTING GUESSES, NOT MEASUREMENTS. Calibrate colorant tint_strength
        | in the WHITE base first (response 1.0 is the reference), then each
        | other type: mix a known recipe (e.g. 20 ml oxide red in 1 L), compare
        | the dried sample with the app's preview, adjust tint_response here.
        | Keys are stored on product_variants.base_type — rename a label freely,
        | never a key.
        |
        */
        'base_types' => [
            'white'  => ['label' => 'White Base',  'hex' => '#FAFAF7', 'tint_response' => 1.0, 'max_tint_ml_per_liter' => 60.0],
            'pastel' => ['label' => 'Pastel Base', 'hex' => '#F4F2EC', 'tint_response' => 1.2, 'max_tint_ml_per_liter' => 80.0],
            'medium' => ['label' => 'Medium Base', 'hex' => '#EAE7DF', 'tint_response' => 1.6, 'max_tint_ml_per_liter' => 100.0],
            'deep'   => ['label' => 'Deep Base',   'hex' => '#DDDAD0', 'tint_response' => 2.5, 'max_tint_ml_per_liter' => 120.0],
            'accent' => ['label' => 'Accent Base', 'hex' => '#CFCBC0', 'tint_response' => 3.5, 'max_tint_ml_per_liter' => 150.0],
        ],

        // The finest step any preset may be measured in. Amounts are compared
        // in tenths of a ml, never with float modulo (0.3 % 0.1 != 0).
        'min_step_ml' => 0.5,

        // Charged on top of the base price, PER CAN mixed. Flat on purpose:
        // the customer pays for the counter's time, not for colorant, which
        // the per-litre cap keeps bounded anyway.
        'fee' => (float) env('PAINT_MIX_FEE', 100),

        /*
        |----------------------------------------------------------------------
        | What the customer is told about a match
        |----------------------------------------------------------------------
        |
        | Upper bound of each band, in ΔE2000; anything above the last is "the
        | closest we can mix". They describe the PREDICTION, never the poured
        | can, and no band may be worded as a guarantee. Mirrored by
        | MATCH_BANDS in the app's lib/paintMix.js — the parity fixture checks.
        |
        */
        'bands' => [
            'match' => 2.0,
            'close' => 5.0,
            'near'  => 10.0,
        ],

    ],

];
