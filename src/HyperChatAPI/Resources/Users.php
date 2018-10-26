<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Users extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'users/';

    /**
     * Find all users
     *
     * @param  array  $query  Query parameters
     * @return array          All users
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Retrieve a user's information using its ID
     *
     * @param  string  $userId  User ID
     * @param  array   $query  Query parameters
     * @return object           User information
     */
    public function findById($userId, $query = array())
    {
        return $this->client->get($this->fullPath($userId), $query);
    }

    /**
     * Update a user's information
     *
     * @param  string  $userId  User's ID
     * @param  array   $data    Query parameters
     * @return object           User information after modifying it
     */
    public function update($userId, $data = array())
    {
        return $this->client->put($this->fullPath($userId), null, $data);
    }

    /**
     * Register user activity
     *
     * @param  string  $userId  User's ID
     * @param  array   $data    Query parameters, such as activity type
     * @return boolean          Success
     */
    public function activity($userId, $data = array())
    {
        return $this->client->post($this->fullPath(sprintf('%s/activity', $userId)), null, $data);
    }

    /**
     * Register a user in the chat platform (with the user role)
     *
     * @param  array   $data  User data
     * @return object         Newly created user information
     */
    public function signup($data = array())
    {
        return $this->client->post($this->fullPath(), null, $data);
    }
}
