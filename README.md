# Text_Figlet

PHP implementation of the FIGlet font format for rendering plain text as ASCII art.

```
        ,--,
      ,--.'|            ,--,    ,--,
   ,--,  | :          ,--.'|  ,--.'|
,---.'|  : '          |  | :  |  | :     ,---.
|   | : _' |          :  : '  :  : '    '   ,'\
:   : |.'  |   ,---.  |  ' |  |  ' |   /   /   |
|   ' '  ; :  /     \ '  | |  '  | |  .   ; ,. :
'   |  .'. | /    /  ||  | :  |  | :  '   | |: :
|   | :  | '.    ' / |'  : |__'  : |__'   | .; :
'   : |  : ;'   ;   /||  | '.'|  | '.'|   :    |
|   | '  ,/ '   |  / |;  :    ;  :    ;\   \  /
;   : ;--'  |   :    ||  ,   /|  ,   /  `----'
|   ,/       \   \  /  ---`-'  ---`-'
'---'         `----'
```

## Installation

```bash
composer require bolk/text-figlet
```

## Quick start

```php
use Bolk\TextFiglet\Figlet;

$figlet = new Figlet();
$figlet->loadFont('standard.flf');

echo $figlet->render('Hello!');
```

Fonts without a path are looked up in the bundled `fonts/` directory. You can also pass a full path:

```php
$figlet->loadFont('/path/to/custom.flf');
```

## Layout modes

FIGlet supports three horizontal layout modes. The default comes from the font, but you can override it:

```php
use Bolk\TextFiglet\LayoutMode;

$figlet->setHorizontalLayout(LayoutMode::Smushing); // characters overlap
$figlet->setHorizontalLayout(LayoutMode::Fitting);  // characters touch
$figlet->setHorizontalLayout(LayoutMode::FullSize);  // full width
```

The same applies vertically when rendering multi-line input:

```php
$figlet->setVerticalLayout(LayoutMode::Fitting);
echo $figlet->render("Hello\nWorld");
```

## Word wrapping

```php
$figlet->setWidth(80);
echo $figlet->render('A very long sentence that will be wrapped');
```

## Justification

```php
use Bolk\TextFiglet\Justification;

$figlet->setWidth(80);
$figlet->setJustification(Justification::Center);
echo $figlet->render('Centered');
```

`Justification::Auto` (the default) picks left for LTR fonts and right for RTL.

## Paragraph mode

Treats single newlines as spaces (like word processors). Double newlines remain as line breaks:

```php
$figlet->setParagraphMode(true);
echo $figlet->render("Hello\nWorld");   // rendered as "Hello World"
echo $figlet->render("Hello\n\nWorld"); // two separate lines
```

## Control files

FIGlet control files (`.flc`) are mapping tables that preprocess input before rendering. They are used for character remapping, encoding modes, and locale-specific input conversions:

```php
$figlet->loadControlFile('utf8.flc');
echo $figlet->render('Hello');
$figlet->clearControlFiles();
```

## Filters

Post-processing filters transform the rendered output. Inspired by TOIlet. Filters are applied in order after rendering and can be chained:

```php
use Bolk\TextFiglet\Filter;

$figlet->addFilter(Filter::Border);
echo $figlet->render('Hello');

$figlet->addFilter(Filter::Rainbow);   // chained: border + rainbow
echo $figlet->render('Hello');

$figlet->clearFilters();
```

Available filters:

| Filter | Description |
|--------|-------------|
| `Filter::Crop` | Trim blank rows and columns |
| `Filter::Flip` | Mirror horizontally |
| `Filter::Flop` | Mirror vertically |
| `Filter::Rotate180` | Rotate 180 degrees |
| `Filter::RotateLeft` | Rotate 90° counterclockwise |
| `Filter::RotateRight` | Rotate 90° clockwise |
| `Filter::Border` | Surround with a Unicode box border |
| `Filter::Rainbow` | Rainbow ANSI color effect |
| `Filter::Metal` | Metallic ANSI color effect |

Color filters (`Rainbow`, `Metal`) output ANSI escape codes for terminal display.

## Terminal width

Auto-detect the terminal width and use it for word wrapping:

```php
$figlet->setWidth(Figlet::terminalWidth());
echo $figlet->render('Adapts to your terminal');
```

`Figlet::terminalWidth()` checks the `COLUMNS` environment variable, falls back to `tput cols`, and defaults to 80.

## HTML output

```php
use Bolk\TextFiglet\ExportFormat;

echo $figlet->render('Hi', ExportFormat::Html);   // XHTML with <span style>
echo $figlet->render('Hi', ExportFormat::Html3);   // table-based with <font color>
```

`Html` produces XHTML with `<nobr>`, `<span>`, `&nbsp;` — supports both foreground and background colors via `style`. `Html3` produces a `<table>` with `<tr><td><tt>` rows — foreground via `<font color>`, background via `<td bgcolor>` — compatible with older HTML renderers and email clients. Color filters (`Rainbow`, `Metal`) are automatically converted from ANSI to the appropriate HTML tags in both modes.

## Font formats

- `.flf` FIGlet fonts
- `.tlf` TOIlet fonts (including colored / 256-color glyphs)
- `.flc` control files

## Colored TLF fonts

TLF fonts can embed ANSI color escape sequences. The library supports:

- **16 colors** — standard SGR codes (30-37, 40-47, 90-97, 100-107)
- **256 colors** — compact codes `256..511` (fg) / `512..767` (bg), or legacy `38;5;N` / `48;5;N` sequences (our extension, not part of the original TOIlet format)

For fonts with 256-color encoding the library transparently decodes the full palette while remaining compatible with `toilet` which uses only the 16-color subset. Standard toilet fonts are loaded as-is.

## Bundled fonts

The bundle includes 317 fonts:

- Classic FIGlet fonts and redistributable community fonts
- TOIlet `.tlf` fonts including `emboss`, `emboss2`, `circle`, `future`, `letter`, `pagga`, `smblock`, `smbraille`, `wideterm`
- `emoji` — 256-color emoji TLF font (1395 glyphs, built with `tools/build_emoji_font.py`)
- JavE fonts with explicit modification/redistribution permission in their headers
- One bundled CJK font: `gb16fs` (GB2312)

See `fonts/THIRD_PARTY.md` for the complete asset list and licensing notes.

Bundled control files: `utf8.flc`, `hz.flc`, `frango.flc`, `jis0201.flc`.

## Compressed fonts

Gzip (`.flf.gz`, requires `ext-zlib`) and ZIP-compressed fonts (requires `ext-zip`) are supported transparently.

## FIGlet standard compliance

Implements the FIGfont Version 2 specification:

- All 6 horizontal smushing rules including rule 6 (hardblank smushing)
- Universal smushing (overlapping)
- Full_Layout header parameter (horizontal + vertical modes and rules)
- Vertical fitting and smushing (5 vertical rules)
- RTL and LTR print direction
- Default character (code 0) fallback
- German character set (7 required Deutsch characters)
- Code-tagged characters (decimal, hex, octal)
- Control files with `t`/`f` commands, range mappings, encoding modes (UTF-8, HZ, Shift-JIS, DBCS), ISO 2022
- TOIlet `.tlf` fonts with 16/256-color ANSI support
- Color-aware smushing (color follows the winning character)

## Static analysis

Run all analyzers:

```bash
composer analyze
```
