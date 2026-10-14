<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\TemplateManager;
use Rudel\Tests\RudelTestCase;

class TemplateRestoreTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateRestoresSavedTablesAndOwnedFilesAfterSourceChangesAndDeletion(): void
    {
        define('ABSPATH', $this->tmpDir . '/wordpress/');
        define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
        define('WP_HOME', 'http://example.test');
        $path = $this->createFakeSandbox('template-source', 'Source', [
            'theme_slug' => 'saved-theme',
            'shared_plugins' => true,
            'shared_uploads' => true,
            'clone_source' => ['git_plugin_file' => 'plugins/preview/preview.php'],
        ]);
        $source = $this->environmentRepository()->get('template-source');
        $this->assertNotNull($source);
        mkdir($path . '/themes/saved-theme', 0755, true);
        mkdir($path . '/plugins/preview', 0755, true);
        file_put_contents($path . '/themes/saved-theme/style.css', "/* Theme Name: Saved */\n");
        file_put_contents($path . '/themes/saved-theme/index.php', '<?php echo "saved theme";');
        file_put_contents($path . '/plugins/preview/preview.php', '<?php /* Plugin Name: Preview */');
        $prefix = $source->get_table_prefix();
        $GLOBALS['wpdb']->addTable($prefix . 'options', 'CREATE TABLE `' . $prefix . 'options` (`option_id` bigint, `option_name` varchar(191), `option_value` longtext)', [
            ['option_id'=>1, 'option_name'=>'blogname', 'option_value'=>'Saved title'],
            ['option_id'=>2, 'option_name'=>'home', 'option_value'=>'http://example.test'],
            ['option_id'=>3, 'option_name'=>'siteurl', 'option_value'=>'http://example.test'],
            ['option_id'=>4, 'option_name'=>'active_plugins', 'option_value'=>serialize(['preview/preview.php'])],
        ]);
        $templates = new TemplateManager();
        $saved = $templates->save($source, 'starter');
        file_put_contents($path . '/themes/saved-theme/index.php', 'changed source');
        $GLOBALS['wpdb']->update($prefix . 'options', ['option_value'=>'Changed title'], ['option_name'=>'blogname']);
        $manager = new EnvironmentManager($this->tmpDir);
        $this->assertTrue($manager->destroy($source->id));
        $first = $manager->create('From template', ['template'=>'starter']);
        $second = $manager->create('Second', ['template'=>'starter']);
        $this->assertSame('saved-theme', $first->theme_slug);
        $this->assertSame('starter', $first->template);
        $this->assertNotSame($first->get_table_prefix(), $second->get_table_prefix());
        foreach ([$first, $second] as $environment) {
            $this->assertSame('<?php echo "saved theme";', file_get_contents($environment->path . '/themes/saved-theme/index.php'));
            $this->assertFileExists($environment->path . '/plugins/preview/preview.php');
            $this->assertSame('plugins/preview/preview.php', $environment->clone_source['git_plugin_file']);
            $this->assertSame('Saved title', $GLOBALS['wpdb']->get_var("SELECT option_value FROM `{$environment->get_table_prefix()}options` WHERE option_name = 'blogname'"));
        }
        $this->assertFileExists(WP_CONTENT_DIR . '/mu-plugins/rudel-git-checkout.php');
        $this->assertFileDoesNotExist($templates->get_template_path('starter') . '/template.json');
        $this->assertTrue($templates->delete('starter'));
        $this->assertFalse($GLOBALS['wpdb']->hasTable($saved['table_prefix'] . 'options'));
        $this->assertTrue($GLOBALS['wpdb']->hasTable($first->get_table_prefix() . 'options'));
        $this->assertTrue($GLOBALS['wpdb']->hasTable('wp_options'));
    }

    public function testUnknownAndUnsafeTemplateNamesFailBeforeCreation(): void
    {
        $manager = new EnvironmentManager($this->tmpDir);
        foreach (['missing-template', '../escape'] as $name) {
            try {
                $manager->create('Should fail', ['template'=>$name]);
                $this->fail('Unknown template was accepted.');
            } catch (\RuntimeException|\InvalidArgumentException $error) {
                $this->assertStringContainsString('template', strtolower($error->getMessage()));
            }
        }
        $this->assertSame([], $manager->list());
        $this->assertTrue($GLOBALS['wpdb']->hasTable('wp_options'));
    }
}
