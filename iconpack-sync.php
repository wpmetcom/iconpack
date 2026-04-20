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

$elementskit_plugin_dir = dirname( __DIR__ );

if ( ! is_dir( $elementskit_plugin_dir ) ) {
	fail( "Directory not found: $elementskit_plugin_dir" );
}

define( 'ICOMOON_SRC_DIR',           __DIR__ . '/src' );
define( 'ICOMOON_FONTS_AND_SVG_DIR', __DIR__ . '/src/Fonts & Svg' );
define( 'ICOMOON_SYSTEM_FILES_DIR',  __DIR__ . '/src/Systems File' );
define( 'ELEMENTSKIT_PLUGIN_DIR',    $elementskit_plugin_dir );
define( 'ICON_PACK_ASSETS_DIR',      ELEMENTSKIT_PLUGIN_DIR . '/modules/elementskit-icon-pack/assets' );
define( 'WIDGET_INIT_ASSETS_DIR',    ELEMENTSKIT_PLUGIN_DIR . '/widgets/init/assets' );









// ─── 0. Extract zips from Icomoon/ folder ────────────────────────────────────
$icomoon_uploads_dir = __DIR__ . '/src/Icomoon';
if ( is_dir( $icomoon_uploads_dir ) ) {
	$icomoon_zip_files = glob( $icomoon_uploads_dir . '/*.zip' );
	if ( count( $icomoon_zip_files ) < 2 ) {
		fail( 'Please upload 2 zip files from IcoMoon into src/Icomoon/ before running this script.' );
	}

	if ( ! empty( $icomoon_zip_files ) ) {
		info( 'Extracting zips from Icomoon/ ...' );
		foreach ( $icomoon_zip_files as $zip_file_path ) {
			$zip = new ZipArchive;
			if ( $zip->open( $zip_file_path ) !== true ) {
				warn( 'Could not open: ' . basename( $zip_file_path ) );
				continue;
			}
			$zip_has_fonts_folder = false;
			$zip_has_svg_folder   = false;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$zip_entry_name = $zip->getNameIndex( $i );
				if ( strpos( $zip_entry_name, 'fonts/' ) === 0 ) { $zip_has_fonts_folder = true; }
				if ( strpos( $zip_entry_name, 'SVG/' ) === 0 )   { $zip_has_svg_folder   = true; }
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$zip_entry_name = $zip->getNameIndex( $i );
				if ( ( $zip_has_fonts_folder && strpos( $zip_entry_name, 'fonts/' ) === 0 ) || ( $zip_has_svg_folder && strpos( $zip_entry_name, 'SVG/' ) === 0 ) ) {
					$dest = ICOMOON_FONTS_AND_SVG_DIR . '/' . $zip_entry_name;
					if ( ! is_dir( dirname( $dest ) ) ) { mkdir( dirname( $dest ), 0755, true ); }
					if ( substr( $zip_entry_name, -1 ) !== '/' ) { file_put_contents( $dest, $zip->getFromIndex( $i ) ); }
				}
			}
			$zip->close();
			ok( 'Extracted: ' . basename( $zip_file_path ) . ( $zip_has_fonts_folder ? ' (fonts/)' : '' ) . ( $zip_has_svg_folder ? ' (SVG/)' : '' ) );
		}
		fwrite( STDOUT, "\n" );
	}
}









// ─── 1. Parse all glyphs from elementskit.svg ────────────────────────────────
$svg = simplexml_load_file( ICOMOON_FONTS_AND_SVG_DIR . '/fonts/elementskit.svg' );
if ( ! $svg ) {
	fail( 'Cannot read icomoon/Fonts & Svg/fonts/elementskit.svg' );
}

$all_icon_glyphs = [];
foreach ( $svg->defs->font->glyph as $glyph ) {
	$glyph_name = (string) $glyph['glyph-name'];
	$unicode    = (string) $glyph['unicode'];

	if ( empty( $glyph_name ) || empty( $unicode ) || $glyph_name === '.notdef' ) {
		continue;
	}

	$unicode_codepoints = [];
	$unicode_char_count = mb_strlen( $unicode, 'UTF-8' );
	for ( $i = 0; $i < $unicode_char_count; $i++ ) {
		$unicode_char         = mb_substr( $unicode, $i, 1, 'UTF-8' );
		$unicode_codepoints[] = strtolower( dechex( mb_ord( $unicode_char ) ) );
	}

	$all_icon_glyphs[] = [ 'name' => $glyph_name, 'hex' => implode( '', $unicode_codepoints ) ];
}

// ─── 1b. widget_panel_glyphs — scan lite + pro handler PHP files for ekit-* icon classes ───
$widget_panel_glyphs       = [];
$existing_editor_css_path  = WIDGET_INIT_ASSETS_DIR . '/css/editor.css';
$widget_glyph_source_label = 'SVG + widget handler PHP files';

$glyph_name_to_hex_map = [];
foreach ( $all_icon_glyphs as $glyph ) {
	$glyph_name_to_hex_map[ $glyph['name'] ] = $glyph['hex'];
}

$elementskit_pro_dir  = dirname( ELEMENTSKIT_PLUGIN_DIR ) . '/elementskit';
$widget_search_dirs   = [ ELEMENTSKIT_PLUGIN_DIR, is_dir( $elementskit_pro_dir ) ? $elementskit_pro_dir : null ];
$processed_glyph_names = [];
foreach ( array_filter( $widget_search_dirs ) as $target_dir ) {
	foreach ( glob( $target_dir . '/widgets/*/*-handler.php' ) as $file ) {
		$php_file_contents = file_get_contents( $file );
		if ( preg_match( '/get_icon[^}]+return\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $php_file_contents, $regex_matches ) ) {
			preg_match_all( '/ekit-([\w-]+)/', $regex_matches[1], $classes );
			foreach ( $classes[1] as $glyph_name ) {
				if ( $glyph_name === 'widget-icon' || isset( $processed_glyph_names[ $glyph_name ] ) ) {
					continue;
				}
				if ( isset( $glyph_name_to_hex_map[ $glyph_name ] ) ) {
					$widget_panel_glyphs[]                    = [ 'name' => $glyph_name, 'hex' => $glyph_name_to_hex_map[ $glyph_name ] ];
					$processed_glyph_names[ $glyph_name ]    = true;
				}
			}
		}
	}
}

$ansi
	? fwrite( STDOUT, "\n  \033[1;33mActivity Log:\033[0m\n\n" )
	: fwrite( STDOUT, "\n  Activity Log:\n\n" );

ok( 'Parsed elementskit.svg — ' . count( $all_icon_glyphs ) . ' icon-pack glyphs' );
fwrite( STDOUT, "\n" );
ok( 'Widget panel glyphs (ekit-*) from ' . $widget_glyph_source_label . ': ' . count( $widget_panel_glyphs ) );
fwrite( STDOUT, "\n" );











// ─── 2. Copy woff font ────────────────────────────────────────────────────────
$woff_source_path      = ICOMOON_FONTS_AND_SVG_DIR . '/fonts/elementskit.woff';
$woff_destination_path = ICON_PACK_ASSETS_DIR . '/fonts/elementskit.woff';
$target_dir            = dirname( $woff_destination_path );
if ( ! is_dir( $target_dir ) ) {
	mkdir( $target_dir, 0755, true );
}
if ( copy( $woff_source_path, $woff_destination_path ) ) {
	ok( "Font copied  → $woff_destination_path" );
} else {
	warn( "Could not copy woff to $woff_destination_path" );
}
fwrite( STDOUT, "\n" );











// ─── 3. Regenerate ekiticons.scss (compiled to css by Grunt/build) ───────────
$scss_file_header = <<<'SCSS'
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

$scss_icon_rules = '';
foreach ( $all_icon_glyphs as $glyph ) {
	$scss_icon_rules .= "\t&.icon-{$glyph['name']}::before,\n";
	$scss_icon_rules .= "\t.ekit-wid-con &.icon-{$glyph['name']}::before {\n";
	$scss_icon_rules .= "\t\tcontent: \"\\{$glyph['hex']}\";\n";
	$scss_icon_rules .= "\t}\n\n";
}
$scss_icon_rules .= "}\n";

$ekiticons_scss_path = ICON_PACK_ASSETS_DIR . '/sass/ekiticons.scss';
$target_dir          = dirname( $ekiticons_scss_path );
if ( ! is_dir( $target_dir ) ) {
	mkdir( $target_dir, 0755, true );
}
file_put_contents( $ekiticons_scss_path, $scss_file_header . $scss_icon_rules );
ok( 'Regenerated ekiticons.scss  → run `npm run dev` or `grunt css` to compile to CSS' );
fwrite( STDOUT, "\n" );









// ─── 5. Regenerate icons.json  { "icon-name": { viewBox, paths } }  (SVG map) ─
require_once ICOMOON_SYSTEM_FILES_DIR . '/svg-to-icon-json.php';
$svg_icon_map    = svg_dir_to_icon_array( ICOMOON_FONTS_AND_SVG_DIR . '/SVG' );
$icons_json_path = ICON_PACK_ASSETS_DIR . '/json/icons.json';
$target_dir      = dirname( $icons_json_path );
if ( ! is_dir( $target_dir ) ) {
	mkdir( $target_dir, 0755, true );
}
file_put_contents( $icons_json_path, json_encode( $svg_icon_map, JSON_UNESCAPED_SLASHES ) );
ok( 'Regenerated icons.json (' . count( $svg_icon_map ) . ' SVG icons)' );
fwrite( STDOUT, "\n" );






// ─── 6. Regenerate editor.css from ekit-* glyphs ───────────────────────────
/**
 * wp_enqueue_style( 'elementskit-panel', \ElementsKit_Lite::widget_url() . 'init/assets/css/editor.css', [], \ElementsKit_Lite::version() );
 * This loads it in the Elementor editor panel (the left sidebar where you pick widgets). Its purpose there is to render the small widget icon next to each ElementsKit widget name in the panel — that's why it only needs the ekit-* glyphs from get_icon(), not the full icon pack.
 * Each widget tells Elementor which icon to show via public function get_icon()
 * The editor.css contains the CSS that maps ekit-accordion to the actual icon character from the font file.
 * The script scans every widget's handler file, finds get_icon(), reads whatever class it returns, and only those classes end up in editor.css
 */

if ( empty( $all_icon_glyphs ) ) {
	warn( 'No glyphs found in elementskit.svg — check the font file.' );
} else {
	// Preserve the font-face query-string version from the existing editor.css
	$font_ver = 'fuwixw';
	if ( file_exists( $existing_editor_css_path ) ) {
		if ( preg_match( '/elementskit\.woff\?([a-z0-9]+)/', file_get_contents( $existing_editor_css_path ), $regex_matches ) ) {
			$font_ver = $regex_matches[1];
		}
	}

	// ── DYNAMIC: font-face + icon classes (generated from SVG glyphs) ──
	$css  = '/* elementskit widget icons for panel */' . "\r\n";
	$css .= '@font-face{font-family:elementskit;';
	$css .= "src:url(../../../../modules/elementskit-icon-pack/assets/fonts/elementskit.woff?{$font_ver}) format('woff');";
	$css .= 'font-weight:400;font-style:normal}';
	$css .= '.ekit{font-family:elementskit!important;speak:none;font-style:normal;font-weight:400;font-variant:normal;text-transform:none;line-height:1;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}';

	foreach ( $widget_panel_glyphs as $glyph ) {
		$css .= ".ekit-{$glyph['name']}:before{content:\"\\{$glyph['hex']}\"}";
	}

	// ── FIXED: panel styling, dark mode, zoom widget (from editor-static.css) ──
	$static_file = ICOMOON_SYSTEM_FILES_DIR . '/editor-static.css';
	if ( file_exists( $static_file ) ) {
		$css .= "\r\n" . file_get_contents( $static_file );
	} else {
		warn( 'editor-static.css not found — fixed styles skipped.' );
	}

	$editor_css_path = WIDGET_INIT_ASSETS_DIR . '/css/editor.css';
	$target_dir      = dirname( $editor_css_path );
	if ( ! is_dir( $target_dir ) ) {
		mkdir( $target_dir, 0755, true );
	}
	file_put_contents( $editor_css_path, $css );
	ok( 'Regenerated editor.css (' . count( $widget_panel_glyphs ) . ' widget icons)' );
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
