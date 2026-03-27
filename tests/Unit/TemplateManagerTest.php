<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Sandbox;
use Rudel\SandboxManager;
use Rudel\TemplateManager;
use Rudel\Tests\RudelTestCase;

class TemplateManagerTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveCreatesDirectoryAndMetadata(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Tpl Source');

        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $meta = $manager->save($sandbox, 'starter', 'A starter template');

        $this->assertDirectoryExists($tplDir . '/starter');
        $this->assertFileExists($tplDir . '/starter/template.json');
        $this->assertFileExists($tplDir . '/starter/wordpress.db');
        $this->assertDirectoryExists($tplDir . '/starter/wp-content');
        $this->assertSame('starter', $meta['name']);
        $this->assertSame('A starter template', $meta['description']);
        $this->assertSame($sandbox->id, $meta['source_sandbox_id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveThrowsOnDuplicateName(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Tpl Dup');

        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $manager->save($sandbox, 'starter');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Template already exists');
        $manager->save($sandbox, 'starter');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveThrowsOnInvalidName(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Tpl Invalid');

        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid template name');
        $manager->save($sandbox, '../escape');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsMetadata(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Tpl List');

        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $manager->save($sandbox, 'alpha');
        $manager->save($sandbox, 'beta');

        $list = $manager->list_templates();
        $this->assertCount(2, $list);

        $names = array_column($list, 'name');
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsEmptyWhenNoTemplates(): void
    {
        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $this->assertSame([], $manager->list_templates());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteRemovesDirectory(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Tpl Delete');

        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $manager->save($sandbox, 'disposable');

        $this->assertTrue($manager->delete('disposable'));
        $this->assertDirectoryDoesNotExist($tplDir . '/disposable');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteReturnsFalseForNonexistent(): void
    {
        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);
        $this->assertFalse($manager->delete('nope'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetTemplatePathThrowsOnMissing(): void
    {
        $tplDir = $this->tmpDir . '/templates';
        $manager = new TemplateManager($tplDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found');
        $manager->get_template_path('missing');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateFromTemplateRewritesUrls(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');

        $sandboxDir = $this->tmpDir . '/sandboxes';
        $tplDir = $this->tmpDir . '/templates';

        $sbManager = new SandboxManager($sandboxDir);
        $source = $sbManager->create('Template Source', ['engine' => 'sqlite']);

        $tplManager = new TemplateManager($tplDir);
        $tplManager->save($source, 'mytemplate');

        // Now create a sandbox from the template.
        // We need to make TemplateManager find the right dir, so we use WP_CONTENT_DIR.
        // Instead, pass the template name through options and let SandboxManager handle it.
        // Since SandboxManager creates its own TemplateManager with default dir,
        // we test this differently by using the initialize_from_template path more directly.
        // For a proper integration test, set WP_CONTENT_DIR so TemplateManager resolves correctly.
        define('WP_CONTENT_DIR', $this->tmpDir);

        // Rename templates dir to match what WP_CONTENT_DIR resolves.
        rename($tplDir, $this->tmpDir . '/rudel-templates');

        $newSandbox = $sbManager->create('From Template', ['engine' => 'sqlite', 'template' => 'mytemplate']);

        $pdo = new \PDO('sqlite:' . $newSandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($newSandbox->id), 0, 6) . '_';
        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();

        $this->assertStringContainsString($newSandbox->id, $siteurl);
        $this->assertStringNotContainsString($source->id, $siteurl);
        $this->assertSame('mytemplate', $newSandbox->template);
    }

    // Helpers

    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }

    private function createRealSandbox(string $name): Sandbox
    {
        $sandboxDir = $this->tmpDir . '/sandboxes';
        $manager = new SandboxManager($sandboxDir);
        return $manager->create($name, ['engine' => 'sqlite']);
    }
}
