<?php

/**
 * PHPUnit bootstrap: boots OJS in the dev container like its command line tools do
 * (request with a page router, generic plugins loaded), so that the plugin's classes,
 * the database and translations are available.
 */

chdir('/var/www/html');
define('INDEX_FILE_LOCATION', '/var/www/html/index.php');
require_once './lib/pkp/includes/bootstrap.php';

$application = \APP\core\Application::get();
$router = new \APP\core\PageRouter();
$router->setApplication($application);
$application->getRequest()->setRouter($router);
\PKP\plugins\PluginRegistry::loadCategory('generic');

// Tests are written against the English strings
\PKP\facades\Locale::setLocale('en');

require_once __DIR__ . '/TestCase.php';
