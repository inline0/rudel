<?php

namespace Rudel\Tests\Unit;

use Rudel\TemplateManager;
use Rudel\Tests\RudelTestCase;

class TemplateManagerOverlayTest extends RudelTestCase {

	public function testSaveCopiesOverlayFilesAndRecordsCanonicalSourceUrl(): void {
		$path = $this->createFakeSandbox(
			'alpha-site',
			'Alpha Site',
			array(
				'engine'       => 'overlay',
				'table_prefix' => 'wp_alpha_',
				'theme_slug'   => 'demo-theme',
			)
		);

		mkdir( $path . '/themes/demo-theme', 0755, true );
		file_put_contents( $path . '/themes/demo-theme/style.css', 'body { color: red; }' );

		$sandbox = $this->environmentRepository()->get( 'alpha-site' );
		$this->assertNotNull( $sandbox );

		$manager = new TemplateManager( $this->tmpDir . '/templates' );
		$meta    = $manager->save( $sandbox, 'starter', 'Starter template' );

		$this->assertSame( 'starter', $meta['name'] );
		$this->assertSame( 'http://example.test/', $meta['source_url'] );
		$this->assertFileExists( $this->tmpDir . '/templates/starter/template.json' );
		$this->assertFileExists( $this->tmpDir . '/templates/starter/wp-content/themes/demo-theme/style.css' );
	}
}
