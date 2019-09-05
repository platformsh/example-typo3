<?php

use Platformsh\ConfigReader\Config;

$platformConfig = new Config();
if ($platformConfig->isValidPlatform()) {

    // Workaround to set the proper env variable
    putenv('PLATFORM_ROUTES_MAIN="' . $platformConfig->getRoute('main')['key'] . '"');

    $databaseConfig = $platformConfig->credentials('database');
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] = 'mysqli';
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] = $databaseConfig['host'];
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] = $databaseConfig['port'];
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = $databaseConfig['path'];
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] = $databaseConfig['username'];
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] = $databaseConfig['password'];

    $redisConfig = $platformConfig->credentials('redis');
    $redisHost = $redisConfig['host'];
    $redisPort = $redisConfig['port'];

    $list = [
        'pages' => 3600*24*7,
        'pagesection' => 3600*24*7,
        'rootline' => 3600*24*7,
        'hash' => 3600*24*7,
        'extbase' => 3600*24*7,
    ];

    $counter = 1;
    foreach ($list as $key => $lifetime) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['backend'] = \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['options'] = [
            'database' => $counter++,
            'hostname' => $redisHost,
            'port' => $redisPort,
            'defaultLifetime' => $lifetime
        ];
    }
} else {
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] = 'mysqli';
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] = '127.0.0.1';
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = 'platform_dev_box';
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] = 'root';
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] = false;
}