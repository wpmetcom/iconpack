<?php
/**
 * ElementsKit Icon Pack Sync Script
 * Run via CLI: npm run load-iconpack & npm run iconpack
 * Regenerates all icon pack files from icomoon source.
 * Iconpack generator source : https://github.com/wpmetcom/iconpack
 * 6 Steps:
	* 1. Extract zips from src/Icomoon/ (uploaded by user from IcoMoon app)
	* 2. Parse all glyphs from elementskit.svg (IcoMoon font file)
	* 3. Copy woff font to icon-pack assets
	* 4. Regenerate ekiticons.scss (compiled to css by Grunt/build)
	* 5. Regenerate icons.json { "icon-name": { viewBox, paths }
	* 6. Regenerate editor.css from ekit-* glyphs (widget icons in panel)
 * 
 */




// ─── ANSI helpers ────────────────────────────────────────────────────────────
$ansi = stream_isatty( STDOUT );
function cli_line( string $icon, string $color, string $msg ): void {
	global $ansi;
	if ( $ansi ) {
		fwrite( STDOUT, "\033[{$color}m{$icon}\033[0m {$msg}\n" );
	} else {
		fwrite( STDOUT, "{$icon} {$msg}\n" );
	}
}
function ok( string $msg )   { cli_line( '→ ', '32', $msg ); }   // green
function info( string $msg ) { cli_line( 'ℹ  ', '36', $msg ); }   // cyan
function warn( string $msg ) { cli_line( '⚠', '33', $msg ); }   // yellow
function fail( string $msg ) { cli_line( '✖', '31', $msg ); exit(1); } // red

// ─── Banner ───────────────────────────────────────────────────────────────────
if ( $ansi ) {
	fwrite( STDOUT, "\033[1;33m\n  ╔══════════════════════════════════════════╗\n  ║  ⚡  ElementsKit Icon Pack Generator     ║\n  ╚══════════════════════════════════════════╝\033[0m\n" );
} else {
	fwrite( STDOUT, "\n  +--------------------------------------------+\n  |  ⚡  ElementsKit Icon Pack Generator  |\n  +--------------------------------------------+\n" );
}

$plugin_dir = dirname( __DIR__ );

if ( ! is_dir( $plugin_dir ) ) {
	fail( "Directory not found: $plugin_dir" );
}

define( 'ICOMOON',        __DIR__ . '/src' );
define( 'ICOMOON_ASSETS', __DIR__ . '/src/Fonts & Svg' );
define( 'ICOMOON_SYSTEM', __DIR__ . '/src/Systems File' );
define( 'PLUGIN_DIR',     $plugin_dir );
define( 'ICON_PACK',      PLUGIN_DIR . '/modules/elementskit-icon-pack/assets' );
define( 'WIDGET_ASSETS',  PLUGIN_DIR . '/widgets/init/assets' );









// ─── 0. Extract zips from Icomoon/ folder ────────────────────────────────────
$icomoon_zip_dir = __DIR__ . '/src/Icomoon';
if ( is_dir( $icomoon_zip_dir ) ) {
	$zips = glob( $icomoon_zip_dir . '/*.zip' );
	if ( count( $zips ) < 2 ) {
		fail( 'Please upload 2 zip files from IcoMoon into src/Icomoon/ before running this script.' );
	}

	if ( ! empty( $zips ) ) {
		info( 'Extracting zips from Icomoon/ ...' );
		foreach ( $zips as $zip_path ) {
			$zip = new ZipArchive;
			if ( $zip->open( $zip_path ) !== true ) {
				warn( 'Could not open: ' . basename( $zip_path ) );
				continue;
			}
			$has_fonts = false;
			$has_svg   = false;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( strpos( $name, 'fonts/' ) === 0 ) { $has_fonts = true; }
				if ( strpos( $name, 'SVG/' ) === 0 )   { $has_svg   = true; }
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( ( $has_fonts && strpos( $name, 'fonts/' ) === 0 ) || ( $has_svg && strpos( $name, 'SVG/' ) === 0 ) ) {
					$dest = ICOMOON_ASSETS . '/' . $name;
					if ( ! is_dir( dirname( $dest ) ) ) { mkdir( dirname( $dest ), 0755, true ); }
					if ( substr( $name, -1 ) !== '/' ) { file_put_contents( $dest, $zip->getFromIndex( $i ) ); }
				}
			}
			$zip->close();
			ok( 'Extracted: ' . basename( $zip_path ) . ( $has_fonts ? ' (fonts/)' : '' ) . ( $has_svg ? ' (SVG/)' : '' ) );
		}
		fwrite( STDOUT, "\n" );
	}
}









// ─── 1. Parse all glyphs from elementskit.svg ────────────────────────────────
$svg = simplexml_load_file( ICOMOON_ASSETS . '/fonts/elementskit.svg' );
if ( ! $svg ) {
	fail( 'Cannot read icomoon/Fonts & Svg/fonts/elementskit.svg' );
}

$icon_pack_glyphs = [];
foreach ( $svg->defs->font->glyph as $glyph ) {
	$name    = (string) $glyph['glyph-name'];
	$unicode = (string) $glyph['unicode'];

	if ( empty( $name ) || empty( $unicode ) || $name === '.notdef' ) {
		continue;
	}

	$codepoints = [];
	$len        = mb_strlen( $unicode, 'UTF-8' );
	for ( $i = 0; $i < $len; $i++ ) {
		$char         = mb_substr( $unicode, $i, 1, 'UTF-8' );
		$codepoints[] = strtolower( dechex( mb_ord( $char ) ) );
	}

	$icon_pack_glyphs[] = [ 'name' => $name, 'hex' => implode( '', $codepoints ) ];
}

// ─── 1b. widget_glyphs — scan lite + pro handler PHP files for ekit-* icon classes ───
$widget_glyphs   = [];
$existing_editor = WIDGET_ASSETS . '/css/editor.css';
$widget_source   = 'SVG + widget handler PHP files';

$svg_index = [];
foreach ( $icon_pack_glyphs as $g ) {
	$svg_index[ $g['name'] ] = $g['hex'];
}

$pro_dir       = dirname( PLUGIN_DIR ) . '/elementskit';
$search_dirs   = [ PLUGIN_DIR, is_dir( $pro_dir ) ? $pro_dir : null ];
$seen          = [];
foreach ( array_filter( $search_dirs ) as $dir ) {
	foreach ( glob( $dir . '/widgets/*/*-handler.php' ) as $file ) {
		$src = file_get_contents( $file );
		if ( preg_match( '/get_icon[^}]+return\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $src, $m ) ) {
			preg_match_all( '/ekit-([\w-]+)/', $m[1], $classes );
			foreach ( $classes[1] as $name ) {
				if ( $name === 'widget-icon' || isset( $seen[ $name ] ) ) {
					continue;
				}
				if ( isset( $svg_index[ $name ] ) ) {
					$widget_glyphs[] = [ 'name' => $name, 'hex' => $svg_index[ $name ] ];
					$seen[ $name ]   = true;
				}
			}
		}
	}
}

$ansi
	? fwrite( STDOUT, "\n  \033[1;33mActivity Log:\033[0m\n\n" )
	: fwrite( STDOUT, "\n  Activity Log:\n\n" );

ok( 'Parsed elementskit.svg — ' . count( $icon_pack_glyphs ) . ' icon-pack glyphs' );
fwrite( STDOUT, "\n" );
ok( 'Widget panel glyphs (ekit-*) from ' . $widget_source . ': ' . count( $widget_glyphs ) );
fwrite( STDOUT, "\n" );











// ─── 2. Copy woff font ────────────────────────────────────────────────────────
$woff_src  = ICOMOON_ASSETS . '/fonts/elementskit.woff';
$woff_dest = ICON_PACK . '/fonts/elementskit.woff';
$dir       = dirname( $woff_dest );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
if ( copy( $woff_src, $woff_dest ) ) {
	ok( "Font copied  → $woff_dest" );
} else {
	warn( "Could not copy woff to $woff_dest" );
}
fwrite( STDOUT, "\n" );











// ─── 3. Regenerate ekiticons.scss (compiled to css by Grunt/build) ───────────
$scss_header = <<<'SCSS'
// Custom selected icons for elementskit

$ekit-font-family:   "elementskit" !default;
$ekit-font-path:     "../fonts" !default;
$ekit-font-version:  "itek3h" !default;

@font-face {
	font-family: $ekit-font-family;
	src: url("#{$ekit-font-path}/elementskit.woff?#{$ekit-font-version}") format("woff");
	font-weight: normal;
	font-style: normal;
	font-display: swap;
}

%ekit-icon-base {
	font-family: $ekit-font-family !important;
	font-style: normal;
	font-weight: normal;
	font-variant: normal;
	text-transform: none;
	line-height: 1;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}

.elementor-editor-active,
.elementor-widget,
.ekit-wid-con {
	.icon::before {
		@extend %ekit-icon-base;
	}
}

// Icon classes — regenerated by iconpack-sync.php

.icon {
SCSS;

$scss_rules = '';
foreach ( $icon_pack_glyphs as $g ) {
	$scss_rules .= "\t&.icon-{$g['name']}::before,\n";
	$scss_rules .= "\t.ekit-wid-con &.icon-{$g['name']}::before {\n";
	$scss_rules .= "\t\tcontent: \"\\{$g['hex']}\";\n";
	$scss_rules .= "\t}\n\n";
}
$scss_rules .= "}\n";

$scss_path = ICON_PACK . '/sass/ekiticons.scss';
$dir       = dirname( $scss_path );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $scss_path, $scss_header . $scss_rules );
ok( 'Regenerated ekiticons.scss  → run `npm run dev` or `grunt css` to compile to CSS' );
fwrite( STDOUT, "\n" );









// ─── 5. Regenerate icons.json  { "icon-name": { viewBox, paths } }  (SVG map) ─
require_once ICOMOON_SYSTEM . '/svg-to-icon-json.php';
$svg_icons  = svg_dir_to_icon_array( ICOMOON_ASSETS . '/SVG' );
$icons_json = ICON_PACK . '/json/icons.json';
$dir        = dirname( $icons_json );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $icons_json, json_encode( $svg_icons, JSON_UNESCAPED_SLASHES ) );
ok( 'Regenerated icons.json (' . count( $svg_icons ) . ' SVG icons)' );
fwrite( STDOUT, "\n" );






// ─── 6. Regenerate editor.css from ekit-* glyphs ───────────────────────────
/**
 * wp_enqueue_style( 'elementskit-panel', \ElementsKit_Lite::widget_url() . 'init/assets/css/editor.css', [], \ElementsKit_Lite::version() );
 * This loads it in the Elementor editor panel (the left sidebar where you pick widgets). Its purpose there is to render the small widget icon next to each ElementsKit widget name in the panel — that's why it only needs the ekit-* glyphs from get_icon(), not the full icon pack.
 * Each widget tells Elementor which icon to show via public function get_icon()
 * The editor.css contains the CSS that maps ekit-accordion to the actual icon character from the font file.
 * The script scans every widget's handler file, finds get_icon(), reads whatever class it returns, and only those classes end up in editor.css
 */

if ( empty( $icon_pack_glyphs ) ) {
	warn( 'No glyphs found in elementskit.svg — check the font file.' );
} else {
	// Preserve the font-face query-string version from the existing editor.css
	$font_ver = 'fuwixw';
	if ( file_exists( $existing_editor ) ) {
		if ( preg_match( '/elementskit\.woff\?([a-z0-9]+)/', file_get_contents( $existing_editor ), $m ) ) {
			$font_ver = $m[1];
		}
	}

	// ── DYNAMIC: font-face + icon classes (generated from SVG glyphs) ──
	$css  = '/* elementskit widget icons for panel */' . "\r\n";
	$css .= '@font-face{font-family:elementskit;';
	$css .= "src:url(../../../../modules/elementskit-icon-pack/assets/fonts/elementskit.woff?{$font_ver}) format('woff');";
	$css .= 'font-weight:400;font-style:normal}';
	$css .= '.ekit{font-family:elementskit!important;speak:none;font-style:normal;font-weight:400;font-variant:normal;text-transform:none;line-height:1;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}';

	foreach ( $widget_glyphs as $g ) {
		$css .= ".ekit-{$g['name']}:before{content:\"\\{$g['hex']}\"}";
	}

	// ── FIXED: panel styling, dark mode, zoom widget (from editor-static.css) ──
	$static_file = ICOMOON_SYSTEM . '/editor-static.css';
	if ( file_exists( $static_file ) ) {
		$css .= "\r\n" . file_get_contents( $static_file );
	} else {
		warn( 'editor-static.css not found — fixed styles skipped.' );
	}

	$editor_css_path = WIDGET_ASSETS . '/css/editor.css';
	$dir             = dirname( $editor_css_path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( $editor_css_path, $css );
	ok( 'Regenerated editor.css (' . count( $widget_glyphs ) . ' widget icons)' );
	fwrite( STDOUT, "\n" );
}

if ( $ansi ) {
	fwrite( STDOUT, "\033[1;32m\n" );
	fwrite( STDOUT, "   ██████╗ ██████╗ ███╗   ██╗ ██████╗ ██████╗  █████╗ ████████╗███████╗██╗\n" );
	fwrite( STDOUT, "  ██╔════╝██╔═══██╗████╗  ██║██╔════╝ ██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██║\n" );
	fwrite( STDOUT, "  ██║     ██║   ██║██╔██╗ ██║██║  ███╗██████╔╝███████║   ██║   ███████╗██║\n" );
	fwrite( STDOUT, "  ██║     ██║   ██║██║╚██╗██║██║   ██║██╔══██╗██╔══██║   ██║   ╚════██║╚═╝\n" );
	fwrite( STDOUT, "  ╚██████╗╚██████╔╝██║ ╚████║╚██████╔╝██║  ██║██║  ██║   ██║   ███████║██╗\n" );
	fwrite( STDOUT, "   ╚═════╝ ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚══════╝╚═╝\n" );
	fwrite( STDOUT, "\033[0m" );
	fwrite( STDOUT, "\033[1;32m\n  ElementsKit Icon pack sync successfully.\033[0m\n\n" );
} else {
	fwrite( STDOUT, "\n  CONGRATS! ElementsKit Icon pack sync successfully.\n\n" );
}
