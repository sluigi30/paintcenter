/**
 * "Pick colour from an image" — the tool under the Screen Preview Color field
 * on the product form.
 *
 * Everything happens in the browser on a <canvas>: the photo is never uploaded
 * and never touches storage, so an admin can drag in a snapshot of a shade
 * card, click the swatch, and get a hex out of it without creating a file.
 *
 * Registered as a Filament Alpine component in AppServiceProvider, which means
 * `php artisan filament:assets` publishes it to public/js/app/color-from-image.js.
 * That command already runs on every composer install via filament:upgrade, so
 * deploys pick it up with no extra step.
 *
 * A photo is NOT a colour measurement — white balance and ambient light shift
 * it, often a lot. That is tolerable here only because hex_code is a screen
 * preview; color_code / color_name carry the real paint identity.
 */

const MAX_CANVAS_WIDTH = 760   // the photo is scaled to this before sampling
const SAMPLE_RADIUS = 2        // 5x5 average — one pixel is JPEG noise, not a colour
const PALETTE_SIZE = 6
const MIN_SEPARATION = 48      // how far apart two suggested swatches must be
const LOUPE_SIZE = 128
const LOUPE_ZOOM = 8

const toHex = (r, g, b) =>
    '#' + [r, g, b].map((channel) => channel.toString(16).padStart(2, '0')).join('').toUpperCase()

const distance = (a, b) =>
    Math.sqrt((a.r - b.r) ** 2 + (a.g - b.g) ** 2 + (a.b - b.b) ** 2)

/** Paper white and shadow black dominate photos of shade cards, and are never the colour being catalogued. */
const isExtreme = ({ r, g, b }) =>
    (r >= 244 && g >= 244 && b >= 244) || (r <= 12 && g <= 12 && b <= 12)

export default function colorFromImage({ statePath }) {
    return {
        statePath,

        open: false,
        hasImage: false,
        dragging: false,
        error: null,

        hex: null,
        palette: [],
        supportsEyeDropper: false,

        loupeVisible: false,
        loupeLeft: 0,
        loupeTop: 0,

        pixels: null,
        canvasWidth: 0,
        canvasHeight: 0,

        init() {
            // Chrome and Edge only; the button stays hidden everywhere else.
            this.supportsEyeDropper = typeof window.EyeDropper === 'function'
        },

        togglePanel() {
            this.open = ! this.open
        },

        // ---------------------------------------------------------------
        // Getting an image in: drop, browse, or paste
        // ---------------------------------------------------------------

        onDrop(event) {
            this.dragging = false
            this.loadFile(event.dataTransfer?.files?.[0])
        },

        onBrowse(event) {
            this.loadFile(event.target.files?.[0])
            event.target.value = ''   // so re-picking the same file fires change again
        },

        onPaste(event) {
            const item = [...(event.clipboardData?.items ?? [])]
                .find((entry) => entry.type?.startsWith('image/'))

            if (! item) {
                return
            }

            event.preventDefault()
            this.loadFile(item.getAsFile())
        },

        loadFile(file) {
            if (! file) {
                return
            }

            if (! file.type?.startsWith('image/')) {
                this.error = 'That file is not an image.'

                return
            }

            this.error = null

            const reader = new FileReader()
            reader.onerror = () => (this.error = 'That image could not be read.')
            reader.onload = () => this.render(reader.result)
            reader.readAsDataURL(file)
        },

        /** Draws the image to the canvas and reads its pixels once, up front. */
        render(source) {
            const image = new Image()

            image.onerror = () => (this.error = 'That image could not be opened.')

            image.onload = () => {
                const canvas = this.$refs.canvas
                const scale = Math.min(1, MAX_CANVAS_WIDTH / image.naturalWidth)

                canvas.width = this.canvasWidth = Math.max(1, Math.round(image.naturalWidth * scale))
                canvas.height = this.canvasHeight = Math.max(1, Math.round(image.naturalHeight * scale))

                const context = canvas.getContext('2d', { willReadFrequently: true })
                context.drawImage(image, 0, 0, canvas.width, canvas.height)

                this.pixels = context.getImageData(0, 0, canvas.width, canvas.height)
                this.hasImage = true
                this.palette = this.extractPalette()
                this.hex = this.palette[0] ?? null
            }

            image.src = source
        },

        reset() {
            this.hasImage = false
            this.pixels = null
            this.palette = []
            this.hex = null
            this.loupeVisible = false
            this.error = null
        },

        // ---------------------------------------------------------------
        // Sampling
        // ---------------------------------------------------------------

        positionFrom(event) {
            const rect = this.$refs.canvas.getBoundingClientRect()

            return {
                // The canvas is displayed at CSS width, which is rarely its pixel
                // width, so clicks have to be scaled back into pixel space.
                x: Math.round(((event.clientX - rect.left) / rect.width) * this.canvasWidth),
                y: Math.round(((event.clientY - rect.top) / rect.height) * this.canvasHeight),
                offsetX: event.clientX - rect.left,
                offsetY: event.clientY - rect.top,
            }
        },

        onCanvasMove(event) {
            if (! this.hasImage) {
                return
            }

            const position = this.positionFrom(event)

            this.loupeLeft = position.offsetX
            this.loupeTop = position.offsetY
            this.loupeVisible = true

            this.drawLoupe(position.x, position.y)
        },

        onCanvasClick(event) {
            if (! this.hasImage) {
                return
            }

            const position = this.positionFrom(event)
            const sampled = this.averageAt(position.x, position.y)

            if (sampled) {
                this.hex = sampled
            }
        },

        averageAt(centreX, centreY) {
            if (! this.pixels) {
                return null
            }

            const { data, width, height } = this.pixels
            let red = 0, green = 0, blue = 0, counted = 0

            for (let y = centreY - SAMPLE_RADIUS; y <= centreY + SAMPLE_RADIUS; y++) {
                for (let x = centreX - SAMPLE_RADIUS; x <= centreX + SAMPLE_RADIUS; x++) {
                    if (x < 0 || y < 0 || x >= width || y >= height) {
                        continue
                    }

                    const offset = (y * width + x) * 4

                    if (data[offset + 3] < 128) {
                        continue
                    }

                    red += data[offset]
                    green += data[offset + 1]
                    blue += data[offset + 2]
                    counted++
                }
            }

            if (! counted) {
                return null
            }

            return toHex(
                Math.round(red / counted),
                Math.round(green / counted),
                Math.round(blue / counted),
            )
        },

        /** Magnified view under the cursor, so a small swatch can be hit precisely. */
        drawLoupe(centreX, centreY) {
            const loupe = this.$refs.loupe

            if (! loupe) {
                return
            }

            const context = loupe.getContext('2d')
            const span = LOUPE_SIZE / LOUPE_ZOOM

            context.imageSmoothingEnabled = false
            context.clearRect(0, 0, LOUPE_SIZE, LOUPE_SIZE)
            context.drawImage(
                this.$refs.canvas,
                centreX - span / 2, centreY - span / 2, span, span,
                0, 0, LOUPE_SIZE, LOUPE_SIZE,
            )

            // Outline exactly the block that averageAt() would read.
            const box = (SAMPLE_RADIUS * 2 + 1) * LOUPE_ZOOM
            const at = (LOUPE_SIZE - box) / 2

            context.strokeStyle = 'rgba(0,0,0,.85)'
            context.lineWidth = 2
            context.strokeRect(at - 1, at - 1, box + 2, box + 2)

            context.strokeStyle = 'rgba(255,255,255,.95)'
            context.lineWidth = 1
            context.strokeRect(at, at, box, box)
        },

        // ---------------------------------------------------------------
        // Suggested palette
        // ---------------------------------------------------------------

        /**
         * Buckets the image into 4 bits per channel, then walks the buckets
         * most-common-first, keeping only colours that are visibly apart from
         * the ones already kept. Cheap, and good enough for "which colours are
         * in this photo" — proper k-means would not read any better at six
         * swatches.
         */
        extractPalette() {
            const { data, width, height } = this.pixels
            const total = width * height
            const step = Math.max(1, Math.floor(total / 40000))
            const buckets = new Map()

            for (let index = 0; index < total; index += step) {
                const offset = index * 4

                if (data[offset + 3] < 128) {
                    continue
                }

                const red = data[offset]
                const green = data[offset + 1]
                const blue = data[offset + 2]
                const key = ((red >> 4) << 8) | ((green >> 4) << 4) | (blue >> 4)
                const bucket = buckets.get(key) ?? { r: 0, g: 0, b: 0, n: 0 }

                bucket.r += red
                bucket.g += green
                bucket.b += blue
                bucket.n++

                buckets.set(key, bucket)
            }

            const candidates = [...buckets.values()]
                .sort((a, b) => b.n - a.n)
                .map((bucket) => ({
                    r: Math.round(bucket.r / bucket.n),
                    g: Math.round(bucket.g / bucket.n),
                    b: Math.round(bucket.b / bucket.n),
                }))

            const picked = []
            const isFarEnough = (colour) =>
                picked.every((existing) => distance(existing, colour) >= MIN_SEPARATION)

            for (const colour of candidates) {
                if (picked.length === PALETTE_SIZE) {
                    break
                }

                if (isExtreme(colour) || ! isFarEnough(colour)) {
                    continue
                }

                picked.push(colour)
            }

            // A photo that really is all white or all black should still offer
            // something rather than an empty strip.
            for (const colour of candidates) {
                if (picked.length >= 3) {
                    break
                }

                if (isFarEnough(colour)) {
                    picked.push(colour)
                }
            }

            return picked.map((colour) => toHex(colour.r, colour.g, colour.b))
        },

        // ---------------------------------------------------------------
        // Handing the colour back to the form
        // ---------------------------------------------------------------

        use(swatch) {
            this.hex = swatch
        },

        /**
         * Writes into the sibling ColorPicker. Its input is x-model="state" and
         * state is entangled with this path, so the text box, the swatch and the
         * saved value all follow from this one call.
         */
        apply() {
            if (! this.hex) {
                return
            }

            this.$wire.set(this.statePath, this.hex)
            this.open = false
        },

        /** Samples any pixel on screen — a brand's shade chart open in another window has no lighting problem at all. */
        async pickFromScreen() {
            if (! this.supportsEyeDropper) {
                return
            }

            try {
                const result = await new window.EyeDropper().open()

                this.hex = (result.sRGBHex ?? '').toUpperCase()
                this.apply()
            } catch (error) {
                // The admin pressed Escape. Nothing to report.
            }
        },
    }
}
