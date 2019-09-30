<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Dispatcher;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

class Dispatcher
{
    const DEFAULT_API_VERSION = 'v1';
    const DEFAULT_SERVER_PORT = 443;

    protected $server_port;
    protected $http;

    private $_appId;
    private $_apiVersion;
    private $_server;

    public function __construct($conf = array())
    {
        $this->setAppId($conf);
        $this->setAPIVersion($conf);
        $this->setServerPort($conf);
        $this->setServerURL($conf);

        $this->http = new Client([ 'base_uri' => $this->getFullVersionedUri() ]);
    }

    /**
     * Perform an HTTP request using the HTTP client
     *
     * @param  Request  $req  Request object
     * @return array          Response
     */
    public function dispatch($req)
    {
        // build the params array
        $params = array(
            'headers' => $req->headers,
            'query'   => $req->query,
        );


        if (isset($req->multipart)) {
            $params['multipart'] = $req->multipart;
        } else if (!empty($req->body)) {
            $params['json'] = $req->body;
        }

        try {
            $this->authenticate($req);
            $response = $this->http->request($req->method, $req->path, $params);
            if ($response && method_exists($response, 'getBody')) {
                $responseBody = $response->getBody();
                if (method_exists($responseBody, 'getContents')) {
                    return json_decode($responseBody->getContents());
                }
            }
        } catch (TransferException $e) {
            if ($e->hasResponse()) {
                return json_decode($e->getResponse()->getBody()->getContents());
            }
        }

        return (object) array();
    }

    /**
     * Get the full base uri depending on selected environment
     * @return string Full server base uri
     */
    public function getBaseUri()
    {
        $uri = $this->_server;

        if (!empty($this->server_port)) {
            $uri .= ':'.$this->server_port;
        }

        return $uri;
    }

    /**
     * Get the full URI including the selected version "prefix"
     * @return string Full URI
     */
    public function getFullVersionedUri()
    {
        return $this->getBaseUri().'/'.$this->getApiVersion().'/';
    }

    /**
     * Retrieve the value of the configured App ID
     * @return string
     */
    public function getAppId()
    {
        return $this->_appId;
    }

    /**
     * Retrieve the value of the configured API version
     * @return string
     */
    public function getApiVersion()
    {
        return $this->_apiVersion;
    }

    /**
     * Authenticate the request (each dispatcher should override this function)
     * @param  Request $request Request object
     * @return Request          Authenticated request object
     */
    protected function authenticate($request)
    {
        return $request;
    }

    /**
     * Set the appId to the class properties
     * @param array $conf Configuration array
     */
    protected function setAppId($conf)
    {
        if (isset($conf['appId']) && is_string($conf['appId'])) {
            $this->_appId = $conf['appId'];
        }
    }

    /**
     * Set the API version selected (or the default)
     * @param array $conf Configuration array
     */
    protected function setAPIVersion($conf)
    {
        $this->_apiVersion = isset($conf['api_version']) ? $conf['api_version'] : self::DEFAULT_API_VERSION;
    }

    /**
     * Set the server port
     * @param array $conf
     */
    protected function setServerPort($conf)
    {
        if (!isset($conf['server_port'])) {
            $this->server_port = self::DEFAULT_SERVER_PORT;
        } else {
            $this->server_port = $conf['server_port'];
        }
    }

    /**
     * Set the server URL
     * @param array $conf
     */
    protected function setServerURL($conf)
    {
        if (isset($conf['server'])) {
            $urlInfo = parse_url($conf['server']);
            if (!empty($urlInfo['port'])) {
                $this->server_port = $urlInfo['port'];
                $conf['server'] = chop($conf['server'], ':'.$this->server_port);
            }
            $this->_server = $conf['server'];
        }
    }
}
