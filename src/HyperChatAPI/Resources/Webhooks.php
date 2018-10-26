<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Webhooks extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'webhooks/';

    /**
     * Get all app's registered webhooks
     *
     * @param  array  $query  Query string parameters
     * @return object         Response object
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Retrieve a webhook's information using its ID
     *
     * @param string    $webhookId  Webhook ID
     * @param array     $query      Array of query parameters
     * @return object               Webhook information
     */
    public function findById($webhookId, $query = array())
    {
        return $this->client->get($this->fullPath($webhookId), $query);
    }

    /**
     * Register a new webhook
     *
     * @param  array  $data   Webook information
     * @return object         Newly created webhook object
     */
    public function create($data)
    {
        return $this->client->post($this->fullPath(), null, $data);
    }

    /**
     * Update the specified webhook's information
     *
     * @param  array  $data   webhooks information
     * @return object         Modified webhooks object
     */
    public function update($webhookId, $data)
    {
        return $this->client->put($this->fullPath($webhookId), null, $data);
    }
}
