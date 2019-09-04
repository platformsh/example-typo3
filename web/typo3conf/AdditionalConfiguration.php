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
        'cache_pages' => 3600*24*7,
        'cache_pagesection' => 3600*24*7,
        'cache_rootline' => 3600*24*7,
        'cache_hash' => 3600*24*7,
        'extbase_reflection' => 0,
        'extbase_datamapfactory_datamap' => 0
    ];

    $counter = 3;
    foreach ($list as $key => $lifetime) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['backend'] = \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['options'] = [
            'database' => $counter++,
            'hostname' => $redisHost,
            'port' => $redisPort,
            'defaultLifetime' => $lifetime
        ];
    }
}