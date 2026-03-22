# TLF color extensions (256-color and truecolor)

Text_Figlet extends the usual TOIlet TLF color model (16-color SGR) with
**compact 256-color** and **compact truecolor** codes inside glyph-line ANSI
sequences. Extensions stay compatible with [TOIlet](http://caca.zoy.org/wiki/toilet)
(libcaca): TOIlet applies the base-16 codes and silently skips unknown numeric
parameters.

The bundled **emoji** fonts (`emoji.tlf`, `emoji-truecolor.tlf`) are the main
reference fonts that use this encoding; they are built with
`build_emoji_font.py` (pass `--truecolor` for `emoji-truecolor.tlf`). The same
rules apply to any other colored TLF that follows this convention.

## Multi-layer SGR

Each cell may carry up to **three** color representations in a single SGR
sequence:

- 16-color codes for TOIlet and 16-color fallback
- compact 256-color codes for 256-color terminals
- compact truecolor codes for 24-bit terminals

```
\e[{fg16};{fg256_or_tc};{bg16};{bg256_or_tc}m
```

- `{fg16}` / `{bg16}` — standard 16-color codes (`30–37`, `40–47`,
  `90–97`, `100–107`). TOIlet reads these; unknown codes are silently skipped.
- `{fg256_or_tc}` / `{bg256_or_tc}` — **either** a compact 256-color code
  **or** a compact truecolor code (see below). Each color uses at most one
  extended layer. The parser determines whether it is foreground or background
  from the preceding 16-color code.

Any layer may be absent when a channel is unchanged (delta encoding) or when a
more compact layer already represents the exact same color.

## Compact 256-color codes

Instead of the verbose `38;5;N` and `48;5;N` sequences (7–9 characters each),
256-color values are stored as single integers with a shared offset:

| Encoding  | Range   | Decoding         |
|-----------|---------|------------------|
| `256 + N` | 256–511 | `idx = code - 256` |

The same offset is used for **both** foreground and background. The parser
determines the channel from the **preceding 16-color code** in the same SGR
sequence (see [Context-based channel detection](#context-based-channel-detection)).

TOIlet (libcaca) ignores codes >107, so these pass through harmlessly.

## Compact truecolor codes

For **stored** glyph data, verbose `38;2;R;G;B` and `48;2;R;G;B` sequences are
usually avoided: they are long and expose intermediate SGR parameters (`38`,
`48`, `2`) that TOIlet would see as extra numbers. Instead, fonts using this
extension store truecolor as a single decimal integer with a shared offset:

| Encoding      | Range              | Decoding             |
|---------------|--------------------|----------------------|
| `512 + bgr24` | 512–16,777,727     | `bgr24 = code - 512` |

Where `bgr24 = (B << 16) | (G << 8) | R`.

Decoding: `R = bgr24 & 0xFF`, `G = (bgr24 >> 8) & 0xFF`, `B = (bgr24 >> 16) & 0xFF`.

The BGR channel order minimizes the decimal digit count: blue (the least
significant channel in typical emoji palettes) occupies the high bits, keeping
most codes shorter than the traditional RGB packing would.

The same offset is used for **both** foreground and background. The parser
determines the channel from context (see below).

## Context-based channel detection

Both compact 256-color codes (256–511) and compact truecolor codes (512+) use
a **single** offset range for foreground and background. The parser tracks the
most recent channel set by a standard 16-color SGR code:

- Codes 30–37, 90–97 (foreground), SGR 1/22 (bold), SGR 39 (default fg)
  → set context to **foreground**
- Codes 40–47, 100–107 (background), SGR 49 (default bg)
  → set context to **background**
- SGR 0 (reset) → resets context to **foreground**

When a compact code is encountered, it is assigned to the current context
channel. Because the encoder always emits a 16-color code before any compact
code for the same channel, the context is always set correctly.

Encoders (e.g. the emoji builder) and the PHP parser normalize colors as follows:

1. If the RGB value exactly matches one of the 16 base colors, emit only 16.
2. Else if it exactly matches one of the 256 ANSI palette colors, emit `16 + 256`.
3. Else emit `16 + truecolor` (the 256-color layer is omitted; the parser
   computes the nearest 256-color index from the truecolor value at runtime).

At runtime Text\_Figlet chooses the best layer in this order:

1. truecolor
2. 256-color (stored explicitly, or computed from truecolor via `nearestAnsi256`)
3. 16-color

Terminals that support truecolor receive standard ANSI output
`38;2;R;G;B` / `48;2;R;G;B`; these verbose forms are generated only at render
time, not stored in compact form in the font.

### Verbose SGR in glyph lines

When **parsing** TLF glyph lines, `Row` also accepts the usual verbose sequences
`38;5;N` / `48;5;N` (256-color) and `38;2;R;G;B` / `48;2;R;G;B` (truecolor), not
only the compact numeric codes. Hand-authored or other tool-generated TLF may use
them; the emoji builder simply prefers compact storage for truecolor.

## Delta encoding

The encoder tracks the current terminal state and emits only the attributes
that actually changed between consecutive cells. There is no full reset
(`\e[0;…m`) between cells — only the differing fg/bg codes appear. A full
reset `\e[m` is emitted only when both colors return to "none".

Example — two adjacent cells with different colors:

```
\e[31;287;41;297m▄\e[32;288m▄
```

The second cell shares the same background, so only the foreground codes are
re-emitted.

## Reverse video toggle

When consecutive cells swap foreground and background (a common pattern with
half-block `▄` characters), the encoder emits `\e[7m` (reverse on) or
`\e[27m` (reverse off) instead of re-specifying both colors:

```
\e[31;287;42;298m▄\e[7m▄
```

A single `7` or `27` replaces two full color specifications. TOIlet supports
SGR 7/27 natively.

## Color normalization

Many 256-color palette entries have RGB values identical to one of the 16 base
colors. For example, palette index 196 (`#ff0000`) matches index 9 (bright red)
in the CGA palette.

When a 256-color index has an exact base-16 RGB match:

1. The index is **canonicalized** to its base-16 equivalent during parsing
   (e.g., 196 → 9).
2. The compact 256-color code is **omitted** from the output, since the
   16-color code already provides the exact color.

This eliminates roughly a third of 256-color slots with no quality loss.

## No leading reset

The first SGR sequence on each glyph line omits the `0;` reset prefix. The
terminal state is always clean at the start of a line, so the reset is
redundant. The sequence starts directly with the color codes:

```
\e[31;287;41;297m▄…
```

## Trailing decolorize

Trailing cells that are spaces with a black or absent background carry no
visual information. Their color attributes are stripped, so the line-ending
`\e[m` reset covers them without per-cell SGR overhead.

## Compression

The TLF file is gzip-compressed (the `.tlf` extension is kept; Text_Figlet
detects gzip by magic bytes). When the `zopfli` Python package is available
(`pip install zopfli`), it produces ~3–5% smaller output than standard gzip.
The `mtime` field in the gzip header is zeroed for reproducible builds.

## 256-to-16 color mapping (Lab + chroma/hue penalty)

The 16-color codes are generated by mapping each 256-color index to its
nearest base-16 color. Instead of Euclidean RGB distance, the mapper uses
CIE L\*a\*b\* color space with two additional penalties:

1. **Achromatic penalty** — when the source has chroma >5 and the target is
   achromatic (chroma <5), the squared distance is increased by
   `(source_chroma × 4)²`. This prevents warm tones like cream `(255,255,215)`
   from falling into white.

2. **Hue mismatch penalty** — when both source and target are chromatic, the
   squared distance is increased by `(source_chroma × 5 × sin(Δhue/2))²`.
   This prevents colors from mapping to a wrong hue family (e.g., a yellow
   tone mapping to cyan just because it happens to be closer in Lab space).

The same algorithm runs in both the Python builder (`nearest_16()`) and the
PHP parser (`Row::nearestBase16()`).

## Base-16 palette

Both the Python builder and the PHP parser use the same CGA-style RGB values
for 16-color downgrading:

| Index | Color         | RGB               |
|-------|---------------|-------------------|
| 0     | Black         | `(0, 0, 0)`       |
| 1     | Red           | `(170, 0, 0)`     |
| 2     | Green         | `(0, 170, 0)`     |
| 3     | Brown/Yellow  | `(170, 85, 0)`    |
| 4     | Blue          | `(0, 0, 170)`     |
| 5     | Magenta       | `(170, 0, 170)`   |
| 6     | Cyan          | `(0, 170, 170)`   |
| 7     | Light gray    | `(170, 170, 170)` |
| 8     | Dark gray     | `(85, 85, 85)`    |
| 9     | Light red     | `(255, 85, 85)`   |
| 10    | Light green   | `(85, 255, 85)`   |
| 11    | Light yellow  | `(255, 255, 85)`  |
| 12    | Light blue    | `(85, 85, 255)`   |
| 13    | Light magenta | `(255, 85, 255)`  |
| 14    | Light cyan    | `(85, 255, 255)`  |
| 15    | White         | `(255, 255, 255)` |

Keeping these in sync between the builder and the parser ensures that TOIlet
and Text_Figlet produce identical 16-color output.

## Reference build pipeline (emoji fonts)

```
Emoji font (TTF/TTC)
  → Pillow renders each codepoint to a cropped PNG
    → chafa converts PNG to ANSI (half-block art, 12×6 cells; `--colors full` for truecolor builds)
      → Parser extracts (char, fg, bg) triples, resolving reverse video
        → Color normalizer canonicalizes exact 16/256 matches
          → Encoder emits 16 + 256 + optional truecolor SGR
            → TLF assembler writes the font file
              → Zopfli/gzip compresses to final `emoji.tlf` / `emoji-truecolor.tlf`
```

## TOIlet compatibility

The format is designed to remain fully compatible with
[TOIlet](http://caca.zoy.org/wiki/toilet) (libcaca):

- TOIlet parses 16-color SGR codes (`30–47`, `90–107`) normally.
- Compact 256-color codes (256+) and truecolor codes (512+) fall outside
  libcaca's known range (0–107) and are silently ignored.
- SGR 7/27 (reverse video) is supported natively by TOIlet.
- Delta encoding is transparent: TOIlet applies each SGR incrementally,
  same as a full-reset sequence would.

## Rejected optimizations

The following ideas were tested and rejected due to TOIlet incompatibility or
lack of measurable benefit.

### fg==bg cells → space with bg-only

When a half-block character (`▄` or `▌`) has identical foreground and
background colors, the cell is visually a solid rectangle — the same as a
space with that background. Replacing the half-block with a space and dropping
the foreground color would save one SGR code per such cell.

**Rejected:** TOIlet's smushing algorithm treats spaces as transparent — a
space at a glyph boundary gets overwritten by the adjacent glyph's character.
Half-blocks, on the other hand, are opaque and win the smush. Replacing `▄`
with a space changes the smushing result, producing visual artifacts when
glyphs overlap (e.g., `▄` at the right edge of one glyph disappears when the
next glyph's left edge has a non-space character).

### SGR 2 (dim/faint) for semi-transparent edges

Using dim mode to approximate alpha transparency at emoji edges — pixels with
partial opacity would render at half brightness instead of being fully opaque
or fully black.

**Rejected:** Terminal support for SGR 2 is inconsistent. Many terminals
ignore it entirely, and those that do support it apply it differently (some
dim the foreground only, some affect background too). The visual improvement
was negligible in practice.

### Single-byte block character substitution

Replacing multi-byte UTF-8 half-block characters (3 bytes for `▄` U+2584)
with single-byte CP437-style equivalents to save 2 bytes per character cell.

**Rejected:** TOIlet does not recognize CP437 byte sequences in UTF-8 TLF
files; the characters render as garbage.

### Independent 16/256-color delta tracking

When two adjacent cells have different 256-color indices but the same
16-color approximation (e.g., both map to light yellow 93), the 16-color
code is unchanged and could be omitted. Only the compact 256-color code
would be emitted; TOIlet keeps the previous 16-color state, and the PHP
parser picks up the new shade.

**Rejected:** The raw (uncompressed) font shrinks by ~10%, but gzip
compresses the regular `{fg16};{fg256}` pairs much more efficiently than
the mixed patterns that result from sometimes omitting the 16-color code.
After zopfli compression the font is ~3% **larger**.

### CUF cursor positioning instead of trailing spaces

Replacing trailing runs of spaces (e.g., in narrow placeholder glyphs) with
CSI CUF sequences (`\e[nC`) to advance the cursor without emitting characters.
libcaca's ANSI parser supports CUF, and the smushing algorithm works on the
canvas (where CUF-skipped cells remain spaces), so compatibility is preserved.

**Rejected:** Saves 1,224 raw bytes (612 lines × 2 bytes), but gzip
compresses repeated spaces much more efficiently than `\e[nC` sequences.
After zopfli, the font is ~70 bytes **larger**. The PHP parser supports CUF
(`\e[nC` inserts n space cells) in case future fonts benefit from it.
