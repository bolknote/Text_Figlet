<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Bolk\TextFiglet\ControlFile;
use Bolk\TextFiglet\ExportFormat;
use Bolk\TextFiglet\Figlet;
use Bolk\TextFiglet\Filter;
use Bolk\TextFiglet\Justification;
use Bolk\TextFiglet\LayoutMode;

$fontsDir = __DIR__ . '/../fonts/';

function section(string $title): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 70) . "\n\n";
}

function subsection(string $title): void
{
    echo "--- $title ---\n\n";
}

function codePointString(string $text): string
{
    $points = [];
    $length = mb_strlen($text, 'UTF-8');

    for ($i = 0; $i < $length; $i++) {
        $points[] = sprintf('U+%04X', mb_ord(mb_substr($text, $i, 1, 'UTF-8'), 'UTF-8'));
    }

    return implode(' ', $points);
}

// ============================================================
section('1. BASIC RENDERING');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');
echo $figlet->render('Hello!') . "\n";

// ============================================================
section('2. DIFFERENT FONTS');

foreach (['standard', 'small', 'slant', 'banner', 'makisupa'] as $name) {
    subsection("$name.flf");
    $f = new Figlet();
    $f->loadFont($fontsDir . "$name.flf");
    echo $f->render($name === 'makisupa' ? 'Hey' : ($name === 'banner' ? 'Hi!' : 'FIGlet')) . "\n\n";
}

// ============================================================
section('3. FONT COMMENT');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');
echo "Font: standard.flf\n";
echo $figlet->fontComment . "\n";

// ============================================================
section('4. LAYOUT MODES (standard.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');

subsection('Smushing (default for standard.flf)');
echo $figlet->render('AB') . "\n\n";

subsection('Fitting (kerning)');
$figlet->setHorizontalLayout(LayoutMode::Fitting);
echo $figlet->render('AB') . "\n\n";

subsection('Full Width');
$figlet->setHorizontalLayout(LayoutMode::FullSize);
echo $figlet->render('AB') . "\n";

// ============================================================
section('5. SMUSHING RULES IN ACTION');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');

subsection('Rule 1 - Equal character: || -> merged |');
echo $figlet->render('||') . "\n\n";

subsection('Rule 5 - Big X: /\\ -> |');
echo $figlet->render('/\\') . "\n\n";

subsection('Rule 4 - Opposite pair: ][ -> |');
echo $figlet->render('][') . "\n\n";

subsection('Full word smushing');
echo $figlet->render('Hello World') . "\n";

// ============================================================
section('6. HTML OUTPUT');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'small.flf');

subsection('ExportFormat::Html (XHTML with <span>)');
$html = $figlet->render('Hi', ExportFormat::Html);
echo "First 200 chars:\n";
echo substr($html, 0, 200) . "...\n\n";

subsection('ExportFormat::Html3 (table with <font>)');
$html3 = $figlet->render('Hi', ExportFormat::Html3);
echo $html3 . "\n";

subsection('ExportFormat::Html3 with Rainbow');
$figlet->addFilter(Filter::Rainbow);
$html3Rainbow = $figlet->render('Hi', ExportFormat::Html3);
echo "First 300 chars:\n";
echo substr($html3Rainbow, 0, 300) . "...\n";
$figlet->clearFilters();

// ============================================================
section('7. WORD WRAPPING (small.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'small.flf');

subsection('Without word wrap');
echo $figlet->render('Hello World FIGlet') . "\n\n";

subsection('With width=40');
$figlet->setWidth(40);
echo $figlet->render('Hello World FIGlet') . "\n\n";

subsection('With width=25');
$figlet->setWidth(25);
echo $figlet->render('Hello World FIGlet') . "\n";

// ============================================================
section('8. PARAGRAPH MODE (small.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'small.flf');

$text = "Hello\nWorld";

subsection('Normal mode (each line separate)');
echo $figlet->render($text) . "\n\n";

subsection('Paragraph mode (newline becomes space)');
$figlet->setParagraphMode(true);
echo $figlet->render($text) . "\n";

// ============================================================
section('9. JUSTIFICATION (small.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'small.flf');
$figlet->setWidth(50);

subsection('Left (default)');
$figlet->setJustification(Justification::Left);
echo $figlet->render('Hi') . "\n\n";

subsection('Center');
$figlet->setJustification(Justification::Center);
echo $figlet->render('Hi') . "\n\n";

subsection('Right');
$figlet->setJustification(Justification::Right);
echo $figlet->render('Hi') . "\n";

// ============================================================
section('10. RIGHT-TO-LEFT (ivrit.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'ivrit.flf');
echo "Print direction: " . ($figlet->getPrintDirection() ? "RTL" : "LTR") . "\n\n";

subsection('Raw RTL rendering (no width)');
echo $figlet->render('Hello') . "\n\n";

subsection('RTL with right-justification (width=80, matches C figlet default)');
$figlet->setWidth(80)->setJustification(Justification::Auto);
echo $figlet->render('Hello') . "\n\n";

subsection('RTL with width=50');
$figlet->setWidth(50);
echo $figlet->render('Hi') . "\n";

// ============================================================
section('11. VERTICAL OPERATIONS (slant.flf)');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'slant.flf');

subsection('Two lines, vertical Full Size');
$figlet->setVerticalLayout(LayoutMode::FullSize);
echo $figlet->render("AB\nCD") . "\n\n";

subsection('Two lines, vertical Fitting');
$figlet->setVerticalLayout(LayoutMode::Fitting);
echo $figlet->render("AB\nCD") . "\n\n";

subsection('Two lines, vertical Smushing');
$figlet->setVerticalLayout(LayoutMode::Smushing);
echo $figlet->render("AB\nCD") . "\n";

// ============================================================
section('12. UTF-8 AND %uHHHH ENCODING');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');

subsection('Direct ASCII');
echo $figlet->render('ABC') . "\n\n";

subsection('Same via %u escapes: %u0041%u0042%u0043');
echo $figlet->render('%u0041%u0042%u0043') . "\n";

// ============================================================
section('13. CONTROL FILES');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');

subsection('Bundled UTF-8 control file');
$figlet->loadControlFile('utf8');
echo $figlet->render('Grüße') . "\n\n";
$figlet->clearControlFiles();

subsection('Bundled Greek transliteration control file: "V" -> final sigma');
$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'big.flf');
$figlet->loadControlFile('frango');
echo $figlet->render('V') . "\n\n";
$figlet->clearControlFiles();

subsection('Bundled HZ control file with double-byte input');
$targetName = '叶夫根尼·斯捷帕尼舍夫';
$hzInput = '~{R67r8yDa!$K9=]EADaIa7r~}';
echo "Represents:    $targetName\n";
echo "HZ input:      $hzInput\n";
echo "(GB-compatible HZ form)\n\n";

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'gb16fs.flf');
$figlet->loadControlFile('hz');
echo $figlet->render($hzInput) . "\n";

// ============================================================
section('14. MISSING CHARACTER FALLBACK');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . '5x7.flf');
echo "Rendering char not in font using 5x7.flf default glyph (code 0):\n";
echo $figlet->render("\xF0\x9F\x98\x80") . "\n";

// ============================================================
section('15. FONT METADATA');

foreach (['standard.flf', 'small.flf', 'slant.flf', 'banner.flf', 'ivrit.flf', 'makisupa.flf', 'gb16fs.flf', 'emboss.tlf', 'wideterm.tlf'] as $name) {
    $f = new Figlet();
    $f->loadFont($fontsDir . $name);
    printf(
        "%-14s  height=%-2d  baseline=%-2d  old_layout=%-3d  full_layout=%-6d  dir=%s  h=%s  v=%s\n",
        $name,
        $f->getHeight(),
        $f->getBaseline(),
        $f->getOldLayout(),
        $f->getFullLayout(),
        $f->getPrintDirection() ? 'RTL' : 'LTR',
        $f->getHorizontalLayout()->name,
        $f->getVerticalLayout()->name,
    );
}

// ============================================================
section('16. TLF (TOIlet) FONT SUPPORT');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'emboss.tlf');

subsection('Emboss TLF font (box-drawing characters)');
echo $figlet->render('Hello') . "\n\n";

subsection('TLF with word wrapping (width=20)');
$figlet->setWidth(20);
echo $figlet->render('AB CD') . "\n\n";

$figlet->setWidth(0);

subsection('TLF font loaded by name (without extension)');
$f = new Figlet();
$f->loadFont('emboss');
echo $f->render('Hi!') . "\n";

// ============================================================
section('17. ISO 2022 CONTROL FILES');

subsection('JIS X 0201 control file (ASCII → JIS Roman mapping)');
$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');
$figlet->loadControlFile('jis0201');
echo "Rendering JIS Roman backslash as Yen sign:\n";
echo $figlet->render("\x1b(J\\") . "\n\n";

subsection('ISO 2022 g-command state');
$cf = ControlFile::fromString("gL 0\n");
echo "Encoding mode after g-command: " . $cf->getEncoding()->name . "\n";
echo "Default ISO 2022 pass-through: '" . $cf->apply('Hi') . "'\n";

// ============================================================
section('18. CONTROL FILE METADATA');

foreach (['utf8.flc', 'hz.flc', 'frango.flc', 'jis0201.flc'] as $name) {
    $cf = ControlFile::load($name);
    $stages = $cf->getStages();
    $ruleCount = 0;
    foreach ($stages as $stage) {
        $ruleCount += count($stage);
    }

    printf(
        "%-12s  encoding=%-8s  stages=%-2d  rules=%-3d\n",
        $name,
        $cf->getEncoding()->name,
        count($stages),
        $ruleCount,
    );
}

// ============================================================
section('19. FILTERS');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'future.tlf');

subsection('No filter (plain) — future.tlf');
echo $figlet->render('Hello') . "\n\n";

$bigFiglet = new Figlet();
$bigFiglet->loadFont($fontsDir . 'big.flf');

subsection('Crop — big.flf (has blank padding rows)');
echo "Before crop:\n";
echo $bigFiglet->render('Hi');
$bigFiglet->addFilter(Filter::Crop);
echo "\nAfter crop:\n";
echo $bigFiglet->render('Hi') . "\n";
$bigFiglet->clearFilters();

subsection('Flip (horizontal mirror)');
$figlet->addFilter(Filter::Flip);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

subsection('Flop (vertical mirror)');
$figlet->addFilter(Filter::Flop);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

subsection('Rotate 180');
$figlet->addFilter(Filter::Rotate180);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

subsection('Border');
$figlet->addFilter(Filter::Border);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

$letterFiglet = new Figlet();
$letterFiglet->loadFont($fontsDir . 'letter.tlf');

subsection('Rotate Right (90 degrees clockwise) — letter.tlf');
$letterFiglet->addFilter(Filter::RotateRight);
echo $letterFiglet->render('Hello') . "\n\n";
$letterFiglet->clearFilters();

subsection('Rotate Left (90 degrees counterclockwise) — letter.tlf');
$letterFiglet->addFilter(Filter::RotateLeft);
echo $letterFiglet->render('Hello') . "\n\n";

$figlet->loadFont($fontsDir . 'small.flf');

subsection('Rainbow (ANSI colors) — small.flf');
$figlet->addFilter(Filter::Rainbow);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

subsection('Metal (ANSI colors)');
$figlet->addFilter(Filter::Metal);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

subsection('Chained: Border + Rainbow');
$figlet->addFilter(Filter::Border)->addFilter(Filter::Rainbow);
echo $figlet->render('Hello') . "\n\n";
$figlet->clearFilters();

// ============================================================
section('20. TERMINAL WIDTH');

echo "Detected terminal width: " . Figlet::terminalWidth() . " columns\n\n";

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'small.flf');
$figlet->setWidth(Figlet::terminalWidth());
echo $figlet->render('Terminal width wrapping demo text') . "\n";

// ============================================================
section('21. INFOCODE');

$figlet = new Figlet();
$figlet->loadFont($fontsDir . 'standard.flf');
$figlet->setWidth(Figlet::terminalWidth());

echo "Code 0 (version):    " . $figlet->getInfoCode(0) . "\n";
echo "Code 1 (version ID): " . $figlet->getInfoCode(1) . "\n";
echo "Code 2 (font dir):   " . $figlet->getInfoCode(2) . "\n";
echo "Code 3 (font name):  " . $figlet->getInfoCode(3) . "\n";
echo "Code 4 (term width): " . $figlet->getInfoCode(4) . "\n";

echo "\n" . str_repeat('=', 70) . "\n";
echo "  Demo complete!\n";
echo str_repeat('=', 70) . "\n";
