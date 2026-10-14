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

		$GLOBALS['wpdb']->addTable('wp_alpha_options', 'CREATE TABLE `wp_alpha_options` (`option_id` bigint, `option_name` varchar(191), `option_value` longtext)', [['option_id'=>1,'option_name'=>'blogname','option_value'=>'Saved title']]);
		$manager = new TemplateManager( $this->tmpDir . '/templates' );
		$meta    = $manager->save( $sandbox, 'starter', 'Starter template' );

		$this->assertSame( 'starter', $meta['name'] );
		$this->assertSame( 'http://example.test/', $meta['source_url'] );
		$this->assertFileDoesNotExist( $this->tmpDir . '/templates/starter/template.json' );
		$this->assertSame($meta, $manager->get('starter'));
		$this->assertTrue($GLOBALS['wpdb']->hasTable($meta['table_prefix'] . 'options'));
		$this->assertFileExists( $this->tmpDir . '/templates/starter/wp-content/themes/demo-theme/style.css' );
	}
}
