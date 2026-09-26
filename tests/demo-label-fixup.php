<?php
/* Apply Wikidata labels (en/mul) to QID-named people; description becomes content. */
$fix = json_decode( file_get_contents( 'C:/Users/guy/Documents/Obitz/Obitleague/work/label-fixup.json' ), true );
foreach ( $fix as $qid => $data ) {
	$post_id = Obitleague\Modules\Import_Service::post_id_by_qid( $qid );
	if ( ! $post_id || ! $data['label'] ) {
		echo $qid . ': skip (no post or label)' . "\n";
		continue;
	}
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_title'   => $data['label'],
			'post_name'    => sanitize_title( $data['label'] ),
			'post_content' => (string) $data['desc'],
		)
	);
	if ( $data['desc'] ) {
		update_post_meta( $post_id, 'obit_role', $data['desc'] );
	}
	echo $qid . ' -> ' . $data['label'] . "\n";
}
echo "done\n";
