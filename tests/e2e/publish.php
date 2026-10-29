<?php
// Test helper: records a fixture update in updates.json. Usage: publish.php <wp-content> plugin|theme <slug> <file> <version> <package-url>
list( , $content, $kind, $slug, $file, $version, $package ) = $argv;
$path    = $content . '/e2e/updates.json';
$updates = is_readable( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
$updates[ $kind . ':' . $slug ] = array(
	'slug'    => $slug,
	'file'    => $file,
	'version' => $version,
	'package' => $package,
);
file_put_contents( $path, json_encode( $updates ) );
