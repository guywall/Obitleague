/**
 * Obitleague demo seed builder (batched, rate-limit friendly).
 *
 * Sources: Wikipedia "Deaths in <Month> 2026" via the MediaWiki API (facts
 * only: name, death day, age, role snippet; attribution recorded), Wikidata
 * entity API for exact birth/death dates (CC0). Titles are resolved to QIDs
 * in 50-per-request batches; every HTTP call retries on 429 with backoff.
 *
 * Usage: node work/build-seed.cjs
 */
const fs = require('fs');
const path = require('path');

const UA = 'ObitleagueSeed/0.1 (local development; contact: local)';
const W = ( f ) => path.join( __dirname, f );
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const MONTHS = ['January','February','March','April','May','June','July','August','September'];
const MONTH_NUM = Object.fromEntries(
	['January','February','March','April','May','June','July','August','September','October','November','December'].map( ( m, i ) => [ m, i + 1 ] )
);

async function getJSON( url, tries = 4 ) {
	for ( let i = 0; i < tries; i++ ) {
		const res = await fetch( url, { headers: { 'User-Agent': UA } } );
		if ( res.ok ) return res.json();
		if ( 429 === res.status || res.status >= 500 ) {
			const wait = parseInt( res.headers.get( 'retry-after' ) ?? '0', 10 ) || ( 5 * ( i + 1 ) * ( i + 1 ) );
			console.log( `  .. HTTP ${res.status}; backing off ${wait}s` );
			await sleep( wait * 1000 );
			continue;
		}
		throw new Error( `HTTP ${res.status}` );
	}
	throw new Error( 'retries exhausted' );
}

function parseMonth( file, month, year = 2026 ) {
	const data = JSON.parse( fs.readFileSync( W( file ), 'utf8' ) );
	const text = data.parse?.wikitext ?? '';
	const out = [];
	const dayRe = /===\s*(\d{1,2})\s*===/g;
	const days = [];
	let m;
	while ( ( m = dayRe.exec( text ) ) !== null ) days.push( { day: parseInt( m[1], 10 ), start: m.index } );
	for ( let i = 0; i < days.length; i++ ) {
		const section = text.slice( days[i].start, days[i + 1]?.start ?? text.length );
		for ( const line of section.split( '\n' ) ) {
			if ( ! line.startsWith( '*' ) ) continue;
			const em = line.match( /^\*\s*\[\[([^\]|]+)(?:\|[^\]]+)?\]\]\s*,\s*(\d{1,3})\s*,\s*(.*)$/ );
			if ( ! em ) continue;
			const role = em[3]
				.replace( /<ref[^>]*>[\s\S]*?<\/ref>/g, '' ).replace( /<ref[^/>]*\/>/g, '' )
				.replace( /\{\{[^}]*\}\}/g, '' )
				.replace( /\[\[([^\]|]+)\|([^\]]+)\]\]/g, '$2' ).replace( /\[\[([^\]]+)\]\]/g, '$1' )
				.replace( /\s*\(.*?\)\s*/g, ' ' ).replace( /''/g, '' )
				.replace( /\s+/g, ' ' ).trim().replace( /[.,;]+$/, '' );
			out.push( {
				name: em[1].trim(), age: parseInt( em[2], 10 ),
				death: `${year}-${String( MONTH_NUM[ month ] ).padStart( 2, '0' )}-${String( days[i].day ).padStart( 2, '0' )}`,
				role: role.slice( 0, 160 ), enwiki: em[1].trim().replace( / /g, '_' ),
			} );
		}
	}
	return out;
}

/** Resolve up to 50 titles per request to QIDs. Returns {title: qid|null}. */
async function resolveTitles( titles ) {
	const map = {};
	for ( let i = 0; i < titles.length; i += 50 ) {
		const chunk = titles.slice( i, i + 50 );
		const q = new URLSearchParams( {
			action: 'query', titles: chunk.join( '|' ), prop: 'pageprops',
			ppprop: 'wikibase_item', format: 'json', formatversion: '2', redirects: '1',
		} );
		const data = await getJSON( `https://en.wikipedia.org/w/api.php?${q}` );
		for ( const page of data.query?.pages ?? [] ) {
			map[ page.title.replace( / /g, '_' ) ] = page.pageprops?.wikibase_item ?? null;
		}
		await sleep( 1500 );
	}
	return map;
}

/** Batch birth/death dates for QIDs (50 per request). */
async function entityFacts( qids ) {
	const facts = {};
	for ( let i = 0; i < qids.length; i += 50 ) {
		const batch = qids.slice( i, i + 50 );
		const q = new URLSearchParams( {
			action: 'wbgetentities', ids: batch.join( '|' ), props: 'claims',
			format: 'json', formatversion: '2',
		} );
		const data = await getJSON( `https://www.wikidata.org/w/api.php?${q}` );
		for ( const [ qid, ent ] of Object.entries( data.entities ?? {} ) ) {
			const grab = ( prop ) => {
				const best = ent.claims?.[prop]?.find( ( c ) => c.mainsnak?.rank !== 'deprecated' );
				const dv = best?.mainsnak?.datavalue?.value;
				return dv?.time ? { time: dv.time.slice( 1, 11 ), precision: dv.precision } : null;
			};
			facts[ qid ] = { birth: grab( 'P569' ), death: grab( 'P570' ) };
		}
		await sleep( 1500 );
	}
	return facts;
}

/** Batch entity labels in English. */
async function resolveLabels( qids ) {
	const out = {};
	for ( let i = 0; i < qids.length; i += 50 ) {
		const q = new URLSearchParams( {
			action: 'wbgetentities', ids: qids.slice( i, i + 50 ).join( '|' ),
			props: 'labels', languages: 'en', format: 'json', formatversion: '2',
		} );
		const data = await getJSON( `https://www.wikidata.org/w/api.php?${q}` );
		for ( const [ qid, ent ] of Object.entries( data.entities ?? {} ) ) {
			out[ qid ] = ent.labels?.en?.value ?? null;
		}
		await sleep( 1500 );
	}
	return out;
}

( async () => {
	// 1. Parse all monthly files.
	const all = [];
	for ( const m of MONTHS ) {
		const file = `wiki-${m}-2026.json`;
		if ( ! fs.existsSync( W( file ) ) ) continue;
		const entries = parseMonth( file, m );
		console.log( `${m}: ${entries.length} entries` );
		all.push( ...entries.map( ( e ) => ( { ...e, month: m } ) ) );
	}
	console.log( `total parsed: ${all.length}` );

	// 2. Select a spread: up to 12 per month, evenly through each list.
	const selected = [];
	for ( const m of MONTHS ) {
		const list = all.filter( ( e ) => e.month === m );
		const take = Math.min( 12, list.length );
		const step = list.length / take;
		for ( let i = 0; i < take; i++ ) selected.push( list[ Math.floor( i * step ) ] );
	}
	console.log( `selected: ${selected.length}` );

	// 3. Batch-resolve QIDs, then batch-fetch facts.
	const titleMap = await resolveTitles( selected.map( ( r ) => r.enwiki ) );
	const withQids = selected.map( ( r ) => ( { ...r, qid: titleMap[ r.enwiki ] ?? null } ) ).filter( ( r ) => r.qid );
	console.log( `resolved: ${withQids.length}` );

	const facts = await entityFacts( withQids.map( ( r ) => r.qid ) );
	const deaths = withQids
		.filter( ( r ) => facts[ r.qid ]?.birth?.precision === 11 )
		.map( ( r ) => ( {
			qid: r.qid, name: r.name, age: r.age, death: r.death,
			birth: facts[ r.qid ].birth.time, death_wd: facts[ r.qid ].death?.time ?? null,
			role: r.role, enwiki: r.enwiki, origin: 'wikipedia:Deaths_in_' + r.month + '_2026',
		} ) );
	fs.writeFileSync( W( 'seed-deaths-2026.json' ), JSON.stringify( deaths, null, 2 ) );
	console.log( `seed-deaths-2026.json: ${deaths.length} rows with day-precision births` );

	// 4. Living pool via SPARQL (actresses/actors/politicians, 1940–1958).
	try {
		const sq = `SELECT ?p ?b WHERE { ?p wdt:P569 ?b . FILTER(?b >= "1940-01-01T00:00:00Z"^^xsd:dateTime && ?b <= "1958-12-31T00:00:00Z"^^xsd:dateTime) { ?p wdt:P106 wd:Q33999 } UNION { ?p wdt:P106 wd:Q82955 } . ?p wikibase:sitelinks ?l . FILTER(?l >= 20) FILTER NOT EXISTS { ?p wdt:P570 ?d } } LIMIT 120`;
		const res = await getJSON( 'https://query.wikidata.org/sparql?query=' + encodeURIComponent( sq ) + '&format=json' );
		const rows = res.results.bindings
			.filter( ( b ) => b.b.value.slice( 0, 4 ) !== '0000' )
			.map( ( b ) => ( { qid: b.p.value.split( '/' ).pop(), birth: b.b.value.slice( 0, 10 ) } ) );
		console.log( `SPARQL living pool: ${rows.length}` );
		// Fetch names for the living pool.
		const lfacts = await entityFacts( rows.slice( 0, 60 ).map( ( r ) => r.qid ) );
		const q2name = await resolveLabels( rows.slice( 0, 60 ).map( ( r ) => r.qid ) );
		const living = rows.slice( 0, 60 )
			.filter( ( r ) => lfacts[ r.qid ]?.birth?.precision === 11 )
			.map( ( r ) => ( { qid: r.qid, name: q2name[ r.qid ] ?? r.qid, birth: lfacts[ r.qid ].birth.time, origin: 'wikidata:sparql-living-pool' } ) );
		fs.writeFileSync( W( 'seed-living-pool.json' ), JSON.stringify( living, null, 2 ) );
		console.log( `seed-living-pool.json: ${living.length} rows` );
	} catch ( e ) {
		console.log( 'living pool failed: ' + e.message );
		fs.writeFileSync( W( 'seed-living-pool.json' ), '[]' );
	}

	// 5. December 2025 deaths → already-dead-at-lock demo rows.
	try {
		const q = new URLSearchParams( { action: 'parse', page: 'Deaths_in_December_2025', prop: 'wikitext', formatversion: '2', format: 'json', redirects: '1' } );
		const data = await getJSON( `https://en.wikipedia.org/w/api.php?${q}` );
		fs.writeFileSync( W( 'wiki-December-2025.json' ), JSON.stringify( data ) );
		// December 2025: same list shape, year 2025.
		const dec = parseMonth( 'wiki-December-2025.json', 'December', 2025 ).map( ( e ) => ( { ...e, month: 'December-2025' } ) );
		const decSel = [ dec[0], dec[ Math.floor( dec.length / 3 ) ], dec[ Math.floor( 2 * dec.length / 3 ) ], dec[ dec.length - 1 ] ].filter( Boolean );
		const dmap = await resolveTitles( decSel.map( ( r ) => r.enwiki ) );
		const decResolved = decSel.map( ( r ) => ( { ...r, qid: dmap[ r.enwiki ] ?? null } ) ).filter( ( r ) => r.qid );
		const dFacts = await entityFacts( decResolved.map( ( r ) => r.qid ) );
		const prelock = decResolved
			.filter( ( r ) => dFacts[ r.qid ]?.birth?.precision === 11 )
			.map( ( r ) => ( {
				qid: r.qid, name: r.name, age: r.age, death: r.death,
				birth: dFacts[ r.qid ].birth.time, death_wd: dFacts[ r.qid ].death?.time ?? null,
				role: r.role, enwiki: r.enwiki, origin: 'wikipedia:Deaths_in_December_2025',
			} ) );
		fs.writeFileSync( W( 'seed-prelock-deaths.json' ), JSON.stringify( prelock, null, 2 ) );
		console.log( `seed-prelock-deaths.json: ${prelock.length} rows (died before the 1 Jan 2026 lock)` );
	} catch ( e ) {
		console.log( 'prelock failed: ' + e.message );
		fs.writeFileSync( W( 'seed-prelock-deaths.json' ), '[]' );
	}

	console.log( 'done.' );
} )().catch( ( e ) => {
	console.error( 'FATAL', e );
	process.exit( 1 );
} );
