<?php
/**
 * Thrown when an optimistic-concurrency check fails.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Stale_Exception extends \RuntimeException {}
