<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Messages extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'messages/';

    /**
     * Mark the specified message as read
     *
     * @param  string  $messageId   Message ID
     * @param  array   $data      Contains the user that reads the message
     * @return object               Contains the user that has read the message and the message itself
     */
    public function read($messageId, $data = array())
    {
        return $this->client->put($this->fullPath(sprintf('%s/read', $messageId)), null, $data);
    }
}
