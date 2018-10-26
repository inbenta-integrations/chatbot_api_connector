<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Settings extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'settings/';

    /**
     * Get all app's settings
     *
     * @param  array  $query  Query string parameters
     * @return object         Response object
     */
    public function getAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Override all configuration (or create a new one) for the current app
     *
     * @param  array  $data   Settings information
     * @return object         Newly created settings object
     */
    public function create($data)
    {
        return $this->client->post($this->fullPath(), null, $data);
    }

    /**
     * Update the current app's configuration
     *
     * @param  array  $data   Settings information
     * @return object         Modified settings object
     */
    public function update($data)
    {
        return $this->client->put($this->fullPath(), null, $data);
    }
}
