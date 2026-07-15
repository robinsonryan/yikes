/**
 * Client-side screenshot size cap.
 *
 * Screenshots are rendered in the browser (snapdom → canvas → PNG blob), so
 * the browser is the only layer that can resize them without the server
 * growing an image library. When a capture exceeds the cap (the hub 413s
 * files over 5 MB; local mode has its own validation cap), redraw it onto a
 * progressively smaller canvas until it fits.
 */

/** Renders the bitmap at the given size and re-encodes it as PNG. */
export type Rasterize = (source: ImageBitmap, width: number, height: number) => Promise<Blob | null>;

const canvasRasterize: Rasterize = async (source, width, height) => {
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext("2d");
    if (!context) {
        return null;
    }

    context.drawImage(source, 0, 0, width, height);

    return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
};

/**
 * Returns the blob unchanged when it is already under the cap; otherwise the
 * smallest PNG produced while downscaling. Best-effort by design: anything
 * undecodable (or a cap that cannot be met) falls back to the smallest blob
 * we have and lets the server-side validation be the final word.
 */
export async function fitUnderCap(blob: Blob, maxBytes: number, rasterize: Rasterize = canvasRasterize): Promise<Blob> {
    if (maxBytes <= 0 || blob.size <= maxBytes) {
        return blob;
    }

    let source: ImageBitmap;
    try {
        source = await createImageBitmap(blob);
    } catch {
        return blob;
    }

    let best = blob;
    // PNG size tracks pixel area roughly linearly — start from the implied
    // scale and shrink harder after every miss.
    let scale = Math.min(0.9, Math.sqrt(maxBytes / blob.size));

    try {
        for (let attempt = 0; attempt < 6; attempt++) {
            const width = Math.max(1, Math.floor(source.width * scale));
            const height = Math.max(1, Math.floor(source.height * scale));

            const scaled = await rasterize(source, width, height);
            if (!scaled) {
                break;
            }

            if (scaled.size < best.size) {
                best = scaled;
            }
            if (scaled.size <= maxBytes) {
                break;
            }

            scale *= 0.7;
        }
    } finally {
        source.close?.();
    }

    return best;
}
