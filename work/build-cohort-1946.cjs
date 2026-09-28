/**
 * Obitleague cohort builder: living people born in 1946 (CC0 from Wikidata).
 *
 * Why this exists: the normal seed pipeline harvests *deaths* from Wikipedia's
 * monthly lists. A dead-pool league also needs a deep pool of people who are
 * still alive to be picked, and that pool has to be deliberately diverse —
 * left alone, it collapses onto a handful of nationalities and occupations and
 * every team faces the same ten names.
 *
 * Sources (Wikidata and the English Wikipedia sitelinks, both free):
 *   - Wikidata Query Service: the candidate set, one query per birth month.
 *   - Wikidata entity API: English labels for occupation and country terms.
 *
 * Three environment facts shaped this script, all found the hard way:
 *
 * 1. `FILTER(YEAR(?dob) = 1946)` is unusable — the endpoint times out
 *    planning it. The working form bounds the statement value directly
 *    (p:P569/ps:P569) inside a one-month window, which the index can serve.
 *    Twelve monthly queries replace one yearly one.
 *
 * 2. Month-sized responses are already ~250KB, and a whole-year response is
 *    truncated mid-stream by whatever sits in front of the endpoint. So the
 *    result set is never fetched in one piece: months are paged to disk, then
 *    enrichment is done in chunks over a bounded sample.
 *
 * 3. The endpoint returns HTTP 504 for a window it cannot finish, and 429 when
 *    queries arrive back to back. Both are retried, the 504 by halving the
 *    window, with a polite gap between calls and a disk cache so an
 *    interrupted run resumes instead of starting over.
 *
 * Usage: node work/build-cohort-1946.cjs [--target 150] [--sample 2000] [--out <file>]
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { createHash } = require( 'crypto' );

const UA = 'ObitleagueCohort/0.1 (local development; contact: local)';
const W = ( f ) => path.join( __dirname, f );
const CACHE = W( '.cohort-cache' );
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );const SPARQL = 'https://query.wikidata.org/sparql';
const API = 'https://www.wikidata.org/w/api.php';
const WIKI_API = 'https://en.wikipedia.org/w/api.php';
const YEAR = 1946;
const GAP_MS = 4000;
const ENWIKI = '<https://en.wikipedia.org/>';

const args = process.argv.slice( 2 );
const argOf = ( flag, fallback ) => {
	const i = args.indexOf( flag );
	return i === -1 ? fallback : args[ i + 1 ];
};
const TARGET = parseInt( argOf( '--target', '150' ), 10 );
const SAMPLE = parseInt( argOf( '--sample', '2000' ), 10 );
const OUT = W( argOf( '--out', 'seed-cohort-1946.json' ) );
const MINIMUM = 100;

// The plugin refuses to approve a person whose birth date lacks a day
// ("Birth date lacks day precision; eligibility review required"), so a
// cohort of year-precision dates imports but cannot be published without an
// editor looking at every row. Exact dates are therefore the default; pass
// --any-precision to keep the partial ones and handle them by hand.
const ALLOW_PARTIAL = args.includes( '--any-precision' );

// Selection ceilings. Enforced as caps, not preferences: without them the
// catalogue is whichever nationality and occupation Wikidata documents most.
const MAX_PER_COUNTRY = 4;
// Two tiers. The second pass exists to fill a target the first pass cannot
// reach, but it must not hand the pool back to whoever is most common.
const OCCUPATION_CAPS = [ 3, 6 ];

const qid = ( u ) => u.split( '/' ).pop();

async function getJSON( url, accept = 'application/json' ) {
	const res = await fetch( url, { headers: { 'User-Agent': UA, Accept: accept } } );
	if ( res.ok ) return res.json();
	if ( 429 === res.status || res.status >= 500 ) {
		const wait = parseInt( res.headers.get( 'retry-after' ) ?? '0', 10 ) || 10 * 1000;
		const err = new Error( `HTTP ${res.status}` );
		err.retryAfter = wait;
		throw err;
	}
	throw new Error( `HTTP ${res.status} for ${url.slice( 0, 100 )}` );
}

async function sparql( query, tries = 4 ) {
	for ( let i = 0; i < tries; i++ ) {
		try {
			const data = await getJSON(
				`${SPARQL}?format=json&query=${encodeURIComponent( query )}`,
				'application/sparql-results+json'
			);
			return data.results?.bindings ?? [];
		} catch ( e ) {
			if ( ! e.message.startsWith( 'HTTP ' ) || i === tries - 1 ) throw e;
			const wait = e.retryAfter ?? 10 * ( i + 1 ) * ( i + 1 ) * 1000;
			console.log( `  .. ${e.message}; backing off ${Math.round( wait / 1000 )}s` );
			await sleep( wait );
		}
	}
	return [];
}

/** Humans born between two dates, with an English Wikipedia article, no death. */
function windowQuery( from, to ) {
	return `SELECT DISTINCT ?item ?dob ?title WHERE {
  ?item p:P569 ?birthStatement ;
        wdt:P31 wd:Q5 .
  ?birthStatement ps:P569 ?dob .
  FILTER(?dob >= "${from}"^^xsd:dateTime && ?dob < "${to}"^^xsd:dateTime)
  FILTER NOT EXISTS { ?item wdt:P570 ?died }
  ?article schema:about ?item ;
           schema:name ?title ;
           schema:isPartOf ${ENWIKI} .
}`;
}

function readCache( file ) {
	const p = path.join( CACHE, file );
	return fs.existsSync( p ) ? JSON.parse( fs.readFileSync( p, 'utf8' ) ) : null;
}
function writeCache( file, value ) {
	fs.mkdirSync( CACHE, { recursive: true } );
	fs.writeFileSync( path.join( CACHE, file ), JSON.stringify( value ) );
}

/** One window, halving on 504 until it succeeds. */
async function fetchWindow( from, to, tag ) {
	const file = `w-${from}-${to}.json`;
	const cached = readCache( file );
	if ( cached ) {
		console.log( `  ${tag} ${from}..${to}: ${cached.length} (cached)` );
		return cached;
	}
	try {
		const rows = ( await sparql( windowQuery( from, to ) ) ).map( ( b ) => ( {
			qid: qid( b.item.value ),
			birth: b.dob.value.slice( 0, 10 ),
			title: b.title.value,
		} ) );
		writeCache( file, rows );
		console.log( `  ${tag} ${from}..${to}: ${rows.length}` );
		await sleep( GAP_MS );
		return rows;
	} catch ( e ) {
		if ( e.message !== 'HTTP 504' ) throw e;
		console.log( `  .. 504 on ${from}..${to}; halving the window` );
		const mid = new Date( ( Date.parse( from ) + Date.parse( to ) ) / 2 );
		const midIso = mid.toISOString().slice( 0, 10 );
		const [ a, b ] = await Promise.all( [
			fetchWindow( from, midIso, tag ),
			fetchWindow( midIso, to, tag ),
		] );
		return a.concat( b );
	}
}

/** Occupations, citizenship, gender, article title and description for a bounded set. */
async function enrich( qids ) {
	const rows = [];
	for ( let i = 0; i < qids.length; i += 200 ) {
		const chunk = qids.slice( i, i + 200 );
		const file = `e-${chunk[ 0 ] }-${chunk[ chunk.length - 1 ]}.json`;
		const cached = readCache( file );
		if ( cached ) { rows.push( ...cached ); continue; }
		const values = chunk.map( ( q ) => `wd:${q}` ).join( ' ' );
		const query = `SELECT ?item ?occ ?country ?gender ?title ?desc WHERE {
  VALUES ?item { ${values} }
  OPTIONAL { ?item wdt:P106 ?occ }
  OPTIONAL { ?item wdt:P27 ?country }
  OPTIONAL { ?item wdt:P21 ?gender }
  ?article schema:about ?item ; schema:name ?title ; schema:isPartOf ${ENWIKI} .
  OPTIONAL { ?article schema:description ?desc . FILTER(LANG(?desc) = "en") }
}`;
		const batch = await sparql( query );
		writeCache( file, batch );
		rows.push( ...batch );
		console.log( `  enriched ${Math.min( i + 200, qids.length )}/${qids.length}` );
		await sleep( GAP_MS );
	}
	return rows;
}

/**
 * English labels for term QIDs.
 *
 * Asked of the query service rather than the entity API: the API rate-limits
 * anonymous callers hard enough to fail a few hundred label lookups, while
 * the same VALUES pattern the enrichment step already uses is served cheaply.
 */
async function labelsFor( qids ) {
	const out = {};
	const uniq = [ ...new Set( qids ) ];
	for ( let i = 0; i < uniq.length; i += 150 ) {
		const batch = uniq.slice( i, i + 150 );
		const values = batch.map( ( q ) => `wd:${q}` ).join( ' ' );
		const query = `SELECT ?term ?termLabel WHERE {
  VALUES ?term { ${values} }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}`;
		const rows = await sparql( query );
		for ( const b of rows ) {
			const k = qid( b.term.value );
			// A missing label is recorded as the QID rather than dropped, so a
			// term never silently becomes an empty string in the report.
			out[ k ] = b.termLabel?.value || k;
		}
		await sleep( GAP_MS );
	}
	return out;
}

/**
 * Rank candidates by how much each one widens the pool, so rare
 * nationalities and occupations are offered first.
 */
function rank( candidates ) {
	const weight = new Map();
	for ( const c of candidates ) {
		for ( const k of [ c.primaryCountry, ...c.occupations ] ) {
			weight.set( k, ( weight.get( k ) ?? 0 ) + 1 );
		}
	}
	const score = ( c ) => {
		const terms = [ c.primaryCountry, ...c.occupations ];
		const total = terms.reduce( ( a, k ) => a + 1 / ( weight.get( k ) ?? 1 ), 0 );
		const breadth = total / ( terms.length || 1 );
		// A positive "Living people" category is worth a nudge, but never more
		// than a genuinely rarer nationality or occupation.
		return breadth + ( c.confirmed ? 0.05 : 0 );
	};
	return [ ...candidates ].sort( ( a, b ) => score( b ) - score( a ) );
}

/**
 * Balanced selection. Candidates are admitted only while their country and
 * occupations are under their ceilings. A second pass relaxes the occupation
 * ceiling — never the country one — so a ubiquitous occupation thins the
 * catalogue but cannot carve out a whole country's cohort.
 */
function select( candidates, target ) {
	const pool = rank( candidates );
	const taken = [];
	const countryUse = new Map();
	const occUse = new Map();

	for ( let pass = 0; pass < OCCUPATION_CAPS.length && taken.length < target; pass++ ) {
		for ( const c of pool ) {
			if ( taken.length >= target ) break;
			if ( taken.includes( c ) ) continue;
			// Citizenship is accounted against the primary (first-stated)
			// country only. Counting every citizenship would let one
			// dual-national quietly consume several countries' allowance.
			if ( ( countryUse.get( c.primaryCountry ) ?? 0 ) >= MAX_PER_COUNTRY ) continue;
			if ( ! c.occupations.some( ( k ) => ( occUse.get( k ) ?? 0 ) < OCCUPATION_CAPS[ pass ] ) ) continue;
			taken.push( c );
			countryUse.set( c.primaryCountry, ( countryUse.get( c.primaryCountry ) ?? 0 ) + 1 );
			for ( const k of c.occupations ) occUse.set( k, ( occUse.get( k ) ?? 0 ) + 1 );
		}
	}
	return taken;
}

/**
 * Sourced birth precision and a death check for the shortlisted people.
 *
 * Two things the SPARQL projection cannot settle, both checked against the
 * authoritative statements:
 *
 * 1. Precision. A month-precision Wikidata date renders as 1946-01-01, so the
 *    day would be invented — a quarter of the cohort lands on the 1st, which
 *    no real distribution does. The plugin's rule is to keep the evidence's
 *    precision, so the date is rebuilt from the statement's own precision.
 *
 * 2. Liveness. "No date of death on record" is what the query filters, but a
 *    person whose P570 was added since the cache was written would still be
 *    offered as a living pick. A cohort of picks has to be alive, so P570 is
 *    re-checked per person and anyone carrying it is dropped.
 */
async function personFacts( qids ) {
	const out = {};
	for ( let i = 0; i < qids.length; i += 50 ) {
		const batch = qids.slice( i, i + 50 );
		let data;
		for ( let attempt = 0; attempt < 5; attempt++ ) {
			try {
				data = await getJSON(
					`${API}?action=wbgetentities&ids=${batch.join( '|' )}&props=claims&format=json&formatversion=2`
				);
				break;
			} catch ( e ) {
				if ( ! e.message.startsWith( 'HTTP ' ) || attempt === 4 ) throw e;
				console.log( `  .. ${e.message} on person facts; backing off` );
				await sleep( 10 * ( attempt + 1 ) * 1000 );
			}
		}
		// `entities` is an id-keyed map here regardless of formatversion.
		for ( const e of Object.values( data?.entities ?? {} ) ) {
			if ( ! e?.id ) continue;
			const claims = e.claims ?? {};
			const death = ( claims.P570 ?? [] ).find( ( c ) => 'deprecated' !== c.rank && c.mainsnak?.datavalue?.value?.time );
			const fact = { birth: '', died: false };
			if ( death ) fact.died = true;

			const born = ( claims.P569 ?? [] );
			const best = born.find( ( c ) => 'deprecated' !== c.rank ) ?? born[ 0 ];
			const v = best?.mainsnak?.datavalue?.value;
			const match = v?.time && /^([+-])(\d{4})-(\d{2})-(\d{2})/.exec( v.time );
			if ( match && '-' !== match[ 1 ] ) {
				const [ , , y, m, d ] = match;
				// Trust a non-zero component over the declared precision: a
				// statement is allowed to be vaguer than its own encoding.
				const precision = Number( v.precision );
				if ( 11 === precision && '00' !== d ) fact.birth = `${y}-${m}-${d}`;
				else if ( ( 10 === precision || 11 === precision ) && '00' !== m ) fact.birth = `${y}-${m}`;
				else if ( '00' !== y ) fact.birth = y;
			}
			out[ e.id ] = fact;
		}
		await sleep( GAP_MS );
	}
	return out;
}

/**
 * Liveness, cross-checked against English Wikipedia's own categories.
 *
 * Wikidata's absence of a P570 is not proof of life: a person who died in 2022
 * can sit there with no date of death, and the candidate query filters on
 * exactly that absence. For a pool of *picks* that is the wrong thing to
 * trust, because the game's whole premise is whether a pick has died.
 *
 * Every biographical article of a dead person carries a "<year> deaths"
 * category, so the categories of the shortlisted articles are a second,
 * independent signal. Articles carrying a death-year category are dropped even
 * when Wikidata says nothing. This is a cross-check, not a replacement: a
 * missing category is not proof of life either, so both signals have to be
 * absent.
 */
async function wikipediaDeaths( titles ) {
	const dead = new Set();
	const living = new Set();
	for ( let i = 0; i < titles.length; i += 50 ) {
		const batch = titles.slice( i, i + 50 );
		const url = `${WIKI_API}?action=query&prop=categories&clshow=!hidden&cllimit=max`
			+ `&titles=${encodeURIComponent( batch.join( '|' ) )}&redirects=1&format=json&formatversion=2`;
		let data;
		for ( let attempt = 0; attempt < 5; attempt++ ) {
			try {
				data = await getJSON( url );
				break;
			} catch ( e ) {
				if ( ! e.message.startsWith( 'HTTP ' ) || attempt === 4 ) throw e;
				console.log( `  .. ${e.message} on categories; backing off` );
				await sleep( 10 * ( attempt + 1 ) * 1000 );
			}
		}
		for ( const page of data?.query?.pages ?? [] ) {
			// Category titles are fully qualified ("Category:2022 deaths"), so
			// the namespace prefix has to come off before matching.
			const cats = ( page.categories ?? [] ).map( ( c ) => String( c.title ).replace( /^Category:/, '' ) );
			// "1946 deaths" is a namesake, not this person; a death year
			// after their birth year is a real signal.
			const year = cats.find( ( t ) => /^\d{3,4} deaths$/.test( t ) );
			const name = String( page.title ?? '' ).replace( /_/g, ' ' );
			if ( year && Number( year.slice( 0, 4 ) ) > YEAR ) dead.add( name );
			if ( cats.includes( 'Living people' ) ) living.add( name );
		}
		await sleep( GAP_MS );
	}
	return { dead, living };
}

/**
 * Proves the liveness cross-check can actually fail.
 *
 * The guard is a filter: when it is right, everything simply passes, and a
 * filter that never rejects anything looks exactly like a filter that is not
 * running. David Bowie is the control — born 1946, dead since 2016, and
 * therefore excluded from the candidate query by its date-of-death filter, so
 * he can only be in this test by being fed in directly.
 */
async function selftest() {
	const cats = await wikipediaDeaths( [ 'David Bowie', 'Emerson Fittipaldi' ] );
	const checks = [
		[ 'David Bowie flagged as deceased', cats.dead.has( 'David Bowie' ) ],
		[ 'Emerson Fittipaldi not flagged', ! cats.dead.has( 'Emerson Fittipaldi' ) ],
		[ 'Emerson Fittipaldi confirmed living', cats.living.has( 'Emerson Fittipaldi' ) ],
	];
	let failed = 0;
	for ( const [ label, ok ] of checks ) {
		console.log( `  ${ok ? 'ok  ' : 'FAIL'} ${label}` );
		if ( ! ok ) failed++;
	}
	if ( failed ) throw new Error( `${failed} self-test checks failed` );
	console.log( 'self-test passed' );
}

/**
 * English labels only, with no native-language fallback.
 *
 * The label service falls back to an item's own language when it has no
 * English label, which puts words like "siviløkonom" into an English byline.
 * Asking the entity API for English and nothing else makes the gap visible.
 */
async function englishLabels( qids ) {
	const out = {};
	const uniq = [ ...new Set( qids ) ].filter( Boolean );
	for ( let i = 0; i < uniq.length; i += 50 ) {
		const batch = uniq.slice( i, i + 50 );
		let data;
		for ( let attempt = 0; attempt < 5; attempt++ ) {
			try {
				data = await getJSON(
					`${API}?action=wbgetentities&ids=${batch.join( '|' )}&props=labels&languages=en&format=json&formatversion=2`
				);
				break;
			} catch ( e ) {
				if ( ! e.message.startsWith( 'HTTP ' ) || attempt === 4 ) throw e;
				console.log( `  .. ${e.message} on English labels; backing off` );
				await sleep( 10 * ( attempt + 1 ) * 1000 );
			}
		}
		// `entities` is an id-keyed map here regardless of formatversion.
		for ( const e of Object.values( data?.entities ?? {} ) ) {
			if ( e?.id ) out[ e.id ] = e.labels?.en?.value ?? '';
		}
		await sleep( GAP_MS );
	}
	return out;
}

async function main() {
	if ( args.includes( '--selftest' ) ) {
		await selftest();
		return;
	}

	console.log( `== living people born ${YEAR}, English Wikipedia article ==` );
	const candidates = [];
	for ( let m = 1; m <= 12; m++ ) {
		const from = `${YEAR}-${String( m ).padStart( 2, '0' )}-01`;
		const to = m === 12 ? `${YEAR + 1}-01-01` : `${YEAR}-${String( m + 1 ).padStart( 2, '0' )}-01`;
		candidates.push( ...await fetchWindow( from, to, `${YEAR}-${String( m ).padStart( 2, '0' )}` ) );
	}
	if ( ! candidates.length ) throw new Error( 'no candidates returned; refusing to write an empty cohort' );

	// Belt and braces: the year is re-checked client-side, because a single
	// mis-parsed row is a person who does not belong in a 1946 cohort.
	const usable = candidates.filter( ( r ) => /^Q\d+$/.test( r.qid ) && r.birth.startsWith( `${YEAR}-` ) );
	const seen = new Set();
	const unique = usable.filter( ( r ) => ( seen.has( r.qid ) ? false : seen.add( r.qid ) ) );
	console.log( `\ncandidates: ${unique.length} distinct` );

	// A uniform, reproducible sample. Enriching all 11,000 candidates would
	// mean dozens of extra queries for no benefit — the selection only needs a
	// pool far larger than the target. Ordering by a hash of the QID gives an
	// even spread; ordering by list position does not, because coverage is
	// wildly uneven across birth months (January alone holds a third of this
	// cohort) and a positional sample simply takes the first of them all.
	const sample = [ ...unique ]
		.map( ( r ) => ( { r, h: createHash( 'sha1' ).update( r.qid ).digest( 'hex' ) } ) )
		.sort( ( a, b ) => ( a.h < b.h ? -1 : 1 ) )
		.slice( 0, Math.min( SAMPLE, unique.length ) )
		.map( ( x ) => x.r );
	const byMonth = sample.reduce( ( m, r ) => { const k = r.birth.slice( 0, 7 ); m[ k ] = ( m[ k ] ?? 0 ) + 1; return m; }, {} );
	console.log( `enriching a uniform sample of ${sample.length}; months=${JSON.stringify( byMonth )}` );

	const raw = await enrich( sample.map( ( r ) => r.qid ) );
	const byItem = new Map();
	for ( const b of raw ) {
		const k = qid( b.item.value );
		if ( ! byItem.has( k ) ) byItem.set( k, { occupations: [], countries: [] } );
		const rec = byItem.get( k );
		if ( b.occ ) rec.occupations.push( qid( b.occ.value ) );
		if ( b.country ) rec.countries.push( qid( b.country.value ) );
		if ( b.gender ) rec.gender = qid( b.gender.value );
		if ( b.title ) rec.title = b.title.value;
		if ( b.desc ) rec.description = b.desc.value;
	}

	// A person with no occupation and no citizenship cannot be made diverse;
	// they are not useful catalogue entries, so they are dropped rather than
	// counted towards the target.
	const ready = sample.map( ( r ) => ( { base: r, ...( byItem.get( r.qid ) ?? { occupations: [], countries: [] } ) } ) )
		.filter( ( r ) => r.occupations.length && r.countries.length );
	console.log( `with occupation and citizenship: ${ready.length}` );
	if ( ready.length < MINIMUM ) throw new Error( `only ${ready.length} usable candidates` );

	const termQids = [ ...new Set( ready.flatMap( ( r ) => [ ...r.occupations, ...r.countries, r.gender ].filter( Boolean ) ) ) ];
	console.log( `resolving ${termQids.length} term labels...` );
	const labels = await labelsFor( termQids );

	const candidates2 = ready.map( ( r ) => ( {
		qid: r.base.qid,
		birth: r.base.birth,
		title: r.title ?? r.base.title,
		description: r.description ?? '',
		occupationQids: [ ...new Set( r.occupations ) ],
		occupations: [ ...new Set( r.occupations.map( ( q ) => labels[ q ] ?? q ) ) ],
		countries: [ ...new Set( r.countries.map( ( q ) => labels[ q ] ?? q ) ) ],
		primaryCountry: r.countries.map( ( q ) => labels[ q ] ?? q )[ 0 ],
		gender: labels[ r.gender ] ?? r.gender ?? '',
	} ) );

	// Selection happens over a shortlist wider than the target, because the
	// birth-precision pass below can disqualify people whose Wikidata
	// statements conflict about the year. Selecting exactly the target and
	// then dropping the rejects would quietly deliver a short cohort.
	const shortlist = rank( candidates2 ).slice( 0, Math.max( 400, TARGET * 3 ) );
	console.log( `\nshortlisting ${shortlist.length} for the precision pass` );

	console.log( 'resolving sourced birth precision and death status...' );
	const facts = await personFacts( shortlist.map( ( p ) => p.qid ) );
	const died = shortlist.filter( ( p ) => facts[ p.qid ]?.died );
	const bornOk = shortlist.filter( ( p ) => ( facts[ p.qid ]?.birth ?? '' ).startsWith( `${YEAR}` ) );
	console.log( `sourced birth in ${YEAR}: ${bornOk.length} of ${shortlist.length}` );
	if ( died.length ) console.log( `  dropped ${died.length} with a date of death on record` );

	const partial = bornOk.filter( ( p ) => ! /^\d{4}-\d{2}-\d{2}$/.test( facts[ p.qid ].birth ) );
	console.log( `  ${partial.length} lack day precision${ALLOW_PARTIAL ? ' (kept: --any-precision)' : ' (dropped: they cannot be approved for publication without review)'}` );

	console.log( 'cross-checking liveness against Wikipedia categories...' );
	const cats = await wikipediaDeaths( bornOk.map( ( p ) => p.title.replace( /_/g, ' ' ) ) );
	const nameOf = ( p ) => p.title.replace( /_/g, ' ' );
	const byName = new Map( bornOk.map( ( p ) => [ nameOf( p ), p ] ) );
	const deceased = [ ...cats.dead ].map( ( n ) => byName.get( n ) ).filter( Boolean );
	console.log( `  dropped ${deceased.length} whose Wikipedia article carries a death-year category` );
	for ( const p of deceased.slice( 0, 8 ) ) console.log( `    ${p.title} (Wikidata death: ${facts[ p.qid ].died ? 'yes' : 'none' })` );

	const inCohort = bornOk
		.filter( ( p ) => ! facts[ p.qid ].died && ! cats.dead.has( nameOf( p ) ) )
		.filter( ( p ) => ALLOW_PARTIAL || /^\d{4}-\d{2}-\d{2}$/.test( facts[ p.qid ].birth ) )
		// Where two candidates are otherwise comparable, prefer the one whose
		// article actively says "Living people" over the one that merely
		// says nothing — silence is not the same as confirmation.
		.map( ( p ) => ( { ...p, confirmed: cats.living.has( nameOf( p ) ) } ) );
	const unconfirmed = inCohort.filter( ( p ) => ! p.confirmed ).length;
	console.log( `eligible: ${inCohort.length} of ${shortlist.length} (${cats.living.size} confirmed by a "Living people" category, ${unconfirmed} unconfirmed)` );
	if ( ! inCohort.length ) throw new Error( 'no shortlisted person has a sourced 1946 birth and no recorded death' );

	const picked = select( inCohort, TARGET );
	console.log( `selected ${picked.length}` );
	if ( picked.length < MINIMUM ) {
		throw new Error( `only ${picked.length} selected, below the ${MINIMUM} minimum` );
	}

	// The byline must read in English. The Wikidata English description is
	// already a sourced "<nationality> <occupation>" phrase; where it is
	// missing, the first occupation that has an English label is used, and
	// only if none does does the native term stand in. Nothing is invented.
	console.log( 'checking occupations for an English label...' );
	const english = await englishLabels( picked.flatMap( ( p ) => p.occupationQids ) );
	let translated = 0;
	const rows = picked.map( ( p ) => {
		const occEn = p.occupationQids.map( ( q ) => english[ q ] ?? '' ).filter( Boolean );
		const occ = occEn[ 0 ] ?? p.occupations[ 0 ] ?? '';
		if ( occ !== p.occupations[ 0 ] ) translated++;
		const role = p.description || [ occ, p.countries[ 0 ] ].filter( Boolean ).join( ', ' );
		return {
			qid: p.qid,
			name: p.title.replace( /_/g, ' ' ),
			birth: facts[ p.qid ].birth,
			birth_precision: /^\d{4}-\d{2}-\d{2}$/.test( facts[ p.qid ].birth ) ? 'day' : ( facts[ p.qid ].birth.length === 7 ? 'month' : 'year' ),
			role,
			occupation: occ,
			occupations: p.occupations,
			countries: p.countries,
			enwiki: p.title.replace( / /g, '_' ),
			origin: `wikidata:sparql-cohort-${YEAR}`,
			// Informational only. Both Wikidata and Wikipedia can be silent
			// about a death, so this records the strength of the evidence
			// rather than promising the person is alive.
			alive_confirmed: p.confirmed,
		};
	} ).sort( ( a, b ) => a.name.localeCompare( b.name ) );

	const problem = ( r ) => {
		if ( ! /^Q\d+$/.test( r.qid ) ) return 'bad qid';
		if ( ! r.name ) return 'no name';
		if ( ! r.birth || ! r.birth.startsWith( `${YEAR}` ) ) return `birth "${r.birth}" outside the cohort`;
		if ( ! r.enwiki ) return 'no enwiki title';
		if ( ! r.role ) return 'no role';
		return '';
	};
	const bad = rows.map( ( r ) => ( { r, why: problem( r ) } ) ).filter( ( x ) => x.why );
	if ( bad.length ) {
		const detail = bad.slice( 0, 6 ).map( ( x ) => `${x.r.name || x.r.qid}: ${x.why}` ).join( '; ' );
		throw new Error( `${bad.length} rows would not import — ${detail}` );
	}
	if ( translated ) console.log( `  ${translated} bylines used an English occupation label instead of a native term` );

	const tally = ( rows2, key ) => {
		const m = new Map();
		for ( const r of rows2 ) for ( const v of new Set( r[ key ] ) ) m.set( v, ( m.get( v ) ?? 0 ) + 1 );
		return [ ...m.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
	};
	const countries = tally( rows, 'countries' );
	const occupations = tally( rows, 'occupations' );
	const months = rows.reduce( ( m, r ) => { const k = r.birth.slice( 0, 7 ); m[ k ] = ( m[ k ] ?? 0 ) + 1; return m; }, {} );

	fs.writeFileSync( OUT, JSON.stringify( rows, null, '\t' ) + '\n' );
	console.log( `\nwrote ${rows.length} rows -> ${OUT}` );
	console.log( `countries: ${countries.length} distinct; largest ${countries[ 0 ]?.[ 0 ]}=${countries[ 0 ]?.[ 1 ]}` );
	console.log( `occupations: ${occupations.length} distinct; largest ${occupations[ 0 ]?.[ 0 ]}=${occupations[ 0 ]?.[ 1 ]}` );
	console.log( `top countries: ${countries.slice( 0, 10 ).map( ( [ c, n ] ) => `${c} ${n}` ).join( ', ' )}` );
	console.log( `top occupations: ${occupations.slice( 0, 10 ).map( ( [ c, n ] ) => `${c} ${n}` ).join( ', ' )}` );
	console.log( `by birth month: ${Object.entries( months ).map( ( [ m, n ] ) => `${m.slice( 5 )}:${n}` ).join( ' ' )}` );
}

main().catch( ( e ) => { console.error( 'FAILED:', e.stack ?? e.message ); process.exit( 1 ); } );
