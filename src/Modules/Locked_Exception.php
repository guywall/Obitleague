<?php
/**
 * Thrown when an entry is locked or the deadline has passed.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Locked_Exception extends \RuntimeException {}
