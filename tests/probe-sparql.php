<?php
/**
 * Server-side SPARQL probe — run on the host to see the raw WDQS answer.
 * Usage: /opt/plesk/php/8.4/bin/php probe-sparql.php
 */

$sparql = 'SELECT ?item ?itemLabel ?sl ?dob ?dod WHERE { '
	. '?item wdt:P31 wd:Q5 . '
	. '?item rdfs:label ?itemLabel FILTER(LANG(?itemLabel) = "en") . '
	. 'OPTIONAL { ?item wdt:P569 ?dob } . '
	. '?item wdt:P570 ?dod . '
	. 'FILTER(?dod >= "2026-09-01T00:00:00Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> '
	. '&& ?dod < "2026-10-01T00:00:00Z"^^<http://www.w3.org/2001/XMLSchema#dateTime>) '
	. 'OPTIONAL { ?sl schema:about ?item . ?sl schema:isPartOf <https://en.wikipedia.org/> } '
	. '} ORDER BY ?item LIMIT 5 OFFSET 0';

$url     = 'https://query.wikidata.org/sparql?format=json&query=' . rawurlencode( $sparql );
$context = stream_context_create(
	array(
		'http' => array(
			'method'        => 'GET',
			'header'        => "Accept: application/sparql-results+json\r\nUser-Agent: Obitleague-DeathWire/0.1 (WordPress; +obitleague.co.uk)\r\n",
			'timeout'       => 60,
			'ignore_errors' => true,
		),
	)
);
$body   = file_get_contents( $url, false, $context );
$status = 'n/a';
foreach ( $http_response_header ?? array() as $h ) {
	if ( preg_match( '#^HTTP/#', $h ) ) {
		$status = $h;
	}
}
echo "status: {$status}\n";
echo 'bytes: ' . strlen( (string) $body ) . "\n";
$json = json_decode( (string) $body, true );
echo 'bindings: ' . ( isset( $json['results']['bindings'] ) ? count( $json['results']['bindings'] ) : 'none' ) . "\n";
if ( isset( $json['results']['bindings'] ) ) {
	foreach ( array_slice( $json['results']['bindings'], 0, 3 ) as $b ) {
		echo '  ' . ( $b['itemLabel']['value'] ?? '?' ) . ' dod=' . ( $b['dod']['value'] ?? '?' ) . ' wiki=' . ( $b['sl']['value'] ?? '-' ) . "\n";
	}
} else {
	echo substr( (string) $body, 0, 300 ) . "\n";
}
