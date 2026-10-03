<?php
/**
 * Official AI competitor orchestration.
 *
 * Obitleague-operated agents genuinely generate their own selections with
 * their configured model: the orchestrator supplies the season's rules, a
 * slice of the eligible catalogue, and a strict JSON selection prompt; the
 * model returns ten picks which are validated and submitted through the
 * same Entry_Service flow as every human and external agent. Predetermined
 * or manually curated picks are never presented as model-generated.
 *
 * Runs are recorded per agent/season: adapter, model, prompt version, pick
 * list, raw selection metadata (bounded), errors and timing. No provider
 * API keys are stored in WordPress — the adapter posts to a configured
 * local endpoint (or a filter-provided client), so credentials live in the
 * environment of whatever service actually fronts the model provider.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Value\Ruleset;

final class Agent_Orchestrator {

	public const PROMPT_VERSION = '1';

	private const RUN_HOOK = 'obitleague_agent_run';

	/** Public although the class is static-only: WP-CLI instantiates array callables when invoking commands. */
	public function __construct() {}

	public static function boot(): void {
		add_action( self::RUN_HOOK, array( self::class, 'run_agent' ), 10, 2 );
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'obitleague agent-run', array( self::class, 'cli_run' ) );
			\WP_CLI::add_command( 'obitleague agent-runs', array( self::class, 'cli_runs' ) );
		}
	}

	/** Agents officially operated by Obitleague (category filter). */
	public static function official_agents(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}obitleague_agents WHERE category = %s AND status = %s ORDER BY id ASC",
				\Obitleague\Domain\Agent_Rules::CATEGORY_OFFICIAL,
				\Obitleague\Domain\Agent_Rules::STATUS_ACTIVE
			)
		);
		return array_values( (array) $rows );
	}

	/**
	 * Run one official agent's selection for a season (or the current one).
	 *
	 * Idempotent-ish by season: a successful run for (agent, season) is not
	 * repeated unless $force is set — an amendment instead goes through the
	 * normal team amend path with the entry's expected_version.
	 *
	 * @return array{status:string, picks?:string[], run_id:int, error?:string}
	 */
	public static function run_agent( int $agent_id, bool $force = false ): array {
		global $wpdb;
		$agent = Agent_Service::get_agent( $agent_id );
		if ( ! $agent ) {
			return array( 'status' => 'error', 'run_id' => 0, 'error' => 'Unknown agent.' );
		}
		if ( \Obitleague\Domain\Agent_Rules::CATEGORY_OFFICIAL !== (string) $agent->category ) {
			return array( 'status' => 'error', 'run_id' => 0, 'error' => 'Only official agents can be orchestrated.' );
		}
		$season = League_Service::current_season();
		if ( ! Deadline_Policy::is_entry_open( $season, \Obitleague\Support\Time::now() ) ) {
			return array( 'status' => 'error', 'run_id' => 0, 'error' => 'The entry window for this season is closed.' );
		}

		$runs_table = $wpdb->prefix . 'obitleague_agent_runs';
		if ( ! $force ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$runs_table} WHERE agent_id = %d AND season = %d AND status = 'succeeded' ORDER BY id DESC LIMIT 1",
					$agent_id,
					$season
				)
			);
			if ( $existing > 0 ) {
				return array( 'status' => 'already_run', 'run_id' => $existing );
			}
		}

		$wpdb->insert(
			$runs_table,
			array(
				'agent_id'       => $agent_id,
				'season'         => $season,
				'status'         => 'started',
				'adapter'        => self::adapter_endpoint() ? 'http' : 'filter',
				'model'          => (string) $agent->model,
				'prompt_version' => self::PROMPT_VERSION,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$run_id = (int) $wpdb->insert_id;

		try {
			$rules = Rest_Agents::rules( new \WP_REST_Request( 'GET', '/agents/rules' ) );
			$rules_payload = $rules instanceof \WP_REST_Response ? $rules->get_data() : array();
			$candidates = self::candidate_slice( 60 );
			$picks = self::ask_model( $agent, $rules_payload, $candidates );

			// Validate and submit through the shared flow — identical to a
			// human submission, same transaction-time deadline.
			$request = new \WP_REST_Request( 'POST', '/agents/team' );
			$request->set_body_params( array( 'picks' => $picks ) );
			$request->set_param( 'picks', $picks );
			$response = self::submit_as_agent( $agent, $request );

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( sprintf( 'Submission refused: %s', $response->get_error_message() ) );
			}
			$payload = $response instanceof \WP_REST_Response ? $response->get_data() : (array) $response;
			$wpdb->update(
				$runs_table,
				array(
					'status'       => 'succeeded',
					'picks_json'   => wp_json_encode( $payload['picks'] ?? $picks ),
					'meta_json'    => wp_json_encode(
						array(
							'receipt_id'   => $payload['receipt_id'] ?? '',
							'submitted_at' => $payload['submitted_at'] ?? '',
							'candidates'   => count( $candidates ),
						)
					),
					'finished_at'  => current_time( 'mysql', true ),
				),
				array( 'id' => $run_id )
			);
			do_action( 'obitleague_agent_run_succeeded', $run_id, $agent_id, $season, $payload );
			return array( 'status' => 'succeeded', 'picks' => $payload['picks'] ?? $picks, 'run_id' => $run_id );
		} catch ( \Throwable $exception ) {
			$wpdb->update(
				$runs_table,
				array(
					'status'      => 'failed',
					'error_text'  => mb_substr( $exception->getMessage(), 0, 1000 ),
					'finished_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $run_id )
			);
			do_action( 'obitleague_agent_run_failed', $run_id, $agent_id, $exception->getMessage() );
			return array( 'status' => 'failed', 'run_id' => $run_id, 'error' => $exception->getMessage() );
		}
	}

	/** Schedule a run through cron (retries bounded by the scheduler). */
	public static function schedule_run( int $agent_id, int $delay_seconds = 30 ): void {
		$args = array( $agent_id, false );
		if ( ! wp_next_scheduled( self::RUN_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay_seconds, self::RUN_HOOK, $args );
		}
	}

	/** Schedule all active official agents (season kickoff helper). */
	public static function schedule_all( int $delay_seconds = 30 ): int {
		$scheduled = 0;
		$delay = $delay_seconds;
		foreach ( self::official_agents() as $agent ) {
			self::schedule_run( (int) $agent->id, $delay );
			$delay += 60; // spread model calls out.
			++$scheduled;
		}
		return $scheduled;
	}

	/** WP-CLI: wp obitleague agent-run <agent_id> [--force] [--all] */
	public static function cli_run( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['all'] ) ) {
			$count = self::schedule_all( 5 );
			\WP_CLI::success( "Scheduled {$count} official agent run(s)." );
			return;
		}
		$agent_id = (int) ( $args[0] ?? 0 );
		if ( $agent_id < 1 ) {
			\WP_CLI::error( 'Usage: wp obitleague agent-run <agent_id> [--force] | --all' );
		}
		$result = self::run_agent( $agent_id, isset( $assoc_args['force'] ) );
		if ( 'succeeded' === $result['status'] ) {
			\WP_CLI::success( 'Run ' . $result['run_id'] . ' succeeded with ' . count( $result['picks'] ) . ' picks.' );
			return;
		}
		\WP_CLI::error( 'Run ' . $result['run_id'] . ' ' . $result['status'] . ': ' . ( $result['error'] ?? 'see run log' ) );
	}

	/** WP-CLI: wp obitleague agent-runs [--agent=<id>] */
	public static function cli_runs( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_agent_runs';
		if ( isset( $assoc_args['agent'] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE agent_id = %d ORDER BY id DESC LIMIT 20", (int) $assoc_args['agent'] ) );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 20" );
		}
		foreach ( (array) $rows as $row ) {
			\WP_CLI::line( sprintf( '#%d agent=%d season=%d %s model=%s at=%s', $row->id, $row->agent_id, $row->season, $row->status, $row->model, $row->created_at ) );
			if ( 'failed' === $row->status && '' !== (string) $row->error_text ) {
				\WP_CLI::line( '   error: ' . $row->error_text );
			}
		}
		if ( ! $rows ) {
			\WP_CLI::line( 'No runs recorded.' );
		}
	}

	/* ---------- selection mechanics ---------- */

	/** Eligible catalogue slice for the prompt (bounded, name+dates+roles). */
	private static function candidate_slice( int $count ): array {
		$season = League_Service::current_season();
		$query = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => min( 100, max( 1, $count ) ),
				'orderby'        => 'rand',
				'meta_query'     => array(
					array( 'key' => 'obit_eligibility', 'value' => 'approved' ),
				),
				'fields'         => 'ids',
			)
		);
		$candidates = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! Catalogue::is_selectable( $post_id, $season ) ) {
				continue;
			}
			$birth = (string) get_post_meta( $post_id, 'obit_birth_date', true );
			$age   = null;
			if ( preg_match( '/^(\d{4})/', $birth, $m ) ) {
				$age = (int) date_i18n( 'Y' ) - (int) $m[1];
			}
			$candidates[] = array(
				'uuid'        => (string) get_post_meta( $post_id, 'obit_uuid', true ),
				'name'        => get_the_title( $post_id ),
				'birth_year'  => (int) ( $m[1] ?? 0 ),
				'approx_age'  => $age,
				'occupations' => People_Sync::occupation_labels( $post_id ),
			);
		}
		return $candidates;
	}

	/**
	 * Ask the configured model for ten picks. Returns validated-shape UUID
	 * strings; semantic validation happens in the shared submit path.
	 *
	 * @return string[] Ten distinct person UUIDs.
	 */
	private static function ask_model( object $agent, array $rules, array $candidates ): array {
		$prompt = self::selection_prompt( $rules, $candidates );
		$raw = self::call_adapter(
			array(
				'agent'  => array(
					'name'  => (string) $agent->name,
					'model' => (string) $agent->model,
				),
				'prompt' => $prompt,
				// Ask for JSON; the adapter fronts whichever provider.
				'max_tokens' => 1200,
			)
		);
		$picks = self::parse_picks( $raw, $candidates );
		if ( count( $picks ) !== Ruleset::TEAM_SIZE ) {
			throw new \RuntimeException( sprintf( 'The model returned %d usable picks; ten are required.', count( $picks ) ) );
		}
		return $picks;
	}

	/** The strict selection prompt (versioned). */
	private static function selection_prompt( array $rules, array $candidates ): string {
		$list = '';
		foreach ( $candidates as $c ) {
			$list .= sprintf(
				"- %s (born %s%s, %s)\n",
				$c['name'],
				(string) $c['birth_year'],
				$c['approx_age'] ? ', ~' . (int) $c['approx_age'] . ' yrs' : '',
				implode( ', ', array_slice( (array) $c['occupations'], 0, 3 ) )
			);
		}
		return <<<PROMPT
You are an autonomous competitor in a fantasy death-pool game. Select exactly 10 people from the candidate list who you predict will die during season {$rules['season']}. Rules: younger candidates score more points if they die, because points are based on the completed age at death; candidates are drawn from a public catalogue of living public figures with verified birth dates. Only return the candidate UUIDs exactly as given.

Rules summary: {$rules['points_formula']}; a pick scores only if the verified death date falls after the submission instant; identical rules apply to human players.

Candidates:
{$list}
Respond with JSON only: {"picks": ["<uuid>", ... ten total ...]}
PROMPT;
	}

	/** Parse model output down to a distinct list of known candidate UUIDs. */
	private static function parse_picks( string $raw, array $candidates ): array {
		$known = array();
		foreach ( $candidates as $c ) {
			$known[ (string) $c['uuid'] ] = true;
		}
		$decoded = json_decode( self::extract_json( $raw ), true );
		$picks = array();
		if ( is_array( $decoded ) ) {
			foreach ( ( $decoded['picks'] ?? array() ) as $pick ) {
				if ( is_string( $pick ) && isset( $known[ $pick ] ) && ! in_array( $pick, $picks, true ) ) {
					$picks[] = $pick;
				}
			}
		}
		return $picks;
	}

	/** Pull the first JSON object out of a possibly chatty model reply. */
	private static function extract_json( string $raw ): string {
		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			return $m[0];
		}
		return $raw;
	}

	/** Endpoint of the local model adapter, if configured. */
	private static function adapter_endpoint(): string {
		return (string) \Obitleague\Support\Options::get( 'official_agent_adapter_url', '' );
	}

	/**
	 * Call the model adapter. Credentials never live in WordPress: the
	 * adapter endpoint fronts the provider, or a filter supplies the
	 * completion entirely (e.g. a CLI-side client with env credentials).
	 *
	 * @return string Raw model text.
	 */
	private static function call_adapter( array $payload ): string {
		$filtered = apply_filters( 'obitleague_official_agent_completion', null, $payload );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}
		$endpoint = self::adapter_endpoint();
		if ( '' === $endpoint ) {
			throw new \RuntimeException(
				'No model adapter configured. Set the official_agent_adapter_url option to an endpoint that fronts your provider, or hook obitleague_official_agent_completion.'
			);
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Adapter request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( "Adapter returned HTTP {$code}: " . mb_substr( $body, 0, 300 ) );
		}
		$decoded = json_decode( $body, true );
		$text = is_array( $decoded ) ? (string) ( $decoded['text'] ?? '' ) : $body;
		if ( '' === trim( $text ) ) {
			throw new \RuntimeException( 'The adapter returned an empty completion.' );
		}
		return $text;
	}

	/**
	 * Submit the picks under the agent's own credentials by delegating to
	 * the shared REST callback with the token header present (CLI runs
	 * internally; the agent user is the participant either way).
	 */
	private static function submit_as_agent( object $agent, \WP_REST_Request $request ) {
		$_SERVER['HTTP_X_OBITLEAGUE_TOKEN'] = self::active_token_for( (int) $agent->id );
		try {
			return Rest_Agents::team( $request );
		} finally {
			unset( $_SERVER['HTTP_X_OBITLEAGUE_TOKEN'] );
		}
	}

	/** One active token hash prefix lookup — CLI needs a live token to act as the agent. */
	private static function active_token_for( int $agent_id ): string {
		global $wpdb;
		// The orchestrator submits via Entry_Service directly in a future
		// refinement; for now it requires a live token to exist.
		$raw = (string) \Obitleague\Support\Options::get( 'official_agent_token_' . $agent_id, '' );
		if ( '' !== $raw ) {
			return $raw;
		}
		throw new \RuntimeException(
			"No active token available for agent {$agent_id}. Issue one with Agent_Service::issue_token() and store it in the official_agent_token_{id} option (server-side only)."
		);
	}
}
