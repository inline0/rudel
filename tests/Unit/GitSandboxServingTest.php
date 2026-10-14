<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Rudel;
use Rudel\Tests\RudelTestCase;

class GitSandboxServingTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGitThemesAndPluginsBecomeSelectedSandboxCode(): void
    {
        $wordpress = $this->tmpDir . '/wordpress';
        mkdir($wordpress . '/wp-content/themes/host-theme', 0755, true);
        file_put_contents($wordpress . '/wp-content/themes/host-theme/style.css', "/* Theme Name: Host */\n");
        define('ABSPATH', $wordpress . '/');
        define('WP_CONTENT_DIR', $wordpress . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('RUDEL_PLUGIN_FILE', dirname(__DIR__, 2) . '/rudel.php');
        $GLOBALS['wpdb']->addTable('wp_options', 'CREATE TABLE wp_options (option_id int, option_name varchar(191), option_value longtext)', [
            ['option_id' => 1, 'option_name' => 'stylesheet', 'option_value' => 'host-theme'],
            ['option_id' => 2, 'option_name' => 'template', 'option_value' => 'host-theme'],
            ['option_id' => 3, 'option_name' => 'home', 'option_value' => 'http://example.test'],
            ['option_id' => 4, 'option_name' => 'siteurl', 'option_value' => 'http://example.test'],
            ['option_id' => 5, 'option_name' => 'active_plugins', 'option_value' => serialize(['rudel/rudel.php'])],
        ]);
        $root = $this->tmpDir . '/http';
        mkdir($root);
        foreach (['remote-theme', 'host-theme', 'remote-plugin'] as $name) {
            $dir = $this->tmpDir . '/' . $name . '-source';
            mkdir($dir);
            $this->git($dir, 'init -q --initial-branch=main');
            $this->git($dir, 'config user.name Test');
            $this->git($dir, 'config user.email test@example.test');
            if ($name === 'remote-plugin') {
                file_put_contents($dir . '/plugin.php', "<?php\n/* Plugin Name: Remote Plugin */\n\$GLOBALS['remote_plugin_marker'] = 'selected';\n");
            } else {
                file_put_contents($dir . '/style.css', "/* Theme Name: Remote */\n");
                file_put_contents($dir . '/index.php', "<?php echo 'remote-theme-marker';\n");
            }
            $this->git($dir, 'add .');
            $this->git($dir, 'commit -qm initial');
            $remote = $root . '/' . $name . '.git';
            $this->git($dir, 'clone --bare ' . escapeshellarg($dir) . ' ' . escapeshellarg($remote));
            $this->git($remote, 'update-server-info');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.err', 'a']], $pipes);
        $this->assertIsResource($server);
        try {
            for ($i = 0; $i < 50; $i++) {
                if (@file_get_contents('http://' . $address . '/remote-theme.git/HEAD') !== false) {
                    break;
                }
                usleep(20_000);
            }
            foreach (['remote-theme', 'host-theme'] as $name) {
                $result = Rudel::create_from_git('http://' . $address . '/' . $name . '.git', ['name' => $name, 'theme' => $name]);
                $this->assertNull($result['git']['error']);
                $environment = $result['environment'];
                $this->assertSame($name, $result['git']['repo_name']);
                $this->assertSame($result['git']['repo_name'], $environment->theme_slug);
                $this->assertFileExists($result['git']['checkout_dir'] . '/index.php');
                $this->assertSame($environment->theme_slug, Rudel::get($environment->id)->theme_slug);
            }
            $result = Rudel::create_from_git('http://' . $address . '/remote-plugin.git', ['name' => 'Plugin', 'type' => 'plugin']);
            $this->assertNull($result['git']['error']);
            $environment = $result['environment'];
            $this->assertSame('plugins/remote-plugin/plugin.php', $environment->clone_source['git_plugin_file']);
            $this->assertFileExists(WP_CONTENT_DIR . '/mu-plugins/rudel-git-checkout.php');
            $options = $GLOBALS['wpdb']->getTableRows($environment->get_table_prefix() . 'options');
            $active = array_values(array_filter($options, static fn ($row) => $row['option_name'] === 'active_plugins'));
            $this->assertContains('remote-plugin/plugin.php', unserialize($active[0]['option_value'], ['allowed_classes' => false]));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private function git(string $dir, string $args): void
    {
        $process = proc_open('git ' . $args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
    }
}
