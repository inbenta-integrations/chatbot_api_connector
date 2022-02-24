<?php

namespace Inbenta\ChatbotConnector\ChatbotAPI;

use \Exception;
use \stdClass;

class ChatbotAPIClient extends APIClient
{
    protected $sessionToken = null;
    protected $sessionTokenExpiration = null;
    protected $appData      = null;
    const CACHED_EXTRA_INFO_TTL = 1800; // Time in seconds where "/app/data" cached-data is valid
    const SESSION_TOKEN_TTL = 1440;     // Time in seconds where the session will be alive without user interaction

    function __construct($key, $secret, $session, $conversationConfiguration)
    {
        parent::__construct($key, $secret);

        // Check if Chatbot API endpoint is known
        if (!isset($this->methods) || !isset($this->methods->chatbot)) {
            throw new Exception("Missing Inbenta API endpoints");
        }
        $this->url = $this->methods->chatbot;
        $this->session = $session;
        $this->appDataCacheFile = $this->cachePath . "cached-appdata-" . preg_replace("/[^A-Za-z0-9 ]/", '', $this->key);
        $this->conversationConf = $conversationConfiguration;
    }

    public function setSessionToken($sessionToken)
    {
        $this->sessionToken = $sessionToken;
    }

    public function startConversation($conf = array(), $userType = 0, $environment = 'development', $source = null)
    {
        // Update access token if needed
        $this->updateAccessToken();

        // Prepare configuration array
        $string = json_encode($conf, true);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string),
            "x-inbenta-user-type:" . $userType,                   //Profile
            "x-inbenta-env:" . $environment,                    //Environment
        );

        if (!is_null($source) && $source !== '') {
            $headers[] = "x-inbenta-source: " . $source;
        }

        $response = $this->call("/v1/conversation", "POST", $headers, $params);

        if (!isset($response->sessionToken)) {
            throw new Exception("Error starting conversation: " . json_encode($response, true), 1);
        } else {
            $this->sessionToken = $response->sessionToken;
            $this->sessionTokenExpiration = time() + self::SESSION_TOKEN_TTL;
            $this->session->set('sessionToken.token', $this->sessionToken);
            $this->session->set('sessionToken.expiration', $this->sessionTokenExpiration);
            return $this->sessionToken;
        }
    }

    public function sendMessage($message)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Prepare the message
        $string = json_encode($message);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        $response = $this->call("/v1/conversation/message", "POST", $headers, $params);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    public function trackEvent($data)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Prepare the event
        $string = json_encode($data);
        $params = array("payload" => $string);

        // Headers
        $headers  = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        $response = $this->call("/v1/tracking/events", "POST", $headers, $params);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    /**
     *  Returns the value of an ExtraInfo group. Optional name parameter to retrieve a child value
     *  @param $data_id string
     *  @param $name (optional) string
     *  @return array
     */
    public function getExtraInfo($data_id, $name = '')
    {
        // Get data from cache if it's empty or if the required value is not found
        if (!is_object($this->appData) || !isset($this->appData->$data_id) || ($name !== '' && !isset($this->appData->$data_id->$name))) {
            $this->getExtraInfoFromCache($data_id, $name);
        }
        // Get data from API if cached-data is still empty or if the required value is still not found
        if (!is_object($this->appData) || !isset($this->appData->$data_id) || ($name !== '' && !isset($this->appData->$data_id->$name))) {
            $this->getExtraInfoFromAPI($data_id, $name);
        }
        if ($name !== '') {
            return isset($this->appData->$data_id->$name) ? $this->appData->$data_id->$name : null;
        } else {
            return isset($this->appData->$data_id) ? $this->appData->$data_id : null;
        }
    }

    /**
     *  Get the cached ExtraInfo data
     */
    protected function getExtraInfoFromCache($data_id, $name)
    {
        $this->appData = new stdClass();
        $cachedAppData      = file_exists($this->appDataCacheFile) ? json_decode(file_get_contents($this->appDataCacheFile)) : null;
        $cachedDataSeconds  = file_exists($this->appDataCacheFile) ? time() - filemtime($this->appDataCacheFile) : null;
        if (is_object($cachedAppData) && !empty($cachedAppData) && $cachedDataSeconds < self::CACHED_EXTRA_INFO_TTL) {
            if ($name !== '') {
                $this->appData->$data_id->$name = $cachedAppData;
            } else {
                $this->appData->$data_id = isset($cachedAppData->$data_id) ? $cachedAppData->$data_id : $cachedAppData;
            }
        }
    }

    /**
     *  Request the ExtraInfo data to the API
     */
    protected function getExtraInfoFromAPI($data_id, $name)
    {
        // Update access token if needed
        $this->updateAccessToken();
        $headers = array("x-inbenta-key:" . $this->key, "Authorization: Bearer " . $this->accessToken);
        $response = $this->call("/v1/app/data/" . $data_id . "?name=" . $name, "GET", $headers);
        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        }
        if ($name !== '') {
            $this->appData->$data_id->$name = $response;
        } else {
            $this->appData->$data_id = $response;
        }
        // Store data in cache
        file_put_contents($this->appDataCacheFile, json_encode($this->appData));
    }

    /**
     *  Update the sessionToken if needed
     */
    protected function updateSessionToken()
    {
        $this->sessionToken = $this->session->get('sessionToken.token');
        $this->sessionTokenExpiration = $this->session->get('sessionToken.expiration');
        if (is_null($this->sessionToken) || is_null($this->sessionTokenExpiration) || $this->sessionTokenExpiration < time()) {
            $source = isset($this->conversationConf['source']) ? $this->conversationConf['source'] : null;
            $this->startConversation($this->conversationConf['configuration'], $this->conversationConf['userType'], $this->conversationConf['environment'], $source);
        }
    }

    /**
     * Set a value of a variable
     */
    public function setVariable($variable)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Prepare the message
        $string = json_encode($variable);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        $response = $this->call("/v1/conversation/variables", "POST", $headers, $params);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    /**
     * Set multiple variables
     */
    public function setMultipleVariables($variables)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Prepare the message
        $string = json_encode(['variables' => $variables]);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        $response = $this->call("/v1/conversation/variables/multiple", "POST", $headers, $params);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    /**
     * Get all the available variables of the conversation
     */
    public function getVariables()
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken
        );

        $response = $this->call("/v1/conversation/variables", "GET", $headers, []);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    /**
     * Gets a single variable
     * @param string $name
     */
    public function getVariable(string $name)
    {
        $response = $this->getVariables();
        if (isset($response->{strtolower($name)})) {
            return $response->{strtolower($name)}->value;
        } else {
            return null;
        }
    }

    /**
     * Get the history of the chat
     */
    public function getChatHistory()
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken
        );
        $response = $this->call("/v1/conversation/history", "GET", $headers, []);
        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }
}
