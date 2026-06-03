<?php
/**
 * Runtime JSON helper tests.
 *
 * @package Rudel\Tests
 */

namespace Rudel\Tests\Unit;

use Rudel\RuntimeJson;
use Rudel\Tests\RudelTestCase;

/**
 * Covers JSON encoding shared by WordPress and pure-PHP runtime paths.
 */
class RuntimeJsonTest extends RudelTestCase {

	public function testEncodeReturnsJsonString(): void {
		$this->assertSame(
			'{"environment":"alpha","flags":["db","themes"]}',
			RuntimeJson::encode(
				array(
					'environment' => 'alpha',
					'flags'       => array( 'db', 'themes' ),
				)
			)
		);
	}
}
