<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Invitations extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'invitations/';

    /**
     * Invite the specified user or agent to a chat
     *
     * @param  array   $data    Query parameters
     * @return boolean          Success
     */
    public function invite($data = array())
    {
        return $this->client->post($this->fullPath(), null, $data);
    }

    /**
     * Accept an invitation
     *
     * @param   array    $params  Query parameters. Chat and user data have to be provided (at least both IDs)
     * @return  boolean           True if success, false otherwise
     */
    public function accept($params = array())
    {
        return $this->client->post($this->fullPath('accept'), null, $params);
    }

    /**
     * Reject an invitation
     *
     * @param  array  $params Query parameters. Chat and user data have to be provided (at least both IDs)
     * @return boolean        True if success, false otherwise
     */
    public function reject($params = array())
    {
        return $this->client->post($this->fullPath('reject'), null, $params);
    }
}
