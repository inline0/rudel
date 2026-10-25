<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('ABSPATH', $argv[1] . '/');
define('RUDEL_PLUGIN_FILE', dirname(__DIR__, 2) . '/rudel.php');
$writer = new Rudel\ConfigWriter();
switch ($argv[2]) {
    case 'installed':
        echo $writer->is_installed() ? 'true' : 'false';
        break;
    case 'install':
        $writer->install();
        break;
    case 'uninstall':
        $writer->uninstall();
        break;
}
