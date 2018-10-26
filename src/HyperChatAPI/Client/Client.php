<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Client;

use Inbenta\ChatbotConnector\HyperChatAPI\Request\Request;
use Inbenta\ChatbotConnector\HyperChatAPI\Dispatcher\BasicDispatcher;
use Inbenta\ChatbotConnector\HyperChatAPI\Resources as Resources;

class Client
{
    protected $request;

    public function __construct($dispatcher, $options = array())
    {
        $this->request = new Request($dispatcher);

        $this->apps = new Resources\Apps($this);
        $this->agents = new Resources\Agents($this);
        $this->chats = new Resources\Chats($this);
        $this->id = new Resources\Id($this);
        $this->invitations = new Resources\Invitations($this);
        $this->media = new Resources\Media($this);
        $this->messages = new Resources\Messages($this);
        $this->settings = new Resources\Settings($this);
        $this->users = new Resources\Users($this);
        $this->webhooks = new Resources\Webhooks($this);
    }

    /**
     * Instantiate a new client using the Basic dispatcher
     * @param  array  $conf App configuration (i.e.: array('appId' => '{appIdValue}'))
     * @return Client       New client instance
     */
    public static function basic($conf = array())
    {
        return new Client(new BasicDispatcher($conf));
    }

    /**
     * Perform a GET request
     * @param  string   $path    Resource path
     * @param  array    $query   Query parameters
     * @param  array    $params  Body/payload parameters
     * @return Response          Response object
     */
    public function get($path, $query = null, $params = array())
    {
        return $this->request->send('GET', $path, $query, $params);
    }

    /**
     * Perform a POST request
     * @param  string   $path    Resource path
     * @param  array    $query   Query parameters
     * @param  array    $params  Body/payload parameters
     * @return Response          Response object
     */
    public function post($path, $query = null, $params = array())
    {
        return $this->request->send('POST', $path, $query, $params);
    }

    /**
     * Perform a PUT request
     * @param  string   $path    Resource path
     * @param  array    $query   Query parameters
     * @param  array    $params  Body/payload parameters
     * @return Response          Response object
     */
    public function put($path, $query = null, $params = array())
    {
        return $this->request->send('PUT', $path, $query, $params);
    }

    /**
     * Perform a DELETE request
     * @param  string   $path    Resource path
     * @param  array    $query   Query parameters
     * @param  array    $params  Body/payload parameters
     * @return Response          Response object
     */
    public function delete($path, $query = null, $params = array())
    {
        return $this->request->send('DELETE', $path, $query, $params);
    }

    /**
     * Download a file
     *
     * @return Response Response object
     */
    public function download($path, $query = null)
    {
        return $this->request->download($path, $query);
    }

    /**
     * Upload a file
     *
     * @return Response Response object
     */
    public function upload($path, $data)
    {
        return $this->request->upload($path, $data);
    }

    /**
     * Get the server URL (+ port) to which the client is making requests
     *
     * @return string Server URI
     */
    public function getServerUri()
    {
        return $this->request->getServerUri();
    }

    /**
     * Get the server URL (+ port + API version) to which the client is making requests
     *
     * @return string Server URI
     */
    public function getVersionedServerUri()
    {
        return $this->request->getVersionedServerUri();
    }
}
