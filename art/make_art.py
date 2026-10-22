"""Generate the XLSX Import art set (SVG) in the shared amjadiqbal template.

Usage: python3 -I make_art.py   (writes SVGs next to this file; render.sh makes the PNGs)
No brown: the only accent is spreadsheet green; no warm colours anywhere.
"""
import os

HERE = os.path.dirname(os.path.abspath(__file__))
FONT = "Helvetica, Arial, sans-serif"
PKG = "amjadiqbal/filament-xlsx-import"
TITLE = "XLSX Import"
TAGLINE = "Excel files in Filament's own ImportAction"
CHIPS = [".xlsx · .ods · .csv", "your Importers, unchanged", "Filament 4 · 5"]

THEMES = {
    "light": dict(bg=("#F8FAFC", "#ECFDF5"), title="#0F172A", accent="#047857", chip_fill="#FFFFFF",
                  chip_stroke="#E2E8F0", chip_text="#334155", card="#FFFFFF", card_stroke="#E2E8F0",
                  muted="#64748B", body="#0F172A", head_fill="#ECFDF5", head_text="#065F46", pkg="#94A3B8",
                  ok="#059669"),
    "dark": dict(bg=("#0B1220", "#0B2A22"), title="#F8FAFC", accent="#6EE7B7", chip_fill="#111C33",
                 chip_stroke="#273449", chip_text="#CBD5E1", card="#111C33", card_stroke="#273449",
                 muted="#94A3B8", body="#F8FAFC", head_fill="#0F2E26", head_text="#6EE7B7", pkg="#64748B",
                 ok="#34D399"),
}


def icon_body():
    """The 512x512 mark: a spreadsheet sheet with a green header row, and an arrow importing it."""
    return """
    <rect width="512" height="512" rx="112" fill="url(#iconbg)"/>
    <!-- sheet -->
    <rect x="92" y="96" width="268" height="300" rx="26" fill="#F8FAFC"/>
    <path d="M92 122 Q92 96 118 96 H334 Q360 96 360 122 V158 H92 Z" fill="#10B981"/>
    <line x1="182" y1="96" x2="182" y2="396" stroke="#CBD5E1" stroke-width="6"/>
    <line x1="272" y1="96" x2="272" y2="396" stroke="#CBD5E1" stroke-width="6"/>
    <line x1="92" y1="218" x2="360" y2="218" stroke="#CBD5E1" stroke-width="6"/>
    <line x1="92" y1="278" x2="360" y2="278" stroke="#CBD5E1" stroke-width="6"/>
    <line x1="92" y1="338" x2="360" y2="338" stroke="#CBD5E1" stroke-width="6"/>
    <rect x="110" y="178" width="54" height="20" rx="10" fill="#94A3B8"/>
    <rect x="200" y="178" width="54" height="20" rx="10" fill="#94A3B8"/>
    <rect x="110" y="238" width="54" height="20" rx="10" fill="#94A3B8"/>
    <rect x="200" y="238" width="40" height="20" rx="10" fill="#94A3B8"/>
    <!-- X mark on the header -->
    <g stroke="#FFFFFF" stroke-width="12" stroke-linecap="round"><line x1="122" y1="112" x2="152" y2="142"/><line x1="152" y1="112" x2="122" y2="142"/></g>
    <!-- import badge: arrow into a tray -->
    <circle cx="372" cy="372" r="86" fill="#059669" stroke="#0F172A" stroke-width="14"/>
    <g fill="none" stroke="#FFFFFF" stroke-width="16" stroke-linecap="round" stroke-linejoin="round">
      <path d="M372 320 V392"/><path d="M342 364 L372 394 L402 364"/><path d="M330 412 H414"/>
    </g>
"""


def defs(t):
    return f"""  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{t['bg'][0]}"/>
      <stop offset="100%" stop-color="{t['bg'][1]}"/>
    </linearGradient>
    <linearGradient id="iconbg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#064E3B"/>
    </linearGradient>
  </defs>"""


def chips(t, x, y, size=15, h=32, gap=10, anchor_center=False):
    widths = [round(len(c) * size * 0.56 + 34) for c in CHIPS]
    if anchor_center:
        x = x - (sum(widths) + gap * (len(widths) - 1)) / 2
    out = [f'  <g font-family="{FONT}" font-size="{size}" font-weight="600" fill="{t["chip_text"]}" text-anchor="middle">']
    for c, w in zip(CHIPS, widths):
        out.append(f'<g transform="translate({x:.0f},{y})"><rect width="{w}" height="{h}" rx="{h/2}" fill="{t["chip_fill"]}" stroke="{t["chip_stroke"]}"/>'
                   f'<text x="{w/2}" y="{h*0.66:.2f}">{c}</text></g>')
        x += w + gap
    out.append("  </g>")
    return "\n".join(out)


MAP = [("Name", "name"), ("Email", "email"), ("Joined", "created_at")]


def map_card(t, x, y, s=1.0):
    """A miniature import modal: file row with sheet name, then column mapping rows."""
    h = 92 + 40 * len(MAP)
    out = [f'  <g transform="translate({x},{y}) scale({s})" font-family="{FONT}">',
           f'    <rect width="300" height="{h}" rx="16" fill="{t["card"]}" stroke="{t["card_stroke"]}" stroke-width="1.5"/>',
           f'    <rect x="16" y="16" width="268" height="38" rx="10" fill="{t["head_fill"]}"/>',
           f'    <text x="30" y="40" font-size="13" font-weight="700" fill="{t["head_text"]}">customers.xlsx</text>',
           f'    <text x="270" y="40" font-size="12" font-weight="600" fill="{t["muted"]}" text-anchor="end">Sheet: 2024 ▾</text>',
           f'    <text x="18" y="80" font-size="11" font-weight="700" fill="{t["muted"]}" letter-spacing="0.6">COLUMNS</text>']
    for i, (col, field) in enumerate(MAP):
        ry = 90 + i * 40
        out.append(f'    <line x1="0" y1="{ry}" x2="300" y2="{ry}" stroke="{t["card_stroke"]}"/>')
        out.append(f'    <text x="18" y="{ry+25}" font-size="13" font-weight="700" fill="{t["body"]}">{field}</text>')
        out.append(f'    <text x="150" y="{ry+25}" font-size="13" fill="{t["muted"]}">← {col}</text>')
        out.append(f'    <g transform="translate(262 {ry+10}) scale(0.85)" fill="none" stroke="{t["ok"]}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'
                   '<path d="M4 12l5 5L20 6"/></g>')
    out.append("  </g>")
    return "\n".join(out)


def svg(w, h, body, t):
    return f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">\n{defs(t)}\n  <rect width="{w}" height="{h}" fill="url(#bg)"/>\n{body}\n</svg>\n'


def banner(name):
    t = THEMES[name]
    body = f"""  <g transform="translate(60,75) scale(0.29297)">{icon_body()}</g>
  <text x="235" y="148" font-family="{FONT}" font-size="64" font-weight="700" fill="{t['title']}">{TITLE}</text>
  <text x="238" y="190" font-family="{FONT}" font-size="23" font-weight="500" fill="{t['accent']}" letter-spacing="0.3">{TAGLINE}</text>
{chips(t, 238, 218)}
{map_card(t, 905, 30, s=0.82)}
  <text x="1170" y="284" text-anchor="end" font-family="{FONT}" font-size="15" fill="{t['pkg']}">{PKG}</text>"""
    return svg(1200, 300, body, t)


def social():
    t = THEMES["dark"]
    body = f"""  <g transform="translate(490,50) scale(0.5859)">{icon_body()}</g>
  <text x="640" y="430" text-anchor="middle" font-family="{FONT}" font-size="76" font-weight="700" fill="{t['title']}">{TITLE}</text>
  <text x="640" y="482" text-anchor="middle" font-family="{FONT}" font-size="28" font-weight="500" fill="{t['accent']}">Import .xlsx and .ods with Filament's core ImportAction</text>
{chips(t, 640, 530, size=18, h=40, gap=14, anchor_center=True)}
  <text x="1240" y="610" text-anchor="end" font-family="{FONT}" font-size="18" fill="{t['pkg']}">{PKG}</text>"""
    return svg(1280, 640, body, t)


def icon():
    return f'<svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">\n{defs(THEMES["dark"])}\n<g>{icon_body()}</g>\n</svg>\n'


if __name__ == "__main__":
    files = {"icon.svg": icon(), "banner-light.svg": banner("light"), "banner-dark.svg": banner("dark"),
             "social-preview.svg": social()}
    for f, s in files.items():
        with open(os.path.join(HERE, f), "w") as fh:
            fh.write(s)
        print("wrote", f)
