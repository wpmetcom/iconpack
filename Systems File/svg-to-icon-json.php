<?php
/**
 * SVG → Icon JSON helper
 *
 * Provides svg_dir_to_icon_array() which reads every *.svg file in a directory
 * and returns an associative array keyed by icon name:
 *
 *   [
 *     "icon-name" => [ "viewBox" => "0 0 32 32", "paths" => ["M..."] ],
 *     ...
 *   ]
 *
 * Usage (standalone):
 *   php svg-to-icon-json.php /path/to/SVG /path/to/output/icons.json
 *
 * Usage (as library):
 *   require_once 'svg-to-icon-json.php';
 *   $icons = svg_dir_to_icon_array( '/path/to/SVG' );
 */

/**
 * Parse all SVG files in $dir and return the icon data array.
 *
 * @param string $dir  Absolute path to the SVG directory.
 * @return array<string, array{viewBox: string, paths: string[]}>
 */
function svg_dir_to_icon_array( string $dir ): array {
	$icons = [];

	foreach ( glob( rtrim( $dir, '/\\' ) . '/*.svg' ) ?: [] as $svg_file ) {
		$icon_name   = pathinfo( $svg_file, PATHINFO_FILENAME );
		$svg_content = file_get_contents( $svg_file );

		$dom = new DOMDocument();
		@$dom->loadXML( $svg_content );

		$svg_el  = $dom->getElementsByTagName( 'svg' )->item( 0 );
		$viewBox = $svg_el ? $svg_el->getAttribute( 'viewBox' ) : '';

		if ( empty( $viewBox ) && $svg_el ) {
			$w       = $svg_el->getAttribute( 'width' ) ?: 32;
			$h       = $svg_el->getAttribute( 'height' ) ?: 32;
			$viewBox = "0 0 $w $h";
		}

		$paths = [];
		foreach ( $dom->getElementsByTagName( 'path' ) as $path ) {
			$d = $path->getAttribute( 'd' );
			if ( $d !== '' ) {
				$paths[] = $d;
			}
		}

		$icons[ $icon_name ] = [ 'viewBox' => $viewBox, 'paths' => $paths ];
	}

	return $icons;
}

// ─── Standalone CLI entry-point ───────────────────────────────────────────────
if ( PHP_SAPI === 'cli' && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	if ( empty( $argv[1] ) ) {
		exit( "Usage: php svg-to-icon-json.php <svg-dir> [output.json]\n" );
	}

	$svg_dir = $argv[1];
	if ( ! is_dir( $svg_dir ) ) {
		exit( "ERROR: Directory not found: $svg_dir\n" );
	}

	$icons  = svg_dir_to_icon_array( $svg_dir );
	$output = json_encode( $icons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! empty( $argv[2] ) ) {
		file_put_contents( $argv[2], $output );
		echo 'Written ' . count( $icons ) . " icons to {$argv[2]}\n";
	} else {
		echo $output . "\n";
	}
}
