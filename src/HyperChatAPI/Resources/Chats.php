<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Chats extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'chats/';

    /**
     * Find all chats in the selected app
     *
     * @param  array  $query  Query parameters array
     * @return array          Chats array
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Create a new chat using the provided information
     *
     * @param  array  $data   Chat information
     * @return object         Newly created chat object
     */
    public function create($data)
    {
        return $this->client->post($this->fullPath(), null, $data);
    }

    /**
     * Retrieve a chat's information using its ID
     *
     * @param string    $chatId  Chat ID
     * @param array     $query   Array of query parameters
     * @return object            Chat information
     */
    public function findById($chatId, $query = array())
    {
        return $this->client->get($this->fullPath($chatId), $query);
    }

    /**
     * Update the specified chat's information
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $data    Array of chat data
     * @return object           Modified chat information
     */
    public function update($chatId, $data = array())
    {
        return $this->client->put($this->fullPath($chatId), null, $data);
    }

    /**
     * Closes the specified chat
     *
     * @param  string   $chatId  Chat ID
     * @param  array    $query   Query parameters array
     * @return boolean           True if closed successfully, false otherwise
     */
    public function close($chatId, $query = array())
    {
        return $this->client->delete($this->fullPath($chatId), $query);
    }

    /**
     * Look for an agent for the specified chat.
     * Optionally, the algorithm to be used can be specified
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters array, such as algorithm
     * @return object           Selected agent's information
     */
    public function assign($chatId, $query = array())
    {
        return $this->client->get($this->fullPath(sprintf('%s/assign', $chatId)), $query);
    }

    /**
     * Retrieve all messages from the specified chat
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters array
     * @return array            Chat messages
     */
    public function messages($chatId, $query = array())
    {
        return $this->client->get($this->fullPath(sprintf('%s/messages', $chatId)), $query);
    }

    /**
     * Send a new message to the specified chat
     *
     * @param  array  $data  Body parameters, such as chat ID and message text
     * @return object        Newly created message
     */
    public function sendMessage($chatId, $data = array())
    {
        return $this->client->post($this->fullPath(sprintf('%s/messages', $chatId)), null, $data);
    }

    /**
     * Retrieve all the events history from the specified chat.
     * To sum up, it exports the chat in the wanted format (defaults to JSON)
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters, such as export format (supported are: .csv, .json)
     * @return array            All chat history events
     */
    public function history($chatId, $query = array())
    {
        return $this->client->get($this->fullPath(sprintf('%s/history', $chatId)), $query);
    }

    /**
     * Import a set of history entries into the specified chat.
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $data    Body parameters: history entries
     * @return array            All chat history events
     */
    public function importHistory($chatId, $data = array())
    {
        return $this->client->post($this->fullPath(sprintf('%s/history', $chatId)), null, $data);
    }

    /**
     * Send the specified chat back to the inbox and finds a new agent
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters
     * @return object           New assigned agent
     */
    public function toInbox($chatId, $query = array())
    {
        return $this->client->post($this->fullPath(sprintf('%s/toinbox', $chatId)), null, $query);
    }

    /**
     * Retrieve the specified chat users
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $query   Query parameters
     * @return array            Chat users
     */
    public function users($chatId, $query = array())
    {
        return $this->client->get($this->fullPath(sprintf('%s/users', $chatId)), $query);
    }

    /**
     * Join the specified user or agent to a chat
     *
     * @param  string  $chatId   Chat ID
     * @param  array   $data     Query parameters
     * @return boolean           Success
     */
    public function join($chatId, $data = array())
    {
        return $this->client->post($this->fullPath(sprintf('%s/join', $chatId)), null, $data);
    }

    /**
     * Make the specified user or agent leave a chat
     *
     * @param  string  $chatId  Chat ID
     * @param  array   $data   Query parameters
     * @return boolean         Success
     */
    public function leave($chatId, $data = array())
    {
        return $this->client->put($this->fullPath(sprintf('%s/leave', $chatId)), null, $data);
    }
}
