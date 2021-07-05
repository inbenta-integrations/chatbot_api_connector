<?php

namespace Inbenta\ChatbotConnector\MessengerAPI;
use Inbenta\ChatbotConnector\ChatbotAPI\APIClient;

use \Exception;
use \stdClass;

class MessengerAPIClient extends APIClient
{
    protected $cachedCmDataFile      = null;
    const CACHED_CM_DATA_TTL = 1800; // Time in seconds where "/app/data" cached-data is valid

    function __construct($key, $secret, $session)
    {
        parent::__construct($key, $secret);

        // Check if Ticketing API endpoint is known
        if (!isset($this->methods) || !isset($this->methods->ticketing)) {
            throw new Exception("Missing Inbenta API endpoints");
        }
        $this->url = $this->methods->ticketing;
        $this->session = $session;
        $this->cachedCmDataFile = $this->cachePath . "cached-cm-data-" . preg_replace("/[^A-Za-z0-9 ]/", '', $this->key);
    }

    /**
     * Get the working time table from CM instance
     */
    public function getWorkTimeTable()
    {
        // Update access token if needed
        $this->updateAccessToken();

        //get data from cache
        $schedule = $this->getWorkTimeTableFromCache();
        if ($schedule) {
            return $schedule;
        }

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken
        );
        $response = $this->call("/v1/settings/work-timetable", "GET", $headers, []);
        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            file_put_contents($this->cachedCmDataFile, json_encode($response));
            return json_decode(json_encode($response), true);
        }
    }

    /**
     *  Get the cached CM Data
     */
    protected function getWorkTimeTableFromCache()
    {
        $cachedAppData      = file_exists($this->cachedCmDataFile) ? json_decode(file_get_contents($this->cachedCmDataFile), true) : null;
        $cachedDataSeconds  = file_exists($this->cachedCmDataFile) ? time() - filemtime($this->cachedCmDataFile) : null;
        if (is_array($cachedAppData) && !empty($cachedAppData) && $cachedDataSeconds < self::CACHED_CM_DATA_TTL) {
            return $cachedAppData;
        }
        return false;
    }
}