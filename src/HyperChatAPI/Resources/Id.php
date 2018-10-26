<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Id extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'id/';

    /**
     * Get a new random ID
     *
     * @param  array  $query  Query parameters array
     * @return string
     */
    public function get($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }
}
