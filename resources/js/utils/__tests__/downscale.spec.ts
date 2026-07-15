import { afterEach, describe, expect, it, vi } from "vitest";
import { fitUnderCap, type Rasterize } from "../downscale";

function pngBlob(bytes: number): Blob {
    return new Blob([new Uint8Array(bytes)], { type: "image/png" });
}

function fakeBitmap(width: number, height: number): ImageBitmap {
    return { width, height, close: vi.fn() } as unknown as ImageBitmap;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("fitUnderCap", () => {
    it("returns the blob untouched when already under the cap", async () => {
        const blob = pngBlob(1000);
        const rasterize = vi.fn();

        await expect(fitUnderCap(blob, 1000, rasterize as unknown as Rasterize)).resolves.toBe(blob);
        expect(rasterize).not.toHaveBeenCalled();
    });

    it("downscales an over-cap blob until it fits", async () => {
        const bitmap = fakeBitmap(2000, 1000);
        vi.stubGlobal("createImageBitmap", vi.fn().mockResolvedValue(bitmap));

        // Fake encoder: bytes proportional to pixel area.
        const rasterize: Rasterize = async (_source, width, height) => pngBlob(Math.round(width * height * 0.01));

        const result = await fitUnderCap(pngBlob(20_000), 5_000, rasterize);

        expect(result.size).toBeLessThanOrEqual(5_000);
        expect(result.size).toBeGreaterThan(0);
        expect(bitmap.close).toHaveBeenCalled();
    });

    it("keeps shrinking when the first scale estimate misses", async () => {
        vi.stubGlobal("createImageBitmap", vi.fn().mockResolvedValue(fakeBitmap(1000, 1000)));

        const sizes: number[] = [];
        // Stubborn encoder: ignores area for two rounds, then complies.
        const outputs = [9_000, 8_000, 900];
        const rasterize: Rasterize = async () => {
            const size = outputs[Math.min(sizes.length, outputs.length - 1)];
            sizes.push(size);
            return pngBlob(size);
        };

        const result = await fitUnderCap(pngBlob(10_000), 1_000, rasterize);

        expect(result.size).toBe(900);
        expect(sizes.length).toBe(3);
    });

    it("returns the original blob when it cannot be decoded", async () => {
        vi.stubGlobal("createImageBitmap", vi.fn().mockRejectedValue(new Error("bad image")));

        const blob = pngBlob(10_000);

        await expect(fitUnderCap(blob, 1_000)).resolves.toBe(blob);
    });

    it("returns the smallest attempt when the cap is unreachable", async () => {
        vi.stubGlobal("createImageBitmap", vi.fn().mockResolvedValue(fakeBitmap(100, 100)));

        // Encoder that never gets under the cap.
        const rasterize: Rasterize = async (_source, width) => pngBlob(5_000 + width);

        const result = await fitUnderCap(pngBlob(50_000), 1_000, rasterize);

        expect(result.size).toBeLessThan(50_000);
        expect(result.size).toBeGreaterThan(5_000);
    });
});
