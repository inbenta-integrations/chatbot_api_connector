<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\Client\HyperChat;
use Inbenta\ChatbotConnector\HyperChatAPI\Client\Client as DefaultHyperchatClient;

abstract class HyperChatClient extends HyperChat
{
    protected $session;

    function __construct($config, $lang, $session, $appConf, $externalClient, $messengerClient = null)
    {
        //If external client hasn't been initialized, make a new instance
        if (is_null($externalClient)) {
            // Check if Hyperchat event data is present
            $event = json_decode(file_get_contents('php://input'), true);
            if (!isset($event['trigger'])) {
                return;
            }

            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $event);
            if (is_null($externalId)) {
                return;
            }

            //Instance External Client
            $externalClient = $this->instanceExternalClient($externalId, $appConf);
        }
        $this->session = $session;
        $externalService = new ChatExternalService($externalClient, $lang, $session);
        parent::__construct($config, $externalService, $session, $messengerClient);

        $this->setExitQueueCommand($config);
    }

    //Instances an external client
    abstract protected function instanceExternalClient($externalId, $appConf);

    /**
     **  Checks if an incoming request has to be handled by HyperChat. If not, stops the script.
     **/
    public function handleChatEvent()
    {
        $request = json_decode(file_get_contents('php://input'), true);
        $isEvent = !empty($request) && isset($request['trigger']) && !empty($request['data']);
        $isHookHandshake = isset($_SERVER['HTTP_X_HOOK_SECRET']);

        if ($isEvent || $isHookHandshake) {
            // Stop script if we have an event and the externalClient hasn't been initialized
            if ($isEvent && !$this->extService->validExternalClient()) {
                die();
            }
            // Process handshake or standard events
            $this->handleEvent();

            if (isset($this->session) && !is_null($this->session->get('surveyElements')) && ($request['trigger'] === 'chats:close' || $request['trigger'] === 'forever:alone')) {
                //Continue in the connector to ask for acceptance of the survey
                $this->session->set('surveyConfirm', true);
            } else {
                die();
            }
        }
    }

    /**
     **  Returns the external id of the user from the incoming HyperChat request.
     **/
    public static function getExternalIdFromEvent($config, $event)
    {
        $creator = self::getCreatorFromEvent($config, $event);
        if (isset($creator->externalId)) {
            return $creator->externalId;
        }
        return null;
    }

    /**
     *  Returns chat creator data from the incoming HyperChat request.
     */
    public static function getCreatorFromEvent($config, $event)
    {
        $client = DefaultHyperchatClient::basic(array(
            'appId' => $config['appId'],
            'server' => $config['server']
        ));

        $creator = null;
        if (isset($event['data'])) {
            $chatId = null;
            if (isset($event['data']['chatId'])) {
                $chatId = $event['data']['chatId'];
            } elseif (isset($event['data']['message']) && isset($event['data']['message']['chat'])) {
                $chatId = $event['data']['message']['chat'];
            }
            if ($chatId) {
                $chat    = $client->chats->findById($chatId, array('secret' => $config['secret']));
                $creator = $client->users->findById($chat->chat->creator, array('secret' => $config['secret']))->user;
            }
        }
        return $creator;
    }

    /**
     **  Returns all the chat information from the parent class if a chatId has been specified
     **/
    public function getChatInformation($chatId)
    {
        return !is_null($chatId) ? parent::getChatInfo($chatId) : false;
    }

    /**
     * Returns the user information
     */
    public function getUserInformation($userId)
    {
        return !is_null($userId) ? parent::getUserInfo($userId) : false;
    }

    /**
     * Set in session the value of command for exit of queue
     * @param array $config
     */
    protected function setExitQueueCommand(array $config)
    {
        if ($this->session->get('exitQueueCommand', '') === '') {
            if (isset($config['exitQueueCommand']) && $config['exitQueueCommand'] !== '') {
                $this->session->set('exitQueueCommand', $config['exitQueueCommand']);
            }
        }
    }
}
