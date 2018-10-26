<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Resources;

class Media extends ResourcesBase
{
    const BASE_RESOURCE_PATH = 'media/';

    /**
     * Find all the media related with the current app.
     * Filters can be specified.
     *
     * @param  array  $query  Array of query parameters
     * @return array          Array of agent objects
     */
    public function findAll($query = array())
    {
        return $this->client->get($this->fullPath(), $query);
    }

    /**
     * Upload the specified media element to the server
     *
     * @param  array  $data  Media parameters (multipart form data, sender and chat info)
     * @return object         Uploaded media
     */
    public function upload($data = array())
    {
        return $this->client->upload($this->fullPath(), $data);
    }

    /**
     * Download the specified media element from the server
     *
     * @param  string  $mediaId  Media ID
     * @param  array   $query    Query parameters
     * @return media             Downloaded media
     */
    public function download($mediaId, $query = array())
    {
        return $this->client->download($this->client->getVersionedServerUri() . $this->fullPath($mediaId), $query);
    }
}
