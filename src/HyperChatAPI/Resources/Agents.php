<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Agents extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'agents/';

    /**
     * Find all the agents registered in the current app
     *
     * @param  array  $query Array of query parameters
     * @return array          Array of agent objects
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Get one agent's info using its ID
     *
     * @param  string  $agentId  Agent ID
     * @param  array   $query    Query string parameters
     * @return object            Response object
     */
    public function findById($agentId, $query = array())
    {
        return $this->client->get($this->fullPath($agentId), $query);
    }

    /**
     * Get the current available agents in a given room
     *
     * @param  array  $query  Array of query parameters
     * @return array          Array of available agent objects
     */
    public function available($query = array())
    {
        return $this->client->get($this->fullPath('available'), $query);
    }

    /**
     * Get wether there are online agents for the given room and optional language
     *
     * @param  array  $query  Array of query parameters
     * @return bool
     */
    public function online($query = array())
    {
        return $this->client->get($this->fullPath('online'), $query);
    }


    /**
     * Register an agent in the chat app
     *
     * @param  $data Agent data
     * @return array   Recently created agent object
     */
    public function signup($data = array())
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
    public function update($agentId, $query = array(), $data = array())
    {
        return $this->client->put($this->fullPath($agentId), $query, $data);
    }

    /**
     * Log an agent into the chat platform using its credentials
     *
     * @param  array  $data   Agent's login credentials
     * @return object         Recently logged in user information or error
     */
    public function login($data = array())
    {
        return $this->client->post($this->fullPath('login'), null, $data);
    }

    /**
     * Log an agent out of the chat platform
     *
     * @param  array  $data   Agent's identifier and, optionally, room IDs from which to logout
     * @return boolean        True if success, false otherwise
     */
    public function logout($data = array())
    {
        return $this->client->post($this->fullPath('logout'), null, $data);
    }
}
