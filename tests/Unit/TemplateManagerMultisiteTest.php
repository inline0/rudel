<?php

namespace Rudel\Tests\Unit;

use Rudel\TemplateManager;
use Rudel\Tests\RudelTestCase;

class TemplateManagerMultisiteTest extends RudelTestCase
{
    public function testSaveCopiesContentAndRecordsCanonicalSourceUrl(): void
    {
        $GLOBALS['rudel_test_sites'][7] = [
            'blog_id' => 7,
            'domain' => 'alpha-site.example.test',
            'path' => '/',
            'siteurl' => 'http://alpha-site.example.test/',
            'home' => 'http://alpha-site.example.test/',
            'title' => 'Alpha Site',
        ];

        $path = $this->createFakeSandbox(
            'alpha-site',
            'Alpha Site',
            [
                'engine' => 'subsite',
                'blog_id' => 7,
                'multisite' => true,
            ]
        );

        mkdir($path . '/wp-content/themes/demo-theme', 0755, true);
        file_put_contents($path . '/wp-content/themes/demo-theme/style.css', 'body { color: red; }');

        $sandbox = $this->environmentRepository()->get('alpha-site');
        $this->assertNotNull($sandbox);

        $manager = new TemplateManager($this->tmpDir . '/templates');
        $meta = $manager->save($sandbox, 'starter', 'Starter template');

        $this->assertSame('starter', $meta['name']);
        $this->assertSame('http://alpha-site.example.test/', $meta['source_url']);
        $this->assertFileExists($this->tmpDir . '/templates/starter/template.json');
        $this->assertFileExists($this->tmpDir . '/templates/starter/wp-content/themes/demo-theme/style.css');
    }
}
