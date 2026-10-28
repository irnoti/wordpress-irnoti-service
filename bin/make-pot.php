<?php
// Extracts __()/esc_html__()/esc_html_e() strings (text domain irnoti-service) into languages/irnoti-service.pot. Run: php bin/make-pot.php
$root = dirname( __DIR__ ) . '/irnoti-service';
$out  = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $f ) {
	if ( substr( $f, -4 ) !== '.php' ) { continue; }
	$src = file_get_contents( $f );
	if ( preg_match_all( "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'irnoti-service'/", $src, $m ) ) {
		foreach ( $m[1] as $s ) { $out[ stripcslashes( $s ) ][] = str_replace( dirname( $root ) . '/', '', (string) $f ); }
	}
}
ksort( $out );
$pot = "msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: IRNoti Service\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"X-Domain: irnoti-service\\n\"\n\n";
foreach ( $out as $s => $files ) {
	$pot .= '#: ' . implode( ' ', array_unique( $files ) ) . "\nmsgid " . json_encode( $s, JSON_UNESCAPED_UNICODE ) . "\nmsgstr \"\"\n\n";
}
@mkdir( $root . '/languages' );
file_put_contents( $root . '/languages/irnoti-service.pot', $pot );
echo count( $out ) . " strings\n";
