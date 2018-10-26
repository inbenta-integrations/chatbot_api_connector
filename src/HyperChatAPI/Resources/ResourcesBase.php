<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class ResourcesBase
{
    const BASE_RESOURCE_PATH = '';

    protected $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    protected function fullPath($subPath = '')
    {
        if (empty(static::BASE_RESOURCE_PATH)) {
            throw new \Exception("Base resource path needed");
        }

        if ($subPath) {
            return sprintf(static::BASE_RESOURCE_PATH.'%s', $subPath);
        }
        return static::BASE_RESOURCE_PATH;
    }
}
