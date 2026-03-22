#!/usr/bin/env python3
"""Build gzip-compressed emoji TLF (default: fonts/emoji.tlf) with optimized SGR encoding.

Optimizations over the original builder:
  1. needs_256_slot  — skip 38;5;N when the color is already exact in base-16
  2. Delta encoding  — emit only changed attributes (no full reset between cells)
  3. Reverse toggle  — use SGR 7/27 when fg↔bg swap
  4. Color normalize — canonicalize palette indices (prefer <16 when RGB matches)
  5. Zopfli compress — optional, better gzip via `pip install zopfli`
  6. Trailing decolor — strip color from trailing invisible black cells
  7. No leading 0;   — skip redundant reset prefix in first SGR per line

Usage:
  python3 tools/build_emoji_font.py [--max N] [--output path] [--cache-dir DIR]
                                     [--truecolor]
                                     [--truecolor-for U+XXXX ...] [--truecolor-codepoints LIST]

SGR / compact encoding: see tools/TLF_COLOR_EXTENSIONS.md.
"""
from __future__ import annotations

import argparse
import gzip
import os
import re
import subprocess
import sys
import unicodedata as ud
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

try:
    import zopfli.gzip as zopfli_gzip

    HAS_ZOPFLI = True
except ImportError:
    HAS_ZOPFLI = False

GLYPH_HEIGHT = 6
GLYPH_WIDTH = 12
EMOJI_PX = 64
CANVAS_PX = EMOJI_PX + 8
COMPACT_OFFSET = 256
TRUECOLOR_OFFSET = 512
INTERNAL_TRUECOLOR_OFFSET = 1 << 24

_SCRIPT_DIR = Path(__file__).resolve().parent
_candidate_root = _SCRIPT_DIR.parent
if (_candidate_root / "composer.json").is_file():
    REPO_ROOT = _candidate_root
else:
    _env = os.environ.get("TEXT_FIGLET_ROOT", "").strip()
    if not _env:
        print("Set TEXT_FIGLET_ROOT or run from repo root.", file=sys.stderr)
        sys.exit(1)
    REPO_ROOT = Path(_env).resolve()
    if not (REPO_ROOT / "composer.json").is_file():
        print(f"Not a Text_Figlet root: {REPO_ROOT}", file=sys.stderr)
        sys.exit(1)

FONTS_DIR = REPO_ROOT / "fonts"
DEFAULT_CACHE = _SCRIPT_DIR / "emoji_render_cache"

# ── Emoji codepoint collection ──────────────────────────────────────

BMP_EMOJI_SINGLETONS: tuple[int, ...] = (
    0x203C,
    0x2049,
    0x2122,
    0x2139,
    *range(0x2194, 0x219A),
    0x21A9,
    0x21AA,
    0x231A,
    0x231B,
    0x2328,
    0x23CF,
    *range(0x23E9, 0x23F4),
    *range(0x23F8, 0x23FB),
    0x24C2,
    0x25AA,
    0x25AB,
    0x25B6,
    0x25C0,
    *range(0x25FB, 0x25FF),
    0x2934,
    0x2935,
    *range(0x2B05, 0x2B08),
    0x2B1B,
    0x2B1C,
    0x2B50,
    0x2B55,
    0x3030,
    0x303D,
    0x3297,
    0x3299,
)

EMOJI_SCAN_RANGES: tuple[tuple[int, int], ...] = (
    (0x1F300, 0x1F5FF),
    (0x1F600, 0x1F64F),
    (0x1F680, 0x1F6FF),
    (0x1F900, 0x1F9FF),
    (0x1FA00, 0x1FAFF),
    (0x2600, 0x26FF),
    (0x2700, 0x27BF),
    (0x2300, 0x23FF),
)


def _assigned(cp: int) -> bool:
    try:
        return ud.name(chr(cp), "") != ""
    except ValueError:
        return False


def collect_emoji_codepoints() -> list[int]:
    codes: set[int] = set(BMP_EMOJI_SINGLETONS)
    for lo, hi in EMOJI_SCAN_RANGES:
        for cp in range(lo, hi + 1):
            if _assigned(cp):
                codes.add(cp)
    return sorted(codes)


def parse_codepoint_token(tok: str) -> int:
    """Parse U+1F600, 0x1F600, 1F600, or decimal."""
    t = tok.strip()
    if not t:
        raise ValueError("empty codepoint token")
    u = t.upper()
    if u.startswith("U+"):
        return int(u[2:], 16)
    if t.lower().startswith("0x"):
        return int(t, 16)
    if t.isdigit():
        return int(t)
    return int(t, 16)


def parse_truecolor_codepoint_specs(
    for_tokens: list[str] | None,
    comma_list: str | None,
) -> set[int]:
    out: set[int] = set()
    if for_tokens:
        for spec in for_tokens:
            out.add(parse_codepoint_token(spec))
    if comma_list:
        for part in comma_list.split(","):
            part = part.strip()
            if part:
                out.add(parse_codepoint_token(part))
    return out


# ── 16-color / 256-color helpers ─────────────────────────────────────

BASE16_RGB: list[tuple[int, int, int]] = [
    (0, 0, 0), (170, 0, 0), (0, 170, 0), (170, 85, 0),
    (0, 0, 170), (170, 0, 170), (0, 170, 170), (170, 170, 170),
    (85, 85, 85), (255, 85, 85), (85, 255, 85), (255, 255, 85),
    (85, 85, 255), (255, 85, 255), (85, 255, 255), (255, 255, 255),
]

_BASE16_RGB_TO_IDX: dict[tuple[int, int, int], int] = {
    rgb: i for i, rgb in enumerate(BASE16_RGB)
}

_nearest_cache: dict[int, int] = {}
_nearest_256_cache: dict[int, int] = {}
_canonical_cache: dict[int, int] = {}


def ansi256_to_rgb(idx: int) -> tuple[int, int, int]:
    if idx < 16:
        return BASE16_RGB[idx]
    if idx < 232:
        i = idx - 16
        to_val = lambda lv: 0 if lv == 0 else 55 + 40 * lv
        return (to_val(i // 36), to_val((i % 36) // 6), to_val(i % 6))
    gray = 8 + 10 * (idx - 232)
    return (gray, gray, gray)


ANSI256_RGB: list[tuple[int, int, int]] = [ansi256_to_rgb(i) for i in range(256)]
_ANSI256_RGB_TO_IDX: dict[tuple[int, int, int], int] = {
    rgb: i for i, rgb in enumerate(ANSI256_RGB)
}


def is_truecolor(code: int) -> bool:
    return code >= INTERNAL_TRUECOLOR_OFFSET


def rgb24_to_truecolor(r: int, g: int, b: int) -> int:
    return INTERNAL_TRUECOLOR_OFFSET + ((b << 16) | (g << 8) | r)


def truecolor_to_rgb(code: int) -> tuple[int, int, int]:
    bgr = code - INTERNAL_TRUECOLOR_OFFSET
    return (bgr & 0xFF, (bgr >> 8) & 0xFF, (bgr >> 16) & 0xFF)


def color_to_rgb(code: int) -> tuple[int, int, int]:
    return truecolor_to_rgb(code) if is_truecolor(code) else ansi256_to_rgb(code)


def _srgb_to_linear(c: int) -> float:
    v = c / 255.0
    return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4


def _rgb_to_lab(r: int, g: int, b: int) -> tuple[float, float, float]:
    rl, gl, bl = _srgb_to_linear(r), _srgb_to_linear(g), _srgb_to_linear(b)
    x = 0.4124564 * rl + 0.3575761 * gl + 0.1804375 * bl
    y = 0.2126729 * rl + 0.7151522 * gl + 0.0721750 * bl
    z = 0.0193339 * rl + 0.1191920 * gl + 0.9503041 * bl
    xn, yn, zn = 0.95047, 1.0, 1.08883

    def f(t: float) -> float:
        return t ** (1 / 3) if t > 0.008856 else 7.787 * t + 16 / 116

    fx, fy, fz = f(x / xn), f(y / yn), f(z / zn)
    return 116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)


_BASE16_LAB = [_rgb_to_lab(*c) for c in BASE16_RGB]


_color_metric = "lab"


def set_color_metric(metric: str) -> None:
    global _color_metric
    _color_metric = metric
    _nearest_cache.clear()


def _lab_chroma(lab: tuple[float, float, float]) -> float:
    return (lab[1] ** 2 + lab[2] ** 2) ** 0.5


def nearest_16(idx: int) -> int:
    if idx < 16:
        return idx
    cached = _nearest_cache.get(idx)
    if cached is not None:
        return cached

    rgb = color_to_rgb(idx)

    if _color_metric == "rgb":
        best = min(
            range(16),
            key=lambda i: sum((a - b) ** 2 for a, b in zip(rgb, BASE16_RGB[i])),
        )
    elif _color_metric == "lab-chroma":
        import math

        lab = _rgb_to_lab(*rgb)
        src_chroma = _lab_chroma(lab)
        src_hue = math.atan2(lab[2], lab[1]) if src_chroma > 5 else 0.0

        def dist(i: int) -> float:
            d = sum((a - b) ** 2 for a, b in zip(lab, _BASE16_LAB[i]))
            tgt = _BASE16_LAB[i]
            tgt_chroma = _lab_chroma(tgt)
            if src_chroma <= 5:
                return d
            if tgt_chroma < 5:
                return d + (src_chroma * 4.0) ** 2
            tgt_hue = math.atan2(tgt[2], tgt[1])
            dh = abs(src_hue - tgt_hue)
            if dh > math.pi:
                dh = 2 * math.pi - dh
            hue_penalty = src_chroma * 5.0 * math.sin(dh / 2)
            return d + hue_penalty ** 2

        best = min(range(16), key=dist)
    else:
        lab = _rgb_to_lab(*rgb)
        best = min(
            range(16),
            key=lambda i: sum((a - b) ** 2 for a, b in zip(lab, _BASE16_LAB[i])),
        )

    _nearest_cache[idx] = best
    return best


def nearest_256(code: int) -> int:
    if not is_truecolor(code):
        return code
    cached = _nearest_256_cache.get(code)
    if cached is not None:
        return cached
    rgb = truecolor_to_rgb(code)
    best = min(
        range(256),
        key=lambda i: sum((a - b) ** 2 for a, b in zip(rgb, ANSI256_RGB[i])),
    )
    _nearest_256_cache[code] = best
    return best


def canonical_color(idx: int) -> int:
    """Prefer the most compact exact representation for a color."""
    if idx < 16:
        return idx
    cached = _canonical_cache.get(idx)
    if cached is not None:
        return cached
    rgb = color_to_rgb(idx)
    base = _BASE16_RGB_TO_IDX.get(rgb)
    if base is not None:
        result = base
    else:
        palette = _ANSI256_RGB_TO_IDX.get(rgb)
        result = palette if palette is not None else idx
    _canonical_cache[idx] = result
    return result


def needs_256_slot(idx: int) -> bool:
    """False when a 256-palette index already has an exact base-16 match."""
    return idx >= 16 and not is_truecolor(idx) and ansi256_to_rgb(idx) not in _BASE16_RGB_TO_IDX


def needs_truecolor_slot(idx: int) -> bool:
    return is_truecolor(idx)


def fg_sgr16(color: int) -> str:
    return str(30 + color) if color < 8 else str(90 + color - 8)


def bg_sgr16(color: int) -> str:
    return str(40 + color) if color < 8 else str(100 + color - 8)

# ── ANSI parsing (chafa output → cells) ─────────────────────────────


def _apply_sgr(
    params: str, fg: int | None, bg: int | None, rev: bool,
) -> tuple[int | None, int | None, bool]:
    if params == "" or params == "0":
        return None, None, False
    codes = [int(x) for x in params.split(";") if x]
    i = 0
    while i < len(codes):
        c = codes[i]
        if c == 0:
            fg = bg = None
            rev = False
        elif c == 7:
            rev = True
        elif c == 27:
            rev = False
        elif c == 38 and i + 2 < len(codes) and codes[i + 1] == 5:
            fg = codes[i + 2]
            i += 2
        elif c == 38 and i + 4 < len(codes) and codes[i + 1] == 2:
            fg = rgb24_to_truecolor(codes[i + 2], codes[i + 3], codes[i + 4])
            i += 4
        elif c == 48 and i + 2 < len(codes) and codes[i + 1] == 5:
            bg = codes[i + 2]
            i += 2
        elif c == 48 and i + 4 < len(codes) and codes[i + 1] == 2:
            bg = rgb24_to_truecolor(codes[i + 2], codes[i + 3], codes[i + 4])
            i += 4
        elif 30 <= c <= 37:
            fg = c - 30
        elif 40 <= c <= 47:
            bg = c - 40
        elif 90 <= c <= 97:
            fg = c - 90 + 8
        elif 100 <= c <= 107:
            bg = c - 100 + 8
        i += 1
    return fg, bg, rev


def parse_cells(line: str) -> list[tuple[str, int | None, int | None]]:
    """Parse chafa ANSI line → list of (char, eff_fg, eff_bg) with normalized colors."""
    cells: list[tuple[str, int | None, int | None]] = []
    fg: int | None = None
    bg: int | None = None
    rev = False
    i = 0
    while i < len(line):
        if line[i] == "\x1b" and i + 1 < len(line) and line[i + 1] == "[":
            j = i + 2
            while j < len(line) and line[j] not in "mABCDHJKSTfnsulhr":
                j += 1
            if j < len(line) and line[j] == "m":
                params = line[i + 2 : j]
                if "?" not in params:
                    fg, bg, rev = _apply_sgr(params, fg, bg, rev)
            i = j + 1
        else:
            eff_fg = bg if rev else fg
            eff_bg = fg if rev else bg
            if eff_fg is not None:
                eff_fg = canonical_color(eff_fg)
            if eff_bg is not None:
                eff_bg = canonical_color(eff_bg)
            cells.append((line[i], eff_fg, eff_bg))
            i += 1
    return cells

# ── Optimized SGR encoder ───────────────────────────────────────────

_stat_toggles = 0
_stat_deltas = 0
_stat_resets = 0
_stat_slots_saved = 0


def _emit_color_parts(parts: list[str], fg: int | None, bg: int | None) -> None:
    """Append 16-color + optional compact 256-color or truecolor codes.

    Context-based compact encoding (channel determined by preceding 16-color code):
      256-color → 256+N (range 256..511)
      truecolor → 512+rgb24 (range 512..16,777,727)
    """
    global _stat_slots_saved
    if fg is not None:
        parts.append(fg_sgr16(nearest_16(fg)))
        if needs_256_slot(fg):
            parts.append(str(nearest_256(fg) + COMPACT_OFFSET))
        else:
            _stat_slots_saved += 1
        if needs_truecolor_slot(fg):
            parts.append(str(TRUECOLOR_OFFSET + (fg - INTERNAL_TRUECOLOR_OFFSET)))
    if bg is not None:
        parts.append(bg_sgr16(nearest_16(bg)))
        if needs_256_slot(bg):
            parts.append(str(nearest_256(bg) + COMPACT_OFFSET))
        else:
            _stat_slots_saved += 1
        if needs_truecolor_slot(bg):
            parts.append(str(TRUECOLOR_OFFSET + (bg - INTERNAL_TRUECOLOR_OFFSET)))


def encode_optimized(cells: list[tuple[str, int | None, int | None]]) -> str:
    global _stat_toggles, _stat_deltas, _stat_resets

    result = ""
    set_fg: int | None = None
    set_bg: int | None = None
    is_neg = False
    first = True

    for char, want_fg, want_bg in cells:
        cur_fg = set_bg if is_neg else set_fg
        cur_bg = set_fg if is_neg else set_bg

        if want_fg != cur_fg or want_bg != cur_bg:
            if want_fg is None and want_bg is None:
                result += "\x1b[m"
                set_fg = set_bg = None
                is_neg = False
                first = True

            else:
                can_toggle = False
                if not first and set_fg is not None and set_bg is not None:
                    tog_fg = set_fg if is_neg else set_bg
                    tog_bg = set_bg if is_neg else set_fg
                    can_toggle = (want_fg == tog_fg and want_bg == tog_bg)

                if can_toggle:
                    result += "\x1b[" + ("27" if is_neg else "7") + "m"
                    is_neg = not is_neg
                    _stat_toggles += 1

                elif first:
                    p: list[str] = []
                    _emit_color_parts(p, want_fg, want_bg)
                    result += "\x1b[" + ";".join(p) + "m"
                    set_fg = want_fg
                    set_bg = want_bg
                    is_neg = False
                    first = False
                    _stat_resets += 1

                else:
                    p = []
                    if is_neg:
                        p.append("27")
                        is_neg = False
                        cur_fg = set_fg
                        cur_bg = set_bg

                    if want_fg != cur_fg and want_fg is not None:
                        _emit_color_parts(p, want_fg, None)
                    if want_bg != cur_bg and want_bg is not None:
                        _emit_color_parts(p, None, want_bg)

                    if p:
                        result += "\x1b[" + ";".join(p) + "m"
                    set_fg = want_fg
                    set_bg = want_bg
                    _stat_deltas += 1

        result += char

    if set_fg is not None or set_bg is not None or is_neg:
        result += "\x1b[m"
    return result


_stat_trimmed = 0


def optimize_cells(
    cells: list[tuple[str, int | None, int | None]],
) -> list[tuple[str, int | None, int | None]]:
    """Decolorize trailing invisible cells (space with black/no bg).

    Converts trailing colored-but-invisible cells to colorless spaces,
    saving SGR overhead in the encoder (single reset vs per-cell codes).
    """
    global _stat_trimmed
    result = list(cells)
    i = len(result) - 1
    while i >= 0 and result[i][0] == " " and result[i][2] in (None, 0):
        i -= 1
    for j in range(i + 1, len(result)):
        if result[j][1] is not None or result[j][2] is not None:
            result[j] = (" ", None, None)
            _stat_trimmed += 1
    return result


def rewrite_optimized(lines: list[str]) -> list[str]:
    return [encode_optimized(optimize_cells(parse_cells(line))) for line in lines]

# ── Chafa / Pillow pipeline ─────────────────────────────────────────


_CURSOR_RE = re.compile(r"\x1b\[\?[0-9;]*[a-zA-Z]")


def chafa_to_ansi(png_path: Path, *, truecolor: bool = False) -> list[str] | None:
    result = subprocess.run(
        [
            "chafa", "--symbols", "half",
            "--colors", "full" if truecolor else "256", "--color-space", "din99d",
            "--work", "9", "--bg", "000000",
            "--size", f"{GLYPH_WIDTH}x{GLYPH_HEIGHT}",
            "--animate=off", "--optimize=9",
            str(png_path),
        ],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        return None
    text = _CURSOR_RE.sub("", result.stdout)
    lines = text.rstrip("\n").split("\n")
    while len(lines) < GLYPH_HEIGHT:
        lines.append("")
    return lines[:GLYPH_HEIGHT]


def render_png(codepoint: int, path: Path, font: ImageFont.FreeTypeFont) -> None:
    if path.is_file():
        return
    rgba = Image.new("RGBA", (CANVAS_PX, CANVAS_PX), (0, 0, 0, 0))
    draw = ImageDraw.Draw(rgba)
    draw.text((4, 4), chr(codepoint), font=font, embedded_color=True)
    bbox = rgba.getbbox()
    if bbox:
        rgba = rgba.crop(bbox)
    bg = Image.new("RGB", rgba.size, (0, 0, 0))
    bg.paste(rgba, mask=rgba.split()[3])
    bg.save(str(path))


def find_emoji_font() -> str:
    candidates = [
        "/System/Library/Fonts/Apple Color Emoji.ttc",
        "/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf",
        "/usr/share/fonts/google-noto-emoji/NotoColorEmoji.ttf",
    ]
    for p in candidates:
        if os.path.isfile(p):
            return p
    print("No emoji font found. Checked:", file=sys.stderr)
    for p in candidates:
        print(f"  {p}", file=sys.stderr)
    sys.exit(1)


# ── TLF builder ─────────────────────────────────────────────────────


def build_tlf(glyphs: dict[int, list[str]], comments: list[str]) -> str:
    max_line = max(
        (len(line.encode("utf-8")) + 2 for lines in glyphs.values() for line in lines),
        default=80,
    )
    header_parts = [
        "tlf2a$",
        str(GLYPH_HEIGHT),
        str(GLYPH_HEIGHT - 1),
        str(max_line),
        "-1",
        str(len(comments)),
        "0", "0", "0",
    ]
    parts: list[str] = [" ".join(header_parts)]
    for c in comments:
        parts.append(c)
    empty_line = " " * (GLYPH_WIDTH // 2)
    for _code in range(32, 127):
        for row in range(GLYPH_HEIGHT):
            parts.append(empty_line + ("@@" if row == GLYPH_HEIGHT - 1 else "@"))
    for _ in range(7):
        for row in range(GLYPH_HEIGHT):
            parts.append(empty_line + ("@@" if row == GLYPH_HEIGHT - 1 else "@"))
    for cp in sorted(glyphs):
        parts.append(f"0x{cp:04X}")
        for row in range(GLYPH_HEIGHT):
            ln = glyphs[cp][row] if row < len(glyphs[cp]) else ""
            parts.append(ln + ("@@" if row == GLYPH_HEIGHT - 1 else "@"))
    return "\n".join(parts) + "\n"


def compress(data: bytes, *, use_zopfli: bool = True) -> bytes:
    if use_zopfli and HAS_ZOPFLI:
        raw = zopfli_gzip.compress(data)
        if len(raw) >= 10:
            raw = raw[:4] + (0).to_bytes(4, "little") + raw[8:]
        return raw
    raw = gzip.compress(data, compresslevel=9, mtime=0)
    if len(raw) >= 10:
        raw = raw[:4] + (0).to_bytes(4, "little") + raw[8:]
    return raw

# ── Main ────────────────────────────────────────────────────────────


def main() -> None:
    global _stat_toggles, _stat_deltas, _stat_resets, _stat_slots_saved, _stat_trimmed

    ap = argparse.ArgumentParser(description="Build optimized emoji TLF font")
    ap.add_argument("--max", type=int, default=0, help="Limit codepoints (0 = all)")
    ap.add_argument("--output", type=Path, default=FONTS_DIR / "emoji.tlf")
    ap.add_argument("--cache-dir", type=Path, default=DEFAULT_CACHE)
    ap.add_argument("--no-zopfli", action="store_true", help="Disable zopfli even if installed")
    ap.add_argument(
        "--truecolor",
        action="store_true",
        help="Run chafa in full color for every glyph (256-color mode off)",
    )
    ap.add_argument(
        "--truecolor-for",
        action="append",
        metavar="CODEPOINT",
        help=(
            "In default 256-color mode, run chafa in truecolor for this codepoint "
            "(repeat or use --truecolor-codepoints). Forms: U+1F600, 0x1F600, 1F600"
        ),
    )
    ap.add_argument(
        "--truecolor-codepoints",
        metavar="LIST",
        help="Comma-separated codepoints for per-glyph truecolor (same forms as --truecolor-for)",
    )
    ap.add_argument(
        "--color-metric", choices=["rgb", "lab", "lab-chroma"], default="lab-chroma",
        help="16-color mapping: rgb (Euclidean), lab (CIE L*a*b*), lab-chroma (Lab + chroma penalty)",
    )
    args = ap.parse_args()
    use_zopfli = HAS_ZOPFLI and not args.no_zopfli
    set_color_metric(args.color_metric)

    truecolor_override = parse_truecolor_codepoint_specs(
        args.truecolor_for,
        args.truecolor_codepoints,
    )
    if truecolor_override and args.truecolor:
        print(
            "Note: --truecolor applies to all glyphs; --truecolor-for / "
            "--truecolor-codepoints are redundant.",
            file=sys.stderr,
        )

    codepoints = collect_emoji_codepoints()
    if args.max > 0:
        codepoints = codepoints[: args.max]

    if truecolor_override:
        in_build = set(codepoints)
        missing = sorted(truecolor_override - in_build)
        if missing:
            print(
                "WARN: --truecolor-for / --truecolor-codepoints not in current build: "
                + ", ".join(f"U+{c:04X}" for c in missing),
                file=sys.stderr,
            )

    font_path = find_emoji_font()
    args.cache_dir.mkdir(parents=True, exist_ok=True)
    FONTS_DIR.mkdir(parents=True, exist_ok=True)

    compressor = "zopfli" if use_zopfli else "gzip-9"
    print(f"Pillow font: {font_path}")
    print(f"Codepoints: {len(codepoints)}, cache: {args.cache_dir}, compress: {compressor}")
    if truecolor_override and not args.truecolor:
        print(
            f"Per-glyph truecolor (chafa full): {len(truecolor_override)} codepoint(s)",
            file=sys.stderr,
        )
    font = ImageFont.truetype(font_path, EMOJI_PX)

    _stat_toggles = _stat_deltas = _stat_resets = _stat_slots_saved = 0
    _stat_trimmed = 0

    glyphs: dict[int, list[str]] = {}
    for cp in codepoints:
        png = args.cache_dir / f"{cp:04x}.png"
        render_png(cp, png, font)
        use_truecolor = args.truecolor or cp in truecolor_override
        lines = chafa_to_ansi(png, truecolor=use_truecolor)
        if lines is None:
            print(f"  WARN chafa U+{cp:04X}")
            continue
        glyphs[cp] = rewrite_optimized(lines)

    license_block = [
        "", "MIT License", "",
        "Copyright (c) 2026 Evgeny Stepanischev", "",
        "Permission is hereby granted, free of charge, to any person obtaining",
        "a copy of this font, to deal in the font without restriction,",
        "including without limitation the rights to use, copy, modify, merge,",
        "publish, distribute, sublicense, and/or sell copies of the font.", "",
        'THE FONT IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.',
    ]
    out_name = args.output.name
    hybrid_note = ""
    tc_sorted = sorted(truecolor_override)
    if truecolor_override and not args.truecolor:
        if len(tc_sorted) <= 12:
            hybrid_note = "+hybrid256+tc(" + ",".join(f"U+{c:04X}" for c in tc_sorted) + ")"
        else:
            hybrid_note = f"+hybrid256+tc({len(tc_sorted)}cps)"

    tc_rebuild = ""
    if truecolor_override and not args.truecolor:
        if len(tc_sorted) <= 4:
            tc_rebuild = " ".join(f" --truecolor-for U+{c:04X}" for c in tc_sorted)
        else:
            tc_rebuild = " --truecolor-codepoints " + ",".join(f"U+{c:04X}" for c in tc_sorted)

    comments = [
        f"{out_name} - optimized encoding (reverse+delta+needs_256_slot+decolor+no_0"
        + ("+truecolor" if args.truecolor else "")
        + hybrid_note
        + ")",
        "",
        "Build pipeline tag: python-pillow.",
        "Rebuild: python3 tools/build_emoji_font.py"
        + (" --truecolor" if args.truecolor else "")
        + tc_rebuild
        + (f" --output {out_name}" if out_name != "emoji.tlf" else ""),
        *license_block,
    ]

    plain = build_tlf(glyphs, comments)
    raw_bytes = plain.encode("utf-8")
    gz = compress(raw_bytes, use_zopfli=use_zopfli)
    args.output.write_bytes(gz)

    raw_sz = len(raw_bytes)
    gz_sz = len(gz)
    total_sgr = _stat_toggles + _stat_deltas + _stat_resets

    print(f"\n{'=' * 60}")
    print(f"  Glyphs: {len(glyphs)}")
    print(f"{'=' * 60}")
    print(f"  Output:   {raw_sz:>10,} raw  {gz_sz:>10,} gzip ({compressor})")

    for ref_name in ("emoji.tlf",):
        ref = FONTS_DIR / ref_name
        if ref.is_file() and ref != args.output:
            ref_sz = ref.stat().st_size
            diff = ref_sz - gz_sz
            pct = diff / ref_sz * 100 if ref_sz else 0
            print(f"  vs {ref_name:<16s} {ref_sz:>10,} gzip  →  {diff:>+,} ({pct:+.1f}%)")

    print(f"{'─' * 60}")
    print(f"  SGR sequences:  {total_sgr:>8,}")
    if total_sgr:
        print(f"    reverse toggle: {_stat_toggles:>6,}  ({_stat_toggles / total_sgr * 100:.1f}%)")
        print(f"    delta update:   {_stat_deltas:>6,}  ({_stat_deltas / total_sgr * 100:.1f}%)")
        print(f"    full reset:     {_stat_resets:>6,}  ({_stat_resets / total_sgr * 100:.1f}%)")
    print(f"  256-slots saved:  {_stat_slots_saved:>6,}  (used 16-color instead)")
    print(f"  trailing decolor: {_stat_trimmed:>6,}  (invisible cells stripped)")
    print(f"{'=' * 60}")
    print(f"  → {args.output}")


if __name__ == "__main__":
    main()