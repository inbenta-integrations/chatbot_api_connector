<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Client;

class Config
{
    private $data = array();

    public function __construct($config)
    {
        if (is_array($config)) {
            $this->data = $config;
        }
    }

    /**
     * Get a key value from the configuration
     * @param  string $key
     * @return mixed
     */
    public function get($key)
    {
        if (!empty($this->data[$key])) {
            return $this->data[$key];
        }
        return null;
    }
}
