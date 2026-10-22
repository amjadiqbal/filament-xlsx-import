"""Fail if an image has brown-ish pixels: hue 0-50 deg, saturation > 0.25, value 0.15-0.75 (dark/muted warm).
Usage: python3 -I brown_scan.py img...   Needs ImageMagick (magick) on PATH."""
import colorsys, subprocess, sys
bad = 0
for f in sys.argv[1:]:
    raw = subprocess.run(["magick", f, "-alpha", "off", "-resize", "400x400>", "-depth", "8", "rgb:-"], capture_output=True).stdout
    n = hits = 0
    for i in range(0, len(raw) - 2, 3):
        r, g, b = raw[i] / 255, raw[i + 1] / 255, raw[i + 2] / 255
        h, s, v = colorsys.rgb_to_hsv(r, g, b)
        n += 1
        if (h * 360 <= 50 or h * 360 >= 345) and s > 0.25 and 0.15 < v < 0.75:
            hits += 1
    pct = 100 * hits / max(n, 1)
    print(f"{f}: {hits} brown-ish px of {n} ({pct:.3f}%)")
    bad |= pct > 0.05
sys.exit(1 if bad else 0)
