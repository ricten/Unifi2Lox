"""Erzeugt das Logo und die LoxBerry-Plugin-Icons im Stil von Senec2Lox / Nut2Lox / Fronius2Lox / Bambulab2Lox.

Aufruf (aus dem Repo-Ordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift: Arial Bold (Windows) bzw. DejaVu Sans Bold.

Ausgabe:
  docs/logo/unifi2lox.png                      gruener, runder LoxBerry-Stil
  unifi2lox/icons/icon_{64,128,256,512}.png    dasselbe als Plugin-Icons

Links ein Switch mit WLAN-Wellen (eigene, schematische Darstellung ohne Herstellerlogo und Schrift: dunkles Gehaeuse mit zwei Portreihen
und Link-Leuchten), rechts der Miniserver (wie bei Senec2Lox), dazwischen ein Pfeil,
darunter der Name "Unifi2Lox".
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
LABEL = "Unifi2Lox"
LABEL_WIDTH = 310      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie
OX, OY = -8, 12         # Verschiebung der ganzen Gruppe: Mittelpunkt der Gruppe (x 99..429, y 137..352) liegt im Kreismittelpunkt (256, 256)

SWITCH_C = (155, 205)                      # Mittelpunkt des Switches
MINISERVER = (270, 152, 426, 240)          # x1, y1, x2, y2 (gruenes Gehaeuse, Draufsicht)
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (214, 256)


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht), identisch zu Senec2Lox.

    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("ellipse", cx, cy, rx, ry, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))
    sh.append(("rrect", x1, y1, x2, y2, 0, green))
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def switch_shapes():
    """Switch mit zwei Portreihen (schematisch, ohne Herstellerlogo und Schrift) und darueber WLAN-Wellen fuer die Access Points:
    weisser Rahmen, dunkles Gehaeuse, Sockel mit gruenen und orangen Link-Leuchten."""
    cx, cy = SWITCH_C
    w, h = 56, 29                                  # halbe Breite und Hoehe
    white = (255, 255, 255, 255)
    body = (46, 48, 52, 255)
    body_hi = (92, 96, 102, 255)
    socket = (18, 20, 24, 255)
    green, orange = (120, 210, 90, 255), (238, 150, 40, 255)
    sh = []
    sh.append(("rrect", cx - w - 3, cy - h - 3, cx + w + 3, cy + h + 3, 6, white))
    sh.append(("rrect", cx - w, cy - h, cx + w, cy + h, 4, body_hi))
    sh.append(("rrect", cx - w + 2.5, cy - h + 2.5, cx + w - 2.5, cy + h - 2.5, 3, body))
    lit = {(0, 0): green, (0, 1): green, (0, 3): orange, (1, 1): green, (1, 2): green, (1, 4): green}
    for row in range(2):
        for col in range(5):
            x1 = cx - w + 11 + col * 20
            y1 = cy - h + 9 + row * 24
            sh.append(("rrect", x1, y1, x1 + 14, y1 + 12, 2, socket))
            sh.append(("rrect", x1 + 2, y1 + 2, x1 + 12, y1 + 6, 1, (60, 64, 70, 255)))
            sh.append(("rrect", x1 + 5, y1 + 14.5, x1 + 9, y1 + 17.5, 1, lit.get((row, col), (70, 74, 80, 255))))
    # WLAN-Wellen (drei Boegen und ein Punkt) ueber dem Switch
    wx, wy = cx, cy - h - 12
    sh.append(("circle", wx, wy, 3.2, white))
    for rad in (9, 16, 23):
        pts = [(wx + rad * math.cos(math.radians(a)), wy + rad * math.sin(math.radians(a))) for a in range(-135, -44, 9)]
        for (x1, y1), (x2, y2) in zip(pts, pts[1:]):
            sh.append(("line", x1, y1, x2, y2, 3.4, white))
    return sh


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def render(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))

    def draw(shapes):
        for sh in shapes:
            if sh[0] == "rrect":
                _, x1, y1, x2, y2, rad, c = sh
                d.rounded_rectangle(r(x1 + OX, y1 + OY, x2 + OX, y2 + OY), radius=rad * k, fill=c)
            elif sh[0] == "circle":
                _, cx, cy, rr, c = sh
                d.ellipse(r(cx - rr + OX, cy - rr + OY, cx + rr + OX, cy + rr + OY), fill=c)
            elif sh[0] == "ellipse":
                _, cx, cy, rx, ry, c = sh
                d.ellipse(r(cx - rx + OX, cy - ry + OY, cx + rx + OX, cy + ry + OY), fill=c)
            else:
                _, x1, y1, x2, y2, w, c = sh
                d.line([((x1 + OX) * k, (y1 + OY) * k), ((x2 + OX) * k, (y2 + OY) * k)], fill=c, width=max(1, int(w * k)))

    draw(switch_shapes())
    draw(miniserver_shapes())
    # Verbindung mit Pfeilspitze (Switch -> Miniserver)
    ly = (LINK_Y + OY) * k
    d.line([((LINK_X[0] + OX) * k, ly), ((LINK_X[1] - 4 + OX) * k, ly)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8 + OX) * k, ly), ((LINK_X[1] - 6 + OX) * k, ly - 11 * k),
               ((LINK_X[1] - 6 + OX) * k, ly + 11 * k)], fill="white")
    # Beschriftung: Name in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, (LABEL_BASE + OY) * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "unifi2lox", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    render(512).save(os.path.join(logo_dir, "unifi2lox.png"))
    for s in (64, 128, 256, 512):
        render(s).save(os.path.join(icon_dir, "icon_%d.png" % s))
    print("Logo und Icons geschrieben")
