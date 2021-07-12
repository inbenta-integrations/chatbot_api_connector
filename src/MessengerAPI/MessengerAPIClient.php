<?php

namespace Inbenta\ChatbotConnector\MessengerAPI;

use Inbenta\ChatbotConnector\ChatbotAPI\APIClient;

use \Exception;
//use \stdClass;

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

    /**
     * Get the survey data
     */
    public function getSurveyData(string $chatId, $surveyId)
    {
        $ticketId = $this->getTicketID($chatId);
        $surveyData = [];
        if ($ticketId !== '') {
            $surveyData = $this->surveyStart($ticketId, $surveyId);
        }
        return $surveyData;
    }

    /**
     * Get the ticket ID of the last Chat
     */
    public function getTicketID(string $chatId)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Headers
        $headers = [
            "x-inbenta-key: " . $this->key,
            "Authorization: Bearer " . $this->accessToken
        ];
        $response = $this->call("/v1/tickets?external_id=" . $chatId, "GET", $headers, []);

        if (isset($response->data[0]->id)) {
            return $response->data[0]->id;
        }
        return '';
    }

    /**
     * Start the survey
     */
    public function surveyStart($ticketId, $surveyId)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Headers
        $headers = [
            "x-inbenta-key: " . $this->key,
            "Authorization: Bearer " . $this->accessToken
        ];
        $params = [
            "sourceType" => "ticket",
            "sourceId" => $ticketId
        ];
        $params = [http_build_query($params)];

        $response = $this->call("/v1/surveys/" . $surveyId . "/start", "POST", $headers, $params);
        if (!isset($response->error)) {
            return $response;
        }
        return [];
    }

    /**
     * Sends the responses of the survey
     */
    public function surveySubmit($answers, $token, $surveyId)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Headers
        $headers = [
            "x-inbenta-key: " . $this->key,
            "Authorization: Bearer " . $this->accessToken
        ];
        $params = [
            "field_answers" => $answers,
            "token" => $token
        ];
        $params = [http_build_query($params)];

        $response = $this->call("/v1/surveys/" . $surveyId . "/submit", "POST", $headers, $params);
        if (isset($response->errors[0]->message)) {
            return $response->errors[0]->message;
        }
        return $response;
    }

    /**
     * Get user by a given value
     * @param string $var
     * @param string $value
     */
    public function getUserByParam(string $var, string $value)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Headers
        $headers = [
            "x-inbenta-key: " . $this->key,
            "Authorization: Bearer " . $this->accessToken
        ];

        $response = $this->call("/v1/users?" . $var . "=" . $value, "GET", $headers, []);
        return $response;
    }

    /**
     * Updates the Messenger User info
     */
    public function updatesUserInfo($idUser, $params)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Headers
        $headers = [
            "x-inbenta-key: " . $this->key,
            "Authorization: Bearer " . $this->accessToken
        ];
        $params = [http_build_query($params)];

        $response = $this->call("/v1/users/" . $idUser, "PUT", $headers, $params);
        if (!isset($response->error)) {
            return $response;
        }
        return [];
    }
}
