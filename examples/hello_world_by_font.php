<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Bolk\TextFiglet\Figlet;


function isSingleSymbolGlyphFont(Figlet $figlet): bool
{
    foreach (['A', 'B', 'P', 'R'] as $sample) {
        $rendered = rtrim($figlet->render($sample), "\n");
        $lines = array_values(array_filter(
            explode("\n", $rendered),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if (count($lines) !== 1 || mb_strlen($lines[0], 'UTF-8') !== 1) {
            return false;
        }
    }

    return true;
}

function detectLanguage(Figlet $figlet): string
{
    $codepoints = $figlet->getLoadedCodepoints();
    $comment = strtolower($figlet->fontComment);
    $rtl = $figlet->getPrintDirection() === 1;

    $hasRange = static function (array $codepoints, int $lo, int $hi): bool {
        foreach ($codepoints as $cp) {
            if ($cp >= $lo && $cp <= $hi) {
                return true;
            }
        }
        return false;
    };

    $hasHebrew   = $hasRange($codepoints, 0x0590, 0x05FF) || $hasRange($codepoints, 0xFB1D, 0xFB4F);
    $hasChinese  = in_array(0x4F60, $codepoints, true) && in_array(0x597D, $codepoints, true);
    $hasCyrillic = $hasRange($codepoints, 0x0400, 0x04FF);
    $hasBraille  = $hasRange($codepoints, 0x2800, 0x28FF);
    $hasKatakana = $hasRange($codepoints, 0x30A0, 0x30FF);
    $hasGreek    = $hasRange($codepoints, 0x0370, 0x03FF);

    if ($hasHebrew) {
        return 'hebrew';
    }
    if ($rtl && (
        str_contains($comment, 'hebrew')
        || str_contains($comment, 'jerusalem')
        || str_contains($comment, 'ivrit')
    )) {
        return 'hebrew-mapped';
    }
    if ($hasChinese) {
        return 'chinese';
    }
    if ($hasCyrillic) {
        if (isSingleSymbolGlyphFont($figlet)) {
            return 'cyrillic-style';
        }
        return 'cyrillic';
    }
    if ($hasKatakana) {
        return 'katakana';
    }
    if ($hasGreek) {
        return 'greek';
    }
    if ($hasBraille) {
        return 'braille';
    }

    $commentMarkers = [
        'cyrillic-mapped' => ['cyrillic', 'moscow'],
        'greek-mapped'    => ['ntgreek', 'greek'],
        'katakana-mapped' => ['katakana'],
        'tengwar'         => ['tengwar', 'tolkien'],
        'hieroglyphs'     => ['hieroglyph'],
        'cherokee'        => ['cherokee', 'tsalagi', 'syllabry'],
        'runic'           => ['futhark', 'runic', 'rune'],
        'morse'           => ['morse code', 'morse'],
        'encoding'        => ['binary', 'octal', 'decimal', 'hexadecimal'],
        'chess'           => ['chessboard-style glyph font', 'chess'],
    ];
    foreach ($commentMarkers as $lang => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($comment, $kw)) {
                return $lang;
            }
        }
    }

    $germanCodes = [196, 214, 220, 228, 246, 252, 223];
    $latinWidth = $figlet->getCharWidth(ord('H')) ?? $figlet->getCharWidth(ord('A')) ?? 1;
    $realGerman = 0;
    foreach ($germanCodes as $gc) {
        $w = $figlet->getCharWidth($gc);
        if ($w !== null && ($latinWidth <= 0 || $w / $latinWidth >= 0.3)) {
            $realGerman++;
        }
    }

    $germanLevel = match (true) {
        $realGerman === 7 => 'full',
        $realGerman > 0   => 'partial',
        default           => 'none',
    };

    $suffix = match ($germanLevel) {
        'full'    => '+german',
        'partial' => '+german-partial',
        'none'    => '',
    };

    if ($rtl) {
        return 'latin-rtl' . $suffix;
    }
    if ($suffix !== '') {
        return 'latin' . $suffix;
    }

    return 'latin';
}

function printSection(string $title): void
{
    echo "\n" . str_repeat('=', 80) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 80) . "\n";
}

/** ANSI SGR: bold on / reset (terminals that support it). */
function terminalBold(string $text): string
{
    return "\033[1m" . $text . "\033[0m";
}

$fontsDir = realpath(__DIR__ . '/../fonts');
if ($fontsDir === false) {
    fwrite(STDERR, "fonts directory not found\n");
    exit(1);
}

$entries = scandir($fontsDir);
if ($entries === false) {
    fwrite(STDERR, "cannot read fonts directory\n");
    exit(1);
}

$fontFiles = [];
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $fullPath = $fontsDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_file($fullPath)) {
        continue;
    }

    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
    if ($ext === 'flf' || $ext === 'tlf') {
        $fontFiles[] = $entry;
    }
}

sort($fontFiles);

printSection('Fonts (.flf/.tlf): "hello" in the language each font was made for');

$greetings = [
    'hebrew'         => ['שלום', 'שלום / Shalom (Unicode Hebrew)'],
    'hebrew-mapped'  => ['akhun', 'שלום / Shalom (keyboard-mapped RTL)'],
    'chinese'        => ['你好', 'Nǐ hǎo'],
    'cyrillic'       => ['Привет', 'Privet (Cyrillic input)'],
    'cyrillic-style' => ['Privet', 'Privet (Cyrillic-style output, Latin input)'],
    'runic'          => ['EK THEK HAILISO', 'ᛖᚲ ᚦᛖᚲ ᚺᚨᛁᛚᛁᛊᛟ (Elder Futhark)'],
    'morse'          => ['Hello', 'Hello (Morse)'],
    'braille'        => ['Hello', 'Hello (Braille)'],
    'katakana'           => ['アイウ', 'アイウ (Katakana, Unicode)'],
    'katakana-mapped'    => ['AIUEO', 'アイウエオ (Katakana, keyboard-mapped)'],
    'greek'              => ['Γεια', 'Γεια / Geia (Greek, Unicode)'],
    'greek-mapped'       => ['Geia', 'Γεια / Geia (Greek, keyboard-mapped)'],
    'cyrillic-mapped'    => ['Privet', 'Привет / Privet (Cyrillic, keyboard-mapped)'],
    'tengwar'            => ['aiya', 'aiya — Quenya for «hello» (Tengwar)'],
    'hieroglyphs'        => ['ankh', 'ankh — ☥ «life» (Egyptian hieroglyphs)'],
    'cherokee'           => ['q&z', 'ᎣᏏᏲ / Osiyo (Cherokee syllabary, Joan Touzet keymap)'],
    'encoding'           => ['Hi', 'Hi (numeric encoding)'],
    'chess'              => ['CHECKMATE', 'Chess-themed font'],
    'latin+german'           => ['grüß Gott', 'grüß Gott (Latin + German)'],
    'latin+german-partial'   => ['Hello', 'Hello (Latin + partial German)'],
    'latin-rtl'              => ['olleH', 'olleH (Latin, RTL)'],
    'latin-rtl+german'       => ['ttoG ßürg', 'grüß Gott (Latin + German, RTL)'],
    'latin-rtl+german-partial' => ['olleH', 'Hello (Latin + partial German, RTL)'],
    'latin'                  => ['Hello', 'Hello'],
];

foreach ($fontFiles as $fontFile) {
    $path = $fontsDir . DIRECTORY_SEPARATOR . $fontFile;

    $figlet = new Figlet();
    $figlet->loadFont($path);

    $language = detectLanguage($figlet);
    [$text, $label] = $greetings[$language];

    echo "\n>>> " . terminalBold($fontFile) . "\n";
    echo "language: $language — $label\n";
    echo $figlet->render($text) . "\n";
}

