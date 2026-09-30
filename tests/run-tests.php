<?php
/**
 * Obitleague test runner.
 *
 * Runs the domain suite without WordPress. The domain layer is deliberately
 * free of WordPress calls so the rules that must never drift can be verified
 * on any machine with PHP alone.
 *
 * Usage: php tests/run-tests.php
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

require_once __DIR__ . '/../src/Domain/Value/Ruleset.php';
require_once __DIR__ . '/../src/Domain/Value/Partial_Date.php';
require_once __DIR__ . '/../src/Domain/Value/Award.php';
require_once __DIR__ . '/../src/Domain/Value/Awarded_Event.php';
require_once __DIR__ . '/../src/Domain/Value/Cause_Status.php';
require_once __DIR__ . '/../src/Domain/Value/Role_Label.php';
require_once __DIR__ . '/../src/Domain/Value/Score_Result.php';
require_once __DIR__ . '/../src/Domain/Age.php';
require_once __DIR__ . '/../src/Domain/Ranking.php';
require_once __DIR__ . '/../src/Domain/Scoring.php';
require_once __DIR__ . '/../src/Domain/Invalid_Team_Exception.php';
require_once __DIR__ . '/../src/Domain/Team_Picks.php';
require_once __DIR__ . '/../src/Domain/Feed_Classifier.php';
require_once __DIR__ . '/../src/Domain/Deadline_Policy.php';
require_once __DIR__ . '/../src/Domain/Discovery_Rules.php';

require_once __DIR__ . '/Scenario_Scoring.php';
require_once __DIR__ . '/Scenario_Ranking.php';
require_once __DIR__ . '/Scenario_Teams.php';
require_once __DIR__ . '/Scenario_Dates.php';
require_once __DIR__ . '/Scenario_Feeds.php';
require_once __DIR__ . '/Scenario_Entries.php';
require_once __DIR__ . '/Scenario_Review.php';
require_once __DIR__ . '/Scenario_Campaign.php';
require_once __DIR__ . '/Scenario_Roles.php';
require_once __DIR__ . '/Scenario_Discovery.php';
require_once __DIR__ . '/Scenario_Agents.php';
require_once __DIR__ . '/Scenario_Wiring.php';

// Domain tests must never depend on WordPress; each scenario pulls only
// pure-domain files.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

final class Runner {

	private int $passed = 0;
	private int $failed = 0;

	public function run( string $suite_class ): void {
		$suite = new $suite_class();
		foreach ( get_class_methods( $suite ) as $method ) {
			if ( ! str_starts_with( $method, 'test_' ) ) {
				continue;
			}
			try {
				$suite->$method( $this );
			} catch ( \Throwable $e ) {
				$this->fail( $method, 'Threw: ' . $e->getMessage() );
				continue;
			}
		}
	}

	public function check( bool $condition, string $method, string $what ): void {
		if ( $condition ) {
			++$this->passed;
			echo "  ok  {$method}: {$what}\n";
			return;
		}
		$this->fail( $method, $what );
	}

	public function fail( string $method, string $what ): void {
		++$this->failed;
		echo "FAIL  {$method}: {$what}\n";
	}

	public function summary(): int {
		echo "\n{$this->passed} passed, {$this->failed} failed\n";
		return 0 === $this->failed ? 0 : 1;
	}
}

$runner = new Runner();
$runner->run( Scenario_Scoring::class );
$runner->run( Scenario_Ranking::class );
$runner->run( Scenario_Teams::class );
$runner->run( Scenario_Dates::class );
$runner->run( Scenario_Feeds::class );
$runner->run( Scenario_Entries::class );
$runner->run( Scenario_Review::class );
$runner->run( Scenario_Campaign::class );
$runner->run( Scenario_Roles::class );
$runner->run( Scenario_Discovery::class );
$runner->run( Scenario_Agents::class );
$runner->run( Scenario_Wiring::class );
exit( $runner->summary() );
