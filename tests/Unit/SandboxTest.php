<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Sandbox;
use Rudel\Tests\RudelTestCase;

class SandboxTest extends RudelTestCase
{
    // validateId()

    public function testValidateIdAcceptsSimpleAlphanumeric(): void
    {
        $this->assertTrue(Sandbox::validate_id('abc123'));
    }

    public function testValidateIdAcceptsSingleChar(): void
    {
        $this->assertTrue(Sandbox::validate_id('a'));
    }

    public function testValidateIdAcceptsHyphensAndUnderscores(): void
    {
        $this->assertTrue(Sandbox::validate_id('my-sandbox_2'));
    }

    public function testValidateIdAcceptsUppercase(): void
    {
        $this->assertTrue(Sandbox::validate_id('MyTest'));
    }

    public function testValidateIdAcceptsMaxLength64(): void
    {
        $id = 'a' . str_repeat('b', 63);
        $this->assertSame(64, strlen($id));
        $this->assertTrue(Sandbox::validate_id($id));
    }

    public function testValidateIdRejectsEmpty(): void
    {
        $this->assertFalse(Sandbox::validate_id(''));
    }

    public function testValidateIdRejectsStartingWithHyphen(): void
    {
        $this->assertFalse(Sandbox::validate_id('-abc'));
    }

    public function testValidateIdRejectsStartingWithUnderscore(): void
    {
        $this->assertFalse(Sandbox::validate_id('_abc'));
    }

    public function testValidateIdRejectsSpecialChars(): void
    {
        $this->assertFalse(Sandbox::validate_id('abc!@#'));
    }

    public function testValidateIdRejectsDots(): void
    {
        $this->assertFalse(Sandbox::validate_id('abc.def'));
    }

    public function testValidateIdRejectsSlashes(): void
    {
        $this->assertFalse(Sandbox::validate_id('abc/def'));
    }

    public function testValidateIdRejectsPathTraversal(): void
    {
        $this->assertFalse(Sandbox::validate_id('../etc'));
    }

    public function testValidateIdRejectsSpaces(): void
    {
        $this->assertFalse(Sandbox::validate_id('abc def'));
    }

    public function testValidateIdRejectsOver64Chars(): void
    {
        $id = 'a' . str_repeat('b', 64);
        $this->assertSame(65, strlen($id));
        $this->assertFalse(Sandbox::validate_id($id));
    }

    public function testValidateIdRejectsNullBytes(): void
    {
        $this->assertFalse(Sandbox::validate_id("abc\x00def"));
    }

    // generateId()

    public function testGenerateIdFormatsAsSlugDashHash(): void
    {
        $id = Sandbox::generate_id('My Test');
        $this->assertMatchesRegularExpression('/^my-test-[a-f0-9]{4}$/', $id);
    }

    public function testGenerateIdStripsSpecialChars(): void
    {
        $id = Sandbox::generate_id('Hello World!!! @#$');
        $this->assertMatchesRegularExpression('/^hello-world-[a-f0-9]{4}$/', $id);
    }

    public function testGenerateIdTrimsLeadingTrailingHyphens(): void
    {
        $id = Sandbox::generate_id('---test---');
        $this->assertMatchesRegularExpression('/^test-[a-f0-9]{4}$/', $id);
    }

    public function testGenerateIdTruncatesLongNames(): void
    {
        $longName = str_repeat('abcdefghij', 10); // 100 chars
        $id = Sandbox::generate_id($longName);
        // slug max 48 + '-' + 4 hash = max 53
        $this->assertLessThanOrEqual(53, strlen($id));
    }

    public function testGenerateIdHandlesNumericName(): void
    {
        $id = Sandbox::generate_id('12345');
        $this->assertMatchesRegularExpression('/^12345-[a-f0-9]{4}$/', $id);
    }

    public function testGenerateIdProducesUniqueIds(): void
    {
        $id1 = Sandbox::generate_id('same-name');
        $id2 = Sandbox::generate_id('same-name');
        $this->assertNotSame($id1, $id2, 'Two calls should produce different IDs (different uniqid)');
    }

    public function testGenerateIdResultIsValid(): void
    {
        $id = Sandbox::generate_id('anything');
        $this->assertTrue(Sandbox::validate_id($id));
    }

    public function testGenerateIdHandlesAllSpecialChars(): void
    {
        $id = Sandbox::generate_id('!@#$%^&*()');
        // After stripping, slug is empty, so id is just the hash
        $this->assertTrue(Sandbox::validate_id($id));
    }

    public function testGenerateIdWithEmptySlugUsesSandboxPrefix(): void
    {
        $id = Sandbox::generate_id('!@#$%^&*()');
        $this->assertMatchesRegularExpression('/^sandbox-[a-f0-9]{4}$/', $id);
    }

    public function testGenerateIdHandlesUnicode(): void
    {
        $id = Sandbox::generate_id('café résumé');
        $this->assertTrue(Sandbox::validate_id($id));
    }

    // Constructor and properties

    public function testConstructorSetsAllProperties(): void
    {
        $sandbox = new Sandbox(
            id: 'test-1234',
            name: 'Test',
            path: '/tmp/test',
            created_at: '2026-01-01T00:00:00+00:00',
            template: 'custom',
            status: 'paused',
        );

        $this->assertSame('test-1234', $sandbox->id);
        $this->assertSame('Test', $sandbox->name);
        $this->assertSame('/tmp/test', $sandbox->path);
        $this->assertSame('2026-01-01T00:00:00+00:00', $sandbox->created_at);
        $this->assertSame('custom', $sandbox->template);
        $this->assertSame('paused', $sandbox->status);
    }

    public function testConstructorDefaults(): void
    {
        $sandbox = new Sandbox(
            id: 'test',
            name: 'Test',
            path: '/tmp/test',
            created_at: '2026-01-01',
        );

        $this->assertSame('blank', $sandbox->template);
        $this->assertSame('active', $sandbox->status);
    }

    // fromPath()

    public function testFromPathReturnsSandboxForValidMeta(): void
    {
        $path = $this->createFakeSandbox('valid-123', 'My Sandbox');
        $sandbox = Sandbox::from_path($path);

        $this->assertNotNull($sandbox);
        $this->assertSame('valid-123', $sandbox->id);
        $this->assertSame('My Sandbox', $sandbox->name);
        $this->assertSame('blank', $sandbox->template);
        $this->assertSame('active', $sandbox->status);
    }

    public function testFromPathReturnsNullWhenNoMetaFile(): void
    {
        $path = $this->tmpDir . '/no-meta';
        mkdir($path, 0755);
        $this->assertNull(Sandbox::from_path($path));
    }

    public function testFromPathReturnsNullForInvalidJson(): void
    {
        $path = $this->tmpDir . '/bad-json';
        mkdir($path, 0755);
        file_put_contents($path . '/.rudel.json', 'not json at all {{{');
        $this->assertNull(Sandbox::from_path($path));
    }

    public function testFromPathReturnsNullForEmptyJson(): void
    {
        $path = $this->tmpDir . '/empty-json';
        mkdir($path, 0755);
        file_put_contents($path . '/.rudel.json', '');
        $this->assertNull(Sandbox::from_path($path));
    }

    public function testFromPathHandlesMissingOptionalFields(): void
    {
        $path = $this->tmpDir . '/minimal';
        mkdir($path, 0755);
        file_put_contents($path . '/.rudel.json', json_encode([
            'id' => 'minimal',
            'name' => 'Minimal',
        ]));

        $sandbox = Sandbox::from_path($path);
        $this->assertNotNull($sandbox);
        $this->assertSame('minimal', $sandbox->id);
        $this->assertSame('', $sandbox->created_at);
        $this->assertSame('blank', $sandbox->template);
        $this->assertSame('active', $sandbox->status);
    }

    public function testFromPathTrimsTrailingSlash(): void
    {
        $path = $this->createFakeSandbox('trim-test', 'test');
        $sandbox = Sandbox::from_path($path . '/');
        $this->assertNotNull($sandbox);
        $this->assertStringEndsNotWith('/', $sandbox->path);
    }

    // getDbPath() / getWpContentPath()

    public function testGetDbPath(): void
    {
        $sandbox = new Sandbox('id', 'name', '/sandboxes/my-test', '2026-01-01');
        $this->assertSame('/sandboxes/my-test/wordpress.db', $sandbox->get_db_path());
    }

    public function testGetWpContentPath(): void
    {
        $sandbox = new Sandbox('id', 'name', '/sandboxes/my-test', '2026-01-01');
        $this->assertSame('/sandboxes/my-test/wp-content', $sandbox->get_wp_content_path());
    }

    // getSize()

    public function testGetSizeReturnsZeroForEmptyDir(): void
    {
        $path = $this->tmpDir . '/empty-sandbox';
        mkdir($path, 0755);
        $sandbox = new Sandbox('id', 'name', $path, '2026-01-01');
        $this->assertSame(0, $sandbox->get_size());
    }

    public function testGetSizeSumsAllFiles(): void
    {
        $path = $this->tmpDir . '/sized-sandbox';
        mkdir($path, 0755);
        $this->createFileWithSize($path . '/file1.txt', 100);
        $this->createFileWithSize($path . '/subdir/file2.txt', 200);

        $sandbox = new Sandbox('id', 'name', $path, '2026-01-01');
        $this->assertSame(300, $sandbox->get_size());
    }

    public function testGetSizeIncludesNestedFiles(): void
    {
        $path = $this->tmpDir . '/nested-sandbox';
        mkdir($path . '/a/b/c', 0755, true);
        $this->createFileWithSize($path . '/a/b/c/deep.txt', 50);

        $sandbox = new Sandbox('id', 'name', $path, '2026-01-01');
        $this->assertSame(50, $sandbox->get_size());
    }

    // getUrl()

    public function testGetUrlWithoutWpHome(): void
    {
        $sandbox = new Sandbox('my-sandbox-abcd', 'name', '/tmp/test', '2026-01-01');
        $url = $sandbox->get_url();
        $this->assertSame('/' . RUDEL_PATH_PREFIX . '/my-sandbox-abcd/', $url);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetUrlWithWpHomeDefined(): void
    {
        define('WP_HOME', 'https://example.com');
        $sandbox = new Sandbox('my-sandbox-abcd', 'name', '/tmp/test', '2026-01-01');
        $this->assertSame('https://example.com/' . RUDEL_PATH_PREFIX . '/my-sandbox-abcd/', $sandbox->get_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetUrlWithWpHomeTrimsTrailingSlash(): void
    {
        define('WP_HOME', 'https://example.com/');
        $sandbox = new Sandbox('my-sandbox-abcd', 'name', '/tmp/test', '2026-01-01');
        $this->assertSame('https://example.com/' . RUDEL_PATH_PREFIX . '/my-sandbox-abcd/', $sandbox->get_url());
    }

    // toArray()

    public function testToArrayReturnsAllFields(): void
    {
        $sandbox = new Sandbox(
            id: 'test-id',
            name: 'Test Name',
            path: '/tmp/test',
            created_at: '2026-01-01',
            template: 'blank',
            status: 'active',
        );

        $arr = $sandbox->to_array();
        $this->assertSame([
            'id' => 'test-id',
            'name' => 'Test Name',
            'path' => '/tmp/test',
            'created_at' => '2026-01-01',
            'template' => 'blank',
            'status' => 'active',
        ], $arr);
    }

    // saveMeta()

    public function testSaveMetaWritesJsonFile(): void
    {
        $path = $this->tmpDir . '/save-meta-test';
        mkdir($path, 0755);

        $sandbox = new Sandbox('save-test', 'Save Test', $path, '2026-01-01');
        $sandbox->save_meta();

        $metaPath = $path . '/.rudel.json';
        $this->assertFileExists($metaPath);

        $data = json_decode(file_get_contents($metaPath), true);
        $this->assertSame('save-test', $data['id']);
        $this->assertSame('Save Test', $data['name']);
    }

    public function testSaveMetaUsesUnescapedSlashes(): void
    {
        $path = $this->tmpDir . '/slash-test';
        mkdir($path, 0755);

        // Use the same path for both the sandbox path and where saveMeta writes
        $sandbox = new Sandbox('id', 'name', $path, '2026-01-01');
        $sandbox->save_meta();

        $raw = file_get_contents($path . '/.rudel.json');
        // JSON_UNESCAPED_SLASHES means no \/ in the output
        $this->assertStringNotContainsString('\\/', $raw);
        // But the path should still contain forward slashes
        $this->assertStringContainsString('/', $raw);
    }

    public function testSaveMetaRoundTrips(): void
    {
        $path = $this->tmpDir . '/roundtrip-test';
        mkdir($path, 0755);

        $original = new Sandbox('roundtrip-abc', 'Roundtrip', $path, '2026-03-01T12:00:00+00:00', 'custom', 'active');
        $original->save_meta();

        $loaded = Sandbox::from_path($path);
        $this->assertNotNull($loaded);
        $this->assertSame($original->id, $loaded->id);
        $this->assertSame($original->name, $loaded->name);
        $this->assertSame($original->created_at, $loaded->created_at);
        $this->assertSame($original->template, $loaded->template);
        $this->assertSame($original->status, $loaded->status);
    }
}
