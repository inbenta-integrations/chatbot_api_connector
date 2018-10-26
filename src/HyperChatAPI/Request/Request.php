<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Request;

class Request
{
    public $headers = array();
    public $query = array();
    public $body = array();
    public $method = '';
    public $path = '';

    protected $dispatcher;

    private $DEFAULTS = array();

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->_setDefaults();
    }

    // returns new response
    public function send($method, $path, $query = array(), $params = array(), $headers = array())
    {
        $this->method = $method;
        $this->path = $path;
        $this->moveAuthToHeaders($query, $headers);
        $this->moveAuthToHeaders($params, $headers);
        $this->addHeaders($headers);
        $this->addQueryStringParams($query);
        $this->addBody($params);

        return $this->dispatcher->dispatch($this);
    }

    public function upload($path, $data, $headers = array())
    {
        $this->method = 'POST';
        $this->path = $path;
        $this->moveAuthToHeaders($data, $headers);
        $this->addHeaders($headers);
        $this->addMultipart($data);

        return $this->dispatcher->dispatch($this);
    }

    /**
     * Download a file from the given path
     * @param  string $path
     * @param  array  $query
     * @return bytes         File contents
     */
    public function download($path, $query = array())
    {
        // Collect all headers that might be used for downloading.
        // This is not done using 'moveAuthToHeaders' because here we build a String.
        $header = '';
        if (isset($query['appId'])) {
            $header .= "x-hyper-appid: {$query['appId']}\r\n";
        }
        if (isset($query['token'])) {
            $header .= "x-hyper-token: {$query['token']}\r\n";
        }
        if (isset($query['secret'])) {
            $header .= "x-hyper-secret: {$query['secret']}\r\n";
        }
        // Create a stream with the headers
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => $header
            )
        ));
        // Add the context to the call
        return file_get_contents($path, false, $context);
    }

    public function addHeaders($headers)
    {
        $headers = $this->_parseHeaders($headers);
        $this->headers = $headers;
    }

    public function addQueryStringParams($query)
    {
        $query = $this->_parseQuery($query);
        $this->query = $query;
    }

    public function addBody($params)
    {
        $params = $this->_parseParameters($params);
        $this->body = $params;
    }

    public function addMultipart($data)
    {
        $this->multipart = [];
        foreach ($data as $key => $value) {
            $this->multipart[] = [
                'name' => $key,
                'contents' => $value
            ];
        }
    }

    public function getServerUri()
    {
        return $this->dispatcher->getBaseUri();
    }

    public function getVersionedServerUri()
    {
        return $this->dispatcher->getFullVersionedUri();
    }

    private function _parseHeaders($headers)
    {
        if (!is_array($headers)) {
            $headers = array();
        }
        return array_merge($this->DEFAULTS['headers'], $headers);
    }

    private function _parseQuery($query)
    {
        if (!is_array($query)) {
            $query = array();
        }
        return array_merge($this->DEFAULTS['query'], $query);
    }

    private function _parseParameters($params)
    {
        if (!is_array($params)) {
            $params = array();
        }
        return array_merge($this->DEFAULTS['body'], $params);
    }

    /**
     * If there are any auth params in the source, move them to headers
     *
     * @param Array $source   Where the auth properties might show up
     * @param Array $headers  Where to move them
     * @return void
     */
    private function moveAuthToHeaders(&$source, &$headers)
    {
        if (is_array($source) && !empty($source)) {
            $authProperties = ['appId', 'secret', 'key', 'token'];
            foreach ($authProperties as $prop) {
                if (isset($source[$prop])) {
                    $headerName = strtolower($prop);
                    $headers = array_merge($headers, array('x-hyper-'.$headerName => $source[$prop]));
                    unset($source[$prop]);
                }
            }
        }
    }

    /**
     * Set the default values for the headers, query and body parameters
     */
    private function _setDefaults()
    {
        $appId = $this->dispatcher->getAppId();
        $appIdHeader = empty($appId) ? array() : array('x-hyper-appid' => $appId);

        $this->DEFAULTS = array(
            'headers' => array_merge(self::_generateVersionHeader(), $appIdHeader),
            'query' => array(),
            'body' => array()
        );
    }

    /**
     * Get the PHP current client version
     * @return string Client version number
     */
    private static function _getClientVersion()
    {
        $version_file = dirname(__FILE__) . "/../VERSION";
        $version_info = file_get_contents($version_file) ?: "0.0.0";
        return str_replace(array("\r", "\n"), "", $version_info);
    }

    /**
     * Generate the version header
     * @return array Array with the X-Inbenta-Hyperchat-Client-Lib generated header
     */
    private static function _generateVersionHeader()
    {
        $posix_available = function_exists('posix_uname');
        $sys_info = $posix_available ? posix_uname() : null;
        $client_info = array(
            'version' => self::_getClientVersion(),
            'language' => 'PHP',
            'language_version' => phpversion(),
            'os' => $posix_available ? $sys_info['sysname'] : php_uname('s'),
            'os_version' => $posix_available ? $sys_info['release'] : php_uname('r')
        );

        return array(
            'X-Inbenta-Hyperchat-Client-Lib' => http_build_query($client_info)
        );
    }
}
