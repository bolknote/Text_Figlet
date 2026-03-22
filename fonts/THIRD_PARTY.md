# Third-party assets

This file documents bundled fonts and control files, their source, and licensing note.
Only redistributable assets are included. A sorted list of every bundled `.flf`,
`.tlf`, and `.flc` file is in [Complete inventory](#complete-inventory-all-bundled-files)
at the end of this document (the same list appears under “Complete inventory” in
`fonts/LICENSE`).

## FIGlet 2.2.5 bundle

Assets:
`banner.flf`, `big.flf`, `block.flf`, `bubble.flf`, `digital.flf`, `ivrit.flf`,
`lean.flf`, `mini.flf`, `mnemonic.flf`, `script.flf`, `shadow.flf`, `slant.flf`,
`small.flf`, `smscript.flf`, `smshadow.flf`, `smslant.flf`, `standard.flf`,
`term.flf`, `frango.flc`, `hz.flc`, `utf8.flc`, `jis0201.flc`, `lower.flc`,
`null.flc`, `rot13.flc`, `rot13.flf`, `upper.flc`

Source:
FIGlet 2.2.5 distribution

Licensing note:
Covered by the FIGlet 2.2.5 BSD-style license in `fonts/LICENSE`.

## figlet.org / ftp.figlet.org fonts

Assets:
`5x7.flf`, `6x9.flf`, `6x10.flf`, `avatar.flf`, `doom.flf`, `epic.flf`,
`graceful.flf`, `mirror.flf`, `morse.flf`, `pyramid.flf`, `runyc.flf`,
`speed.flf`, `wavy.flf`

Source:
`figlet.org/fonts` and `ftp.figlet.org/pub/figlet/fonts`

Licensing note:
Each file includes an explicit permissive/public-domain style notice in its header.

## emoji.tlf / emoji-truecolor.tlf (project-built emoji TLF)

Assets:
`emoji.tlf`, `emoji-truecolor.tlf`

Source:
Project-built; regenerate with `tools/build_emoji_font.py` (add `--truecolor`
for `emoji-truecolor.tlf`). `emoji-truecolor.tlf` embeds a 24-bit truecolor layer
in addition to 16- and 256-color layers (larger file).

Licensing note:
MIT header embedded in the font comment (see rebuild script / font lines);
glyph bitmaps follow whichever color emoji font the build environment uses when
the maintenance script is run.

## TOIlet fonts

Assets:
`bfraktur.tlf`, `circle.tlf`, `emboss.tlf`, `emboss2.tlf`,
`fauxcyrillic.tlf`, `fullcyrillic.tlf`, `future.tlf`, `letter.tlf`,
`pagga.tlf`, `smblock.tlf`, `smbraille.tlf`, `wideterm.tlf`

Source:
TOIlet official distribution and `cmatsuoka/figlet-fonts`

Licensing note:
Each file states that it is free software under the WTFPL v2.

Not bundled from TOIlet:
`ascii9.tlf`, `ascii12.tlf`, `smascii9.tlf`, `smascii12.tlf`,
`bigascii9.tlf`, `bigascii12.tlf`, `mono9.tlf`, `mono12.tlf`,
`smmono9.tlf`, `smmono12.tlf`, `bigmono9.tlf`, `bigmono12.tlf`,
`biggray9.tlf`, `biggray12.tlf` (distributed there as compressed binary `.tlf`
assets with no standalone in-file license header in this form).

## JavE fonts

Assets:
`3d_diagonal.flf`, `b1ff.flf`, `icl-1900.flf`, `ascii_new_roman.flf`,
`bear.flf`, `bigfig.flf`, `blocks.flf`, `braced.flf`, `broadway_kb.flf`,
`cards.flf`, `chiseled.flf`, `cola.flf`, `crazy.flf`, `dancingfont.flf`,
`dietcola.flf`, `doubleshorts.flf`, `filter.flf`, `fire_font-k.flf`,
`fire_font-s.flf`, `flipped.flf`, `flowerpower.flf`, `funface.flf`,
`funfaces.flf`, `ghost.flf`, `ghoulish.flf`, `heart_left.flf`,
`heart_right.flf`, `hieroglyphs.flf`, `horizontalleft.flf`,
`horizontalright.flf`, `impossible.flf`, `knob.flf`, `konto.flf`,
`kontoslant.flf`, `lildevil.flf`, `lineblocks.flf`, `merlin1.flf`,
`merlin2.flf`, `modular.flf`, `muzzle.flf`, `puzzle.flf`,
`rammstein.flf`, `rotated.flf`, `s-relief.flf`, `smallcaps.flf`,
`soft.flf`, `starstrips.flf`, `swampland.flf`, `sweet.flf`, `test1.flf`,
`train.flf`, `twisted.flf`, `varsity.flf`, `wetletter.flf`, `wow.flf`

Source:
JavE collection via `cmatsuoka/figlet-fonts`

Licensing note:
Each imported font header contains:
`Permission is hereby given to modify this font, as long as the modifier's name is placed on a comment line.`

## JavE mirror archive (zip-wrapped bundle)

Source:
[JavE FIGlet fonts](http://www.jave.de/figlet/fonts.html) (donor `readme.txt`; each shipped `*.flf` was a Zip archive containing one plaintext FIGlet font, unpacked here).

Not imported (left in the donor folder intentionally):
`Georgia11.flf`, `georgi16.flf` — designed to mimic the commercial Georgia typeface; headers do not include the usual JavE redistribution line, so rights are unclear.

Assets (175 fonts from this import; control files `lower.flc`, `null.flc`,
`rot13.flc`, `rot13.flf`, `upper.flc` are listed under FIGlet 2.2.5 above):

`1row.flf`, `3-d.flf`, `3x5.flf`, `4max.flf`, `5lineoblique.flf`, `acrobatic.flf`, `alligator.flf`,
`alligator2.flf`, `alligator3.flf`, `alpha.flf`, `alphabet.flf`, `amc3line.flf`, `amc3liv1.flf`,
`amcaaa01.flf`, `amcneko.flf`, `amcrazo2.flf`, `amcrazor.flf`, `amcslash.flf`, `amcslder.flf`,
`amcthin.flf`, `amctubes.flf`, `amcun1.flf`, `arrows.flf`, `banner3-d.flf`, `banner3.flf`,
`banner4.flf`, `barbwire.flf`, `basic.flf`, `bell.flf`, `benjamin.flf`, `bigchief.flf`, `binary.flf`,
`bolger.flf`, `bright.flf`, `broadway.flf`, `bulbhead.flf`, `calgphy2.flf`, `caligraphy.flf`,
`catwalk.flf`, `chunky.flf`, `coinstak.flf`, `colossal.flf`, `computer.flf`, `contessa.flf`,
`contrast.flf`, `cosmic.flf`, `cosmike.flf`, `crawford.flf`, `cricket.flf`, `cyberlarge.flf`,
`cybermedium.flf`, `cybersmall.flf`, `cygnet.flf`, `danc4.flf`, `decimal.flf`, `defleppard.flf`,
`diamond.flf`, `doh.flf`, `dotmatrix.flf`, `double.flf`, `drpepper.flf`,
`dwhistled.flf`, `eftichess.flf`, `eftifont.flf`, `eftipiti.flf`, `eftirobot.flf`, `eftitalic.flf`,
`eftiwall.flf`, `eftiwater.flf`, `fender.flf`, `fourtops.flf`, `fraktur.flf`, `fuzzy.flf`,
`glenyn.flf`, `goofy.flf`, `gothic.flf`, `gradient.flf`, `graffiti.flf`, `greek.flf`, `henry3d.flf`,
`hex.flf`, `hollywood.flf`, `invita.flf`, `isometric1.flf`, `isometric2.flf`, `isometric3.flf`,
`isometric4.flf`, `italic.flf`, `jacky.flf`, `jazmine.flf`, `jerusalem.flf`, `katakana.flf`,
`kban.flf`, `keyboard.flf`, `larry3d.flf`, `lcd.flf`, `letters.flf`, `linux.flf`, `lockergnome.flf`, `madrid.flf`, `marquee.flf`, `maxfour.flf`,
`mike.flf`, `moscow.flf`, `mshebrew210.flf`,
`nancyj-fancy.flf`, `nancyj-underlined.flf`, `nancyj.flf`, `nipples.flf`,
`nscript.flf`, `ntgreek.flf`, `nvscript.flf`, `o8.flf`, `octal.flf`, `ogre.flf`,
`oldbanner.flf`, `os2.flf`, `pawp.flf`, `peaks.flf`, `peaksslant.flf`, `pebbles.flf`, `pepper.flf`,
`poison.flf`, `puffy.flf`, `rectangles.flf`, `red_phoenix.flf`, `relief.flf`, `relief2.flf`,
`reverse.flf`, `roman.flf`, `rounded.flf`, `rowancap.flf`, `rozzo.flf`,
`runic.flf`, `santaclara.flf`, `sblood.flf`, `serifcap.flf`, `shimrod.flf`, `short.flf`, `slide.flf`,
`slscript.flf`, `smisome1.flf`, `smkeyboard.flf`, `smpoison.flf`, `smtengwar.flf`, `spliff.flf`,
`stacey.flf`, `stampate.flf`, `stampatello.flf`, `starwars.flf`, `stellar.flf`, `stforek.flf`,
`stop.flf`, `straight.flf`, `sub-zero.flf`, `swan.flf`, `tanja.flf`, `tengwar.flf`, `thick.flf`,
`thin.flf`, `threepoint.flf`, `ticks.flf`, `ticksslant.flf`, `tiles.flf`, `tinker-toy.flf`,
`tombstone.flf`, `trek.flf`, `tsalagi.flf`, `tubular.flf`, `twopoint.flf`, `univers.flf`, `usaflag.flf`, `weird.flf`, `whimsy.flf`

Licensing note:
Spot-checks of these files show either the JavE modify permission (quoted in the section above), or a standard `flf2a$` / `flc2a` FIGlet header with author attribution—consistent with the usual FIGlet and JavE redistribution practice. Font names may reference trademarks or third-party styles; that does not extend a trademark license—only the FIGlet font files as such.

## CJK font

Assets:
`gb16fs.flf`

Source:
CJK bundle via `cmatsuoka/figlet-fonts`

Licensing note:
The header grants permission to use, copy, modify, and distribute the font and its documentation without fee, subject to notice retention and no-endorsement terms.

## Other bundled font

Assets:
`makisupa.flf`

Source:
Grey Wolf Web Works (conversion by Evgeny Stepanischev)

Licensing note:
GPL and OFL. See `fonts/LICENSE`.

## xero/figlet-fonts — Glenn Chappell figlet 2.1 variants

Assets:
`small_shadow.flf`, `small_slant.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`)

Licensing note:
Each file header contains:
`Permission is hereby given to modify this font, as long as the modifier's name is placed on a comment line.`
(Glenn Chappell, figlet release 2.1 — 12 Aug 1994)

## xero/figlet-fonts — JavE fonts

Assets:
`js_capital_curves.flf`, `thorned.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`)

Licensing note:
Each file header contains:
`Permission is hereby given to modify this font, as long as the modifier's name is placed on a comment line.`
(via JavE FIGlet font export assistant, http://www.jave.de)

## xero/figlet-fonts — Terminus-based fonts

Assets:
`terminus.flf`, `terminus_dots.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`);
based on Terminus bitmap font (`https://terminus-font.sourceforge.net/`),
generated with `https://gitlab.com/unlessgames/flfgen`

Licensing note:
Terminus is released under the SIL Open Font License 1.1. See `fonts/LICENSE`.

## xero/figlet-fonts — TOIlet-derived font

Assets:
`mono9.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`);
modified from TOIlet's `smmono9.tlf` by @BooeySays, 2020

Licensing note:
Derived from a TOIlet font (WTFPL v2; see TOIlet section above).

## xero/figlet-fonts — patorjk creations and conversions

Assets:
`3d-ascii.flf`, `ansi_regular.flf`, `ansi_shadow.flf`, `bloody.flf`, `calvin_s.flf`,
`crawford2.flf`, `delta_corps_priest_1.flf`, `electronic.flf`, `elite.flf`,
`patorjks_cheese.flf`, `patorjk-hex.flf`, `stronger_than_all.flf`,
`the_edge.flf`, `this.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`);
created or converted by patorjk (`http://patorjk.com/figfont-editor`)

Licensing note:
Fonts created with patorjk.com's FIGfont editor or converted by patorjk from
the ASCII-art community. Several are derived from TheDraw TDF format via the
historical roysac.com archive. Shared without restriction on patorjk.com and
distributed widely in the figlet community.

## xero/figlet-fonts — Joan Stark ASCII art conversions

Assets:
`js_block_letters.flf`, `js_bracket_letters.flf`, `js_cursive.flf`,
`js_stick_letters.flf`, `stick_letters.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`);
ASCII art by Joan Stark (`http://www.geocities.com/SoHo/7373/`),
FIGlet conversions by patorjk (April 2008)

Licensing note:
Joan Stark's ASCII art was shared publicly for non-commercial use with
attribution. The FIGlet conversions are by patorjk and carry no additional
restrictions.

## xero/figlet-fonts — other community fonts

Assets:
`big_money-ne.flf`, `big_money-nw.flf`, `big_money-se.flf`, `big_money-sw.flf`,
`cursive.flf`, `crawford2.flf`, `halfiwi.flf`, `koholint.flf`, `kompaktblk.flf`,
`maxiwi.flf`, `miniwi.flf`, `six-fo.flf`, `stencil.flf`, `tubes-regular.flf`,
`tubes-smushed.flf`, `ublk.flf`

Source:
`xero/figlet-fonts` (`https://github.com/xero/figlet-fonts`)

Licensing note:
- `cursive.flf`: based on `cursive(6)` by Jan Wolter (24 Jul 1985); FIGlet conversion
  by Wendell Hicken (5 Mar 1994), amended by Jerrad Pierce (8 Aug 2002). No explicit
  redistribution restriction.
- `crawford2.flf`: update of Crawford by patorjk (Jan 2008); original by Rowan Crawford
  from alt.ascii-art, no explicit restriction.
- `big_money-ne.flf`, `big_money-nw.flf`, `big_money-se.flf`, `big_money-sw.flf`: no
  license header; widely redistributed in figlet community.
- `halfiwi.flf`, `maxiwi.flf`, `miniwi.flf`: based on the miniwi bitmap font
  (`https://github.com/sshbio/miniwi`); published without a license file, shared
  publicly on GitHub.
- `koholint.flf`, `kompaktblk.flf`, `six-fo.flf`, `ublk.flf`: created by netspooky
  (`https://n0.lol`); published without a license file, shared publicly on GitHub.
- `stencil.flf`: by Subskybox (subskybox@gmail.com); shared without restriction.
- `tubes-regular.flf`, `tubes-smushed.flf`: by Phantomwise; created with patorjk.com's
  FIGfont editor, shared without restriction.

## Complete inventory (all bundled files)

`1row.flf`, `3-d.flf`, `3d-ascii.flf`, `3d_diagonal.flf`, `3x5.flf`, `4max.flf`,
`5lineoblique.flf`, `5x7.flf`, `6x10.flf`, `6x9.flf`, `b1ff.flf`, `danc4.flf`,
`icl-1900.flf`, `acrobatic.flf`, `alligator.flf`, `alligator2.flf`, `alligator3.flf`,
`alpha.flf`, `alphabet.flf`, `amc3line.flf`, `amc3liv1.flf`, `amcaaa01.flf`, `amcneko.flf`,
`amcrazo2.flf`, `amcrazor.flf`, `amcslash.flf`, `amcslder.flf`, `amcthin.flf`,
`amctubes.flf`, `amcun1.flf`, `ansi_regular.flf`, `ansi_shadow.flf`, `arrows.flf`,
`ascii_new_roman.flf`, `avatar.flf`, `banner.flf`, `banner3-d.flf`, `banner3.flf`,
`banner4.flf`, `barbwire.flf`, `basic.flf`, `bear.flf`, `bell.flf`, `benjamin.flf`,
`bfraktur.tlf`, `big.flf`, `big_money-ne.flf`, `big_money-nw.flf`, `big_money-se.flf`,
`big_money-sw.flf`, `bigchief.flf`, `bigfig.flf`, `binary.flf`, `block.flf`, `blocks.flf`,
`bloody.flf`, `bolger.flf`, `braced.flf`, `bright.flf`, `broadway.flf`, `broadway_kb.flf`,
`bubble.flf`, `bulbhead.flf`, `calgphy2.flf`, `caligraphy.flf`, `calvin_s.flf`, `cards.flf`,
`catwalk.flf`, `chiseled.flf`, `chunky.flf`, `circle.tlf`, `coinstak.flf`, `cola.flf`,
`colossal.flf`, `computer.flf`, `contessa.flf`, `contrast.flf`, `cosmic.flf`, `cosmike.flf`,
`crawford.flf`, `crawford2.flf`, `crazy.flf`, `cricket.flf`, `cursive.flf`, `cyberlarge.flf`,
`cybermedium.flf`, `cybersmall.flf`, `cygnet.flf`, `dancingfont.flf`, `decimal.flf`,
`defleppard.flf`, `delta_corps_priest_1.flf`, `diamond.flf`, `dietcola.flf`, `digital.flf`,
`doh.flf`, `doom.flf`, `dotmatrix.flf`, `double.flf`, `doubleshorts.flf`, `drpepper.flf`,
`dwhistled.flf`, `eftichess.flf`, `eftifont.flf`, `eftipiti.flf`, `eftirobot.flf`,
`eftitalic.flf`, `eftiwall.flf`, `eftiwater.flf`, `electronic.flf`, `elite.flf`,
`emboss.tlf`, `emboss2.tlf`, `emoji-truecolor.tlf`, `emoji.tlf`, `epic.flf`, `fauxcyrillic.tlf`, `fender.flf`,
`filter.flf`, `fire_font-k.flf`, `fire_font-s.flf`, `flipped.flf`, `flowerpower.flf`,
`fourtops.flf`, `fraktur.flf`, `frango.flc`, `fullcyrillic.tlf`, `funface.flf`,
`funfaces.flf`, `future.tlf`, `fuzzy.flf`, `gb16fs.flf`, `ghost.flf`, `ghoulish.flf`,
`glenyn.flf`, `goofy.flf`, `gothic.flf`, `graceful.flf`, `gradient.flf`, `graffiti.flf`,
`greek.flf`, `halfiwi.flf`, `heart_left.flf`, `heart_right.flf`, `henry3d.flf`, `hex.flf`,
`hieroglyphs.flf`, `hollywood.flf`, `horizontalleft.flf`, `horizontalright.flf`, `hz.flc`,
`impossible.flf`, `invita.flf`, `isometric1.flf`, `isometric2.flf`, `isometric3.flf`,
`isometric4.flf`, `italic.flf`, `ivrit.flf`, `jacky.flf`, `jazmine.flf`, `jerusalem.flf`,
`jis0201.flc`, `js_block_letters.flf`, `js_bracket_letters.flf`, `js_capital_curves.flf`,
`js_cursive.flf`, `js_stick_letters.flf`, `katakana.flf`, `kban.flf`, `keyboard.flf`,
`knob.flf`, `koholint.flf`, `kompaktblk.flf`, `konto.flf`, `kontoslant.flf`, `larry3d.flf`,
`lcd.flf`, `lean.flf`, `letter.tlf`, `letters.flf`, `lildevil.flf`, `lineblocks.flf`,
`linux.flf`, `lockergnome.flf`, `lower.flc`, `madrid.flf`, `makisupa.flf`, `marquee.flf`,
`maxfour.flf`, `maxiwi.flf`, `merlin1.flf`, `merlin2.flf`, `mike.flf`, `mini.flf`,
`miniwi.flf`, `mirror.flf`, `mnemonic.flf`, `modular.flf`, `mono9.flf`, `morse.flf`,
`moscow.flf`, `mshebrew210.flf`, `muzzle.flf`, `nancyj-fancy.flf`, `nancyj-underlined.flf`,
`nancyj.flf`, `nipples.flf`, `nscript.flf`, `ntgreek.flf`, `null.flc`, `nvscript.flf`,
`o8.flf`, `octal.flf`, `ogre.flf`, `oldbanner.flf`, `os2.flf`, `pagga.tlf`,
`patorjk-hex.flf`, `patorjks_cheese.flf`, `pawp.flf`, `peaks.flf`, `peaksslant.flf`,
`pebbles.flf`, `pepper.flf`, `poison.flf`, `puffy.flf`, `puzzle.flf`, `pyramid.flf`,
`rammstein.flf`, `rectangles.flf`, `red_phoenix.flf`, `relief.flf`, `relief2.flf`,
`reverse.flf`, `roman.flf`, `rot13.flc`, `rot13.flf`, `rotated.flf`, `rounded.flf`,
`rowancap.flf`, `rozzo.flf`, `runic.flf`, `runyc.flf`, `s-relief.flf`, `santaclara.flf`,
`sblood.flf`, `script.flf`, `serifcap.flf`, `shadow.flf`, `shimrod.flf`, `short.flf`,
`six-fo.flf`, `slant.flf`, `slide.flf`, `slscript.flf`, `small.flf`, `small_shadow.flf`,
`small_slant.flf`, `smallcaps.flf`, `smblock.tlf`, `smbraille.tlf`, `smisome1.flf`,
`smkeyboard.flf`, `smpoison.flf`, `smscript.flf`, `smshadow.flf`, `smslant.flf`,
`smtengwar.flf`, `soft.flf`, `speed.flf`, `spliff.flf`, `stacey.flf`, `stampate.flf`,
`stampatello.flf`, `standard.flf`, `starstrips.flf`, `starwars.flf`, `stellar.flf`,
`stencil.flf`, `stforek.flf`, `stick_letters.flf`, `stop.flf`, `straight.flf`,
`stronger_than_all.flf`, `sub-zero.flf`, `swampland.flf`, `swan.flf`, `sweet.flf`,
`tanja.flf`, `tengwar.flf`, `term.flf`, `terminus.flf`, `terminus_dots.flf`, `test1.flf`,
`the_edge.flf`, `thick.flf`, `thin.flf`, `this.flf`, `thorned.flf`, `threepoint.flf`,
`ticks.flf`, `ticksslant.flf`, `tiles.flf`, `tinker-toy.flf`, `tombstone.flf`, `train.flf`,
`trek.flf`, `tsalagi.flf`, `tubes-regular.flf`, `tubes-smushed.flf`, `tubular.flf`,
`twisted.flf`, `twopoint.flf`, `ublk.flf`, `univers.flf`, `upper.flc`, `usaflag.flf`,
`utf8.flc`, `varsity.flf`, `wavy.flf`, `weird.flf`, `wetletter.flf`, `whimsy.flf`,
`wideterm.tlf`, `wow.flf`.
