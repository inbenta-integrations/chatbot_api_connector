<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Apps extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'apps/';

    /**
     * Get all apps registered in Hyperchat server
     *
     * @return object Response object
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Get one app using its ID
     *
     * @param  string  $appId  App ID
     * @param  array   $query  Query string parameters
     * @return object          Response object
     */
    public function findById($appId, $query = array())
    {
        return $this->client->get($this->fullPath($appId), $query);
    }

    /**
     * Create a new app using the provided information
     *
     * @param  array  $data   App information
     * @return object         Newly created chat object
     */
    public function create($data)
    {
        return $this->client->post($this->fullPath(), null, $data);
    }

    /**
     * Update the specified agent's information
     *
     * @param  string  $agentId Agent ID
     * @param  array   $data    Array of agent data
     * @return object           Modified agent information
     */
    public function update($appId, $query = array(), $data = array())
    {
        return $this->client->put($this->fullPath($appId), $query, $data);
    }

    /**
     * Validate an app ID
     *
     * @param  string  $appId  App ID
     * @return object          Response object
     */
    public function validate($appId, $query = array())
    {
        return $this->client->get($this->fullPath(sprintf('%s/validate', $appId)), $query);
    }
}
