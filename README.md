# Text_Figlet

PHP implementation of the FIGlet font format for rendering plain text as ASCII art.

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

## HTML output

```php
echo $figlet->render('Hi', asHtml: true);
```

## Font formats

- `.flf` FIGlet fonts
- `.tlf` TOIlet fonts
- `.flc` control files

## Bundled fonts

The bundle includes 98 fonts:

- Classic FIGlet fonts and redistributable community fonts
- TOIlet `.tlf` fonts including `emboss`, `emboss2`, `circle`, `future`, `letter`, `pagga`, `smblock`, `smbraille`, `wideterm`
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
- TOIlet `.tlf` fonts

## Static analysis

Run all analyzers:

```bash
composer analyze
```
