{{--
    Colour-from-image picker, shown under the Screen Preview Color field.

    Styles are written out longhand instead of with Tailwind utilities: the admin
    panel uses Filament's own compiled stylesheet, which does not scan app views,
    so arbitrary utility classes would simply not exist here. Dark mode follows
    Filament's `.dark` class on <html>.
--}}
@php
    $statePath = $getStatePath();

    // The ColorPicker this tool feeds is a sibling, so it shares this field's
    // container path (e.g. "data.hex_picker" -> "data.hex_code").
    $targetPath = str_contains($statePath, '.')
        ? \Illuminate\Support\Str::beforeLast($statePath, '.') . '.hex_code'
        : 'hex_code';
@endphp

<div
    wire:ignore
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('color-from-image', 'app') }}"
    x-data="colorFromImage({ statePath: @js($targetPath) })"
    class="cfi"
>
    <style>
        .cfi { --cfi-line: #e5e7eb; --cfi-bg: #ffffff; --cfi-muted: #6b7280; --cfi-text: #374151; --cfi-soft: #f9fafb; }
        .dark .cfi { --cfi-line: #3f3f46; --cfi-bg: #18181b; --cfi-muted: #a1a1aa; --cfi-text: #d4d4d8; --cfi-soft: #27272a; }

        /* Filament's stylesheet has no [x-cloak] rule, so the panel would flash open before Alpine boots. */
        .cfi [x-cloak] { display: none !important; }

        .cfi-row { display: flex; flex-wrap: wrap; gap: .5rem; }
        .cfi-btn {
            display: inline-flex; align-items: center; gap: .375rem;
            padding: .375rem .75rem; font-size: .75rem; font-weight: 600; line-height: 1.25rem;
            color: var(--cfi-text); background: var(--cfi-bg);
            border: 1px solid var(--cfi-line); border-radius: .5rem; cursor: pointer;
        }
        .cfi-btn:hover { background: var(--cfi-soft); }
        .cfi-btn:disabled { opacity: .5; cursor: not-allowed; }
        .cfi-btn-primary { color: #fff; background: #b91c1c; border-color: #b91c1c; }
        .cfi-btn-primary:hover:not(:disabled) { background: #991b1b; }
        .cfi-btn svg { width: 1rem; height: 1rem; }

        .cfi-panel { margin-top: .75rem; padding: .875rem; border: 1px solid var(--cfi-line); border-radius: .75rem; background: var(--cfi-bg); }

        .cfi-drop {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem;
            padding: 2rem 1rem; text-align: center; cursor: pointer;
            border: 2px dashed var(--cfi-line); border-radius: .625rem; background: var(--cfi-soft);
        }
        .cfi-drop-active { border-color: #b91c1c; background: rgba(185, 28, 28, .06); }
        .cfi-drop p { margin: 0; font-size: .8125rem; color: var(--cfi-text); }
        .cfi-link { color: #b91c1c; font-weight: 600; text-decoration: underline; }
        .cfi-file { display: none; }

        .cfi-canvas-wrap { position: relative; display: inline-block; max-width: 100%; line-height: 0; }
        .cfi-canvas { max-width: 100%; height: auto; border-radius: .5rem; cursor: crosshair; border: 1px solid var(--cfi-line); }
        .cfi-loupe {
            position: absolute; width: 128px; height: 128px; pointer-events: none;
            transform: translate(-50%, calc(-100% - .75rem));
            border-radius: .5rem; border: 2px solid var(--cfi-bg);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .28); background: var(--cfi-bg);
        }

        .cfi-hint { margin: .5rem 0 0; font-size: .75rem; color: var(--cfi-muted); }
        .cfi-label { font-size: .6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--cfi-muted); }

        .cfi-swatches { display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .375rem; }
        .cfi-swatch { width: 2rem; height: 2rem; padding: 0; border: 1px solid var(--cfi-line); border-radius: .5rem; cursor: pointer; }
        .cfi-swatch-on { outline: 2px solid #b91c1c; outline-offset: 2px; }

        .cfi-result { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .875rem; padding-top: .875rem; border-top: 1px solid var(--cfi-line); }
        .cfi-preview { width: 2rem; height: 2rem; border: 1px solid var(--cfi-line); border-radius: .5rem; }
        .cfi-hex { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; color: var(--cfi-text); margin-right: auto; }
        .cfi-error { margin: .5rem 0 0; font-size: .75rem; color: #b91c1c; }
    </style>

    <div class="cfi-row">
        <button type="button" class="cfi-btn" x-on:click="togglePanel()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            </svg>
            <span x-text="open ? 'Close image picker' : 'Pick color from an image'"></span>
        </button>

        <button type="button" class="cfi-btn" x-show="supportsEyeDropper" x-cloak x-on:click="pickFromScreen()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11.25 1.5 24.75l-.75-.75L14.25 10.5m.75.75 4.5-4.5m-4.5 4.5-2.25-2.25m6.75 2.25 2.121-2.121a3 3 0 0 0 0-4.243l-1.007-1.007a3 3 0 0 0-4.243 0L14.25 6m5.25 5.25L14.25 6" />
            </svg>
            Sample from screen
        </button>
    </div>

    <div class="cfi-panel" x-show="open" x-cloak x-on:paste.window="open && onPaste($event)">
        <div
            class="cfi-drop"
            x-show="! hasImage"
            x-bind:class="{ 'cfi-drop-active': dragging }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="onDrop($event)"
            x-on:click="$refs.file.click()"
        >
            <p>Drop a photo here, paste one, or <span class="cfi-link">browse</span></p>
            <p class="cfi-hint">A shade card, the can label, or any photo containing the colour. Nothing is uploaded.</p>
        </div>

        <input type="file" accept="image/*" class="cfi-file" x-ref="file" x-on:change="onBrowse($event)" />

        <div x-show="hasImage" x-cloak>
            <div class="cfi-canvas-wrap">
                <canvas
                    x-ref="canvas"
                    class="cfi-canvas"
                    x-on:mousemove="onCanvasMove($event)"
                    x-on:mouseleave="loupeVisible = false"
                    x-on:click="onCanvasClick($event)"
                ></canvas>

                <canvas
                    x-ref="loupe"
                    width="128"
                    height="128"
                    class="cfi-loupe"
                    x-show="loupeVisible"
                    x-cloak
                    x-bind:style="`left: ${loupeLeft}px; top: ${loupeTop}px`"
                ></canvas>
            </div>

            <p class="cfi-hint">Click the photo to sample that spot — it averages a 5&times;5 pixel block, so JPEG noise does not decide the colour.</p>

            <div x-show="palette.length" style="margin-top: .875rem">
                <span class="cfi-label">Colors found in this photo</span>
                <div class="cfi-swatches">
                    <template x-for="swatch in palette" :key="swatch">
                        <button
                            type="button"
                            class="cfi-swatch"
                            x-bind:class="{ 'cfi-swatch-on': swatch === hex }"
                            x-bind:style="`background: ${swatch}`"
                            x-bind:title="swatch"
                            x-on:click="use(swatch)"
                        ></button>
                    </template>
                </div>
            </div>

            <div class="cfi-result">
                <span class="cfi-preview" x-bind:style="`background: ${hex || 'transparent'}`"></span>
                <code class="cfi-hex" x-text="hex || 'No color picked yet'"></code>
                <button type="button" class="cfi-btn" x-on:click="reset()">Use another photo</button>
                <button type="button" class="cfi-btn cfi-btn-primary" x-bind:disabled="! hex" x-on:click="apply()">Use this color</button>
            </div>
        </div>

        <p class="cfi-error" x-show="error" x-cloak x-text="error"></p>
    </div>
</div>
