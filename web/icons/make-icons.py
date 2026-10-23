"""Draws the RemoteWake PWA icon set (same glyph as web/icons/icon.svg)."""
import os
import sys
from PIL import Image, ImageDraw

BG = (15, 20, 25, 255)       # --bg  #0f1419
FG = (230, 237, 243, 255)    # --text #e6edf3
ACCENT = (59, 130, 246, 255) # --accent #3b82f6
S = 2048                     # supersample, then downscale for clean edges


def draw(scale=1.0, rounded=True):
    """Glyph on a 512-unit canvas; `scale` shrinks it into a maskable safe zone."""
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    k = S / 512

    if rounded:
        d.rounded_rectangle([0, 0, S - 1, S - 1], radius=112 * k, fill=BG)
    else:
        d.rectangle([0, 0, S - 1, S - 1], fill=BG)

    def pt(x, y):
        # scale about the centre (256, 256)
        return ((256 + (x - 256) * scale) * k, (256 + (y - 256) * scale) * k)

    w = lambda v: max(1, int(round(v * scale * k)))

    # light radiating from the screen
    for a, b in (((256, 54), (256, 98)), ((141, 101), (172, 132)), ((371, 101), (340, 132))):
        d.line([pt(*a), pt(*b)], fill=ACCENT, width=w(22), joint="curve")
        for p in (a, b):  # round caps
            cx, cy = pt(*p)
            r = w(22) / 2
            d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=ACCENT)

    # phone body
    x0, y0 = pt(166, 150)
    x1, y1 = pt(346, 450)
    d.rounded_rectangle([x0, y0, x1, y1], radius=30 * scale * k, outline=FG, width=w(20))

    # lit screen
    x0, y0 = pt(192, 190)
    x1, y1 = pt(320, 410)
    d.rounded_rectangle([x0, y0, x1, y1], radius=12 * scale * k, fill=ACCENT)

    # home button
    cx, cy = pt(256, 428)
    r = 9 * scale * k
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=FG)
    return img


def save(img, path, size):
    out = img.resize((size, size), Image.LANCZOS)
    out.save(path)
    print("wrote", path, size)


def main(web_dir):
    icons = os.path.join(web_dir, "icons")
    os.makedirs(icons, exist_ok=True)
    normal = draw()
    maskable = draw(scale=0.66, rounded=False)

    save(normal, os.path.join(icons, "icon-192.png"), 192)
    save(normal, os.path.join(icons, "icon-512.png"), 512)
    save(normal, os.path.join(icons, "apple-touch-icon.png"), 180)
    save(maskable, os.path.join(icons, "icon-maskable-512.png"), 512)
    save(maskable, os.path.join(icons, "icon-maskable-192.png"), 192)

    # multi-size favicon
    ico = os.path.join(web_dir, "favicon.ico")
    normal.resize((256, 256), Image.LANCZOS).save(
        ico, sizes=[(16, 16), (32, 32), (48, 48), (64, 64)]
    )
    print("wrote", ico)


if __name__ == "__main__":
    main(sys.argv[1])
