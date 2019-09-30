<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Queues extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'queues/';

    /**
     * Get the current global queues status.
     *
     * @param  array  $query  Query parameters array
     * @return array          status data
     */
    public function status($query = array())
    {
        return $this->client->get($this->fullPath('status'), $query);
    }

    /**
     * Get the queue update for the given chat
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters array
     * @return array            queue update data
     */
    public function getUpdate($chatId, $query = array())
    {
        return $this->client->get($this->fullPath('update/' . $chatId), $query);
    }
}
