<?php
$relationships = getenv("PLATFORM_RELATIONSHIPS");
if ($relationships) {

    $relationships = json_decode(base64_decode($relationships), TRUE);

    foreach ($relationships['database'] as $endpoint) {
        if (empty($endpoint['query']['is_master'])) {
            continue;
        }
        //$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['handlerCfg']['_DEFAULT']['config']['driver'] = 'mysql';
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] =$endpoint['host'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] =$endpoint['port'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = $endpoint['path'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'] = $endpoint['username'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] = $endpoint['password'];
    }

    $redisHost = "";
    $redisPort = "";
    foreach ($relationships['redis'] as $endpoint) {
        $redisHost = $endpoint['host'];
        $redisPort = $endpoint['port'];
    }

    $list = [];
    $counter = 3;
    foreach($list as $key)
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching'][$key] = [
            'backend' => 'TYPO3\CMS\Core\Cache\Backend\RedisBackend',
            'options' => array(
                'database' => $counter++,
                'hostname' => $redisHost,
                'port' => $redisPort
            ),
        ];

}


//$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['pageLoadedFromCache'][] = 'Ksjogo\\Platformsh\\FrontendPagesCache';
