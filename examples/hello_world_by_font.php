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

function detectLanguage(Figlet $figlet, string $comment): string
{
    $text = strtolower($comment);

    $markers = [
        'hebrew'   => ['hebrew font', 'hebrew unicode', 'ivrit'],
        'chinese'  => ['gb2312', 'hanzi', 'guobiao'],
        'cyrillic' => ['cyrillic'],
        'runic'    => ['futhark', 'runic', 'rune'],
        'morse'    => ['morse code', 'morse'],
        'braille'  => ['braille'],
    ];

    foreach ($markers as $language => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                if ($language === 'cyrillic' && isSingleSymbolGlyphFont($figlet)) {
                    return 'cyrillic-style';
                }

                return $language;
            }
        }
    }

    return 'latin';
}

function printSection(string $title): void
{
    echo "\n" . str_repeat('=', 80) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 80) . "\n";
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
    'hebrew'         => ['Shalom', 'Shalom (RTL)'],
    'chinese'        => ['你好', 'Nǐ hǎo'],
    'cyrillic'       => ['Привет', 'Privet (Cyrillic input)'],
    'cyrillic-style' => ['Privet', 'Privet (Cyrillic-style output, Latin input)'],
    'runic'          => ['EK THEK HAILISO', 'ᛖᚲ ᚦᛖᚲ ᚺᚨᛁᛚᛁᛊᛟ (Elder Futhark)'],
    'morse'          => ['Hello', 'Hello (Morse)'],
    'braille'        => ['Hello', 'Hello (Braille)'],
    'latin'          => ['Hello', 'Hello'],
];

foreach ($fontFiles as $fontFile) {
    $path = $fontsDir . DIRECTORY_SEPARATOR . $fontFile;

    $figlet = new Figlet();
    $figlet->loadFont($path);

    $language = detectLanguage($figlet, $figlet->fontComment);
    [$text, $label] = $greetings[$language];

    echo "\n>>> $fontFile\n";
    echo "language: $language — $label\n";
    echo $figlet->render($text) . "\n";
}

