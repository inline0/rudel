<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\AppManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }

    // create()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppWithDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Client A', ['client-a.com'], ['engine' => 'sqlite']);

        $this->assertNotEmpty($app->id);
        $this->assertSame('Client A', $app->name);
        $this->assertSame('app', $app->type);
        $this->assertTrue($app->is_app());
        $this->assertSame(['client-a.com'], $app->domains);
        $this->assertDirectoryExists($app->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppWritesDomainMap(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Map Test', ['mapped.com'], ['engine' => 'sqlite']);

        $mapPath = $this->tmpDir . '/domains.json';
        $this->assertFileExists($mapPath);

        $map = json_decode(file_get_contents($mapPath), true);
        $this->assertArrayHasKey('mapped.com', $map);
        $this->assertSame($app->id, $map['mapped.com']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppNormalizesDomainsToLowercase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Case Test', ['Client-A.COM'], ['engine' => 'sqlite']);

        $this->assertSame(['client-a.com'], $app->domains);

        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('client-a.com', $map);
        $this->assertSame($app->id, $map['client-a.com']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRequiresDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one domain');
        $manager->create('No Domain', [], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsInvalidDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid domain');
        $manager->create('Bad Domain', ['not a domain'], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsSubsiteEngine(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subsite engine');
        $manager->create('Subsite App', ['sub.com'], ['engine' => 'subsite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsDuplicateDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $manager->create('First App', ['taken.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already mapped');
        $manager->create('Second App', ['taken.com'], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsDuplicateDomainIgnoringCase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $manager->create('First App', ['Taken.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already mapped');
        $manager->create('Second App', ['taken.COM'], ['engine' => 'sqlite']);
    }

    // list() / get()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListAndGet(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('List Test', ['list-test.com'], ['engine' => 'sqlite']);

        $all = $manager->list();
        $this->assertCount(1, $all);

        $found = $manager->get($app->id);
        $this->assertNotNull($found);
        $this->assertSame($app->id, $found->id);
    }

    // destroy()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyRemovesAppAndDomainMap(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Destroy Test', ['destroy-test.com'], ['engine' => 'sqlite']);

        $this->assertTrue($manager->destroy($app->id));
        $this->assertNull($manager->get($app->id));

        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('destroy-test.com', $map);
    }

    // add_domain() / remove_domain()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddAndRemoveDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Test', ['primary.com'], ['engine' => 'sqlite']);

        $manager->add_domain($app->id, 'secondary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('secondary.com', $map);
        $this->assertSame($app->id, $map['secondary.com']);

        $manager->remove_domain($app->id, 'secondary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('secondary.com', $map);
        $this->assertArrayHasKey('primary.com', $map);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddAndRemoveDomainNormalizeCase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Case Test', ['primary.com'], ['engine' => 'sqlite']);

        $manager->add_domain($app->id, 'WWW.Primary.COM');
        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('www.primary.com', $map);
        $this->assertSame($app->id, $map['www.primary.com']);

        $manager->remove_domain($app->id, 'www.primary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('www.primary.com', $map);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCannotRemoveLastDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Last Domain', ['only.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('last domain');
        $manager->remove_domain($app->id, 'only.com');
    }

    // get_url()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppGetUrlReturnsDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('URL Test', ['url-test.com'], ['engine' => 'sqlite']);

        $this->assertSame('https://url-test.com/', $app->get_url());
    }
}
