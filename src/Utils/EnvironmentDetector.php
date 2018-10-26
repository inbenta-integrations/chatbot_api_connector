<?php
namespace Inbenta\ChatbotConnector\Utils;

use \Exception;

class EnvironmentDetector
{
    const DEV_ENV = 'development';
    const STAGING_ENV = 'preproduction';
    const PRODUCTION_ENV = 'production';

    /**
    *  Returns a string with the environment where the application is being run
    */
    public static function detect($conditions)
    {
        $environment = static::PRODUCTION_ENV;

        foreach ($conditions as $env => $condition) {
            if (!isset($condition['type']) || empty($condition['type']) || !isset($condition['regex']) || empty($condition['regex'])) {
                continue;
            }
            $conditionField = strtoupper($condition['type']) == 'HTTP_HOST' ? $_SERVER['HTTP_HOST'] : $_SERVER['SCRIPT_NAME'];
            preg_match_all($condition['regex'], $conditionField, $matches, PREG_SET_ORDER, 0);

            // Check if the environment matches the conditions
            if (count($matches)) {
                $environment = $env;
                break;
            }
        }
        return $environment;
    }    
}
