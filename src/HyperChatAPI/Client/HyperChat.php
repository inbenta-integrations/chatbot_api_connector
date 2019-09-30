<?php

namespace Inbenta\ChatbotConnector\HyperChatAPI\Client;

use Inbenta\ChatbotConnector\HyperChatAPI\Client\Client as HyperchatClient;
use Inbenta\ChatbotConnector\HyperChatAPI\ChatExternalService as ExternalService;

class HyperChat
{
    const USER_ALREADY_EXISTS = 409;

    protected $config;
    protected $api;
    protected $extService;

    private $eventHandlers = array();

    public function __construct(array $config, ExternalService $extService = null)
    {
        $this->config = new Config($config);

        $clientConfig = array(
            'appId' => $this->config->get('appId'),
            'secret' => $this->config->get('secret'),
        );
        if ($this->config->get('region') !== null) {
            $clientConfig['region'] = $this->config->get('region');
        } else if ($this->config->get('server') !== null) {
            $clientConfig['server'] = $this->config->get('server');
        }
        $this->api = HyperchatClient::basic($clientConfig);

        if (!is_null($extService)) {
            $this->extService = $extService;
        }
    }

    public function getContentUrl($mediaUrl)
    {
        // avoid to put '/' two times in the url
        if (substr($mediaUrl, 0, 1) === '/') {
            $mediaUrl = substr($mediaUrl, 1);
        }

        return $this->api->getVersionedServerUri().$mediaUrl."?appId=".$this->config->get('appId')."&secret=".$this->config->get('secret');
    }

    /**
     * Register a new event handler (typically for an event which is not in the switch below)
     * @param  string   $event   Event name
     * @param  function $handler Handler function
     */
    public function registerEventHandler($event, $handler)
    {
        $this->eventHandlers[$event] = $handler;
    }

    /**
     * Handle an incoming event and perform the required logic
     */
    public function handleEvent()
    {
        // listen for a webhook handshake call
        if ($this->webhookHandshake() === true) {
            return;
        }

        // get event data
        $event = json_decode(file_get_contents('php://input'), true);
        if (!empty($event) &&
            isset($event['trigger']) &&
            !empty($event['data'])
        ) {
            $eventData = $event['data'];

            // if the event trigger has a custom handler defined, execute this one
            if (in_array($event['trigger'], array_keys($this->eventHandlers))) {
                $handler = $this->eventHandlers[$event['trigger']];
                return $handler($event);
            }

            // or respond with the default logic depending on the event type
            switch ($event['trigger']) {
                case 'messages:new':
                    if (empty($eventData['message'])) {
                        return;
                    }
                    $messageData = $eventData['message'];

                    $chat = $this->getChatInfo($messageData['chat']);
                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }
                    $sender = $this->getUserInfo($messageData['sender']);
                    if (!empty($sender->providerId)) {
                        $targetUser = $this->getUserInfo($chat->creator);

                        if ($messageData['type'] === 'media') {
                            $fullUrl = $this->getContentUrl($messageData['message']['url']);
                            $messageData['message']['fullUrl'] = $fullUrl;
                            $messageData['message']['contentBase64'] =
                                'data:'.$messageData['message']['type'].';base64,'.
                                base64_encode(file_get_contents($fullUrl));
                        }

                        // send message
                        $this->extService->sendMessageFromAgent(
                            $chat,
                            $targetUser,
                            $sender,
                            $messageData,
                            $event['created_at']
                        );
                    }

                    break;

                case 'chats:close':
                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $userId = $eventData['userId'];
                    $isSystem = ($userId === 'system') ? true : false;
                    $user = !$isSystem ? $this->getUserById($eventData['userId']) : null;

                    if (($user && !empty($user->providerId)) || $isSystem) {
                        $targetUser = $this->getUserInfo($chat->creator);
                        $extChatId = $chat->externalId;
                        // notify chat close
                        $attended = true;
                        $this->extService->notifyChatClose(
                            $chat,
                            $targetUser,
                            $isSystem,
                            $attended,
                            !$isSystem ? $user : null
                        );
                    }

                    break;

                case 'invitations:accept':
                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $agent = $this->getUserById($eventData['userId']);
                    $targetUser = $this->getUserInfo($chat->creator);

                    $this->extService->notifyChatStart($chat, $targetUser, $agent);

                    break;

                case 'users:activity':
                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $targetUser = $this->getUserInfo($chat->creator);

                    switch ($eventData['type']) {
                        case 'not-writing':
                            $this->extService->sendTypingPaused($chat, $targetUser);
                            break;
                        case 'writing':
                            $this->extService->sendTypingActive($chat, $targetUser);
                            break;
                        default:
                            $this->extService->sendTypingPaused($chat, $targetUser);
                            break;
                    }

                    break;

                case 'forever:alone':
                    $chat = $this->getChatInfo($event['data']['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $targetUser = $this->getUserInfo($chat->creator);

                    // close chat on server
                    $this->api->chats->close($chat->id, array('secret' => $this->config->get('secret')));

                    $system = true;
                    $attended = false;
                    $this->extService->notifyChatClose($chat, $targetUser, $system, $attended);

                    break;

                case 'queues:update':
                    $chat = $this->getChatInfo($eventData['chatId']);
                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }
                    $user = $this->getUserInfo($eventData['userId']);
                    $data = $eventData['data'];
                    $this->extService->notifyQueueUpdate($chat, $user, $data);
            }
        }
    }

    public function openChat($data)
    {
        $queueActive = false;
        $queueConfig = $this->config->get('queue');
        if ($queueConfig && isset($queueConfig['active'])) {
            $queueActive = $queueConfig['active'];
        }

        // try to register the user, if it exists, update its info
        $user = $this->signupOrUpdateUser($data['user']);
        if (!$user) {
            return (object) [ 'error' => 'Error signing up the user' ];
        }

        // Save in session that a chat is being opened
        if(isset($data['user']) && isset($data['user']['externalId'])) {
            if($this->getChatOpening($data['user']['externalId'])) {
                return (object) [ 'error' => 'Already opening a chat' ];
            }
            $this->setChatOpening($data['user']['externalId'],true);
        }

        // try to get an active chat, and if there's none, create it
        $chat = $this->getActiveChat($user);
        if ($chat !== null && $chat->status !== 'closed') {
            // if the user has an active chat with the same external ID, return it.
            // if there's no externalId specified in data, return it too
            $considerExternalId = isset($data['chat']) && isset($data['chat']['externalId']) && !empty($data['chat']['externalId']);
            if (!$considerExternalId || $chat->externalId === $data['chat']['externalId']) {
                $this->chatOpeningEnd($data['user']['externalId']);
                return (object) [
                    'chat' => $chat,
                    'existed' => true
                ];
            }
        }

        // Check for available agents before trying to create a new chat
        $roomId = !empty($data['roomId']) ? $data['roomId'] : $this->config->get('roomId');
        $check = $queueActive ? $this->checkAgentsOnline($roomId) : $this->checkAgentsAvailable($roomId);
        if (!$check) {
            $this->chatOpeningEnd($data['user']['externalId']);
            return (object) [ 'error' => 'No available agents' ];
        }

        // create a new chat
        $requestBody = array(
            'secret' => $this->config->get('secret'),
            'creator' => $user->id,
            'room' => $roomId,
            'source' => $this->config->get('source'),
            'lang' => $this->config->get('lang'),
        );
        if (isset($data['chat']['externalId'])) {
            $requestBody['externalId'] = $data['chat']['externalId'];
        }
        if (isset($data['history'])) {
            $processedHistory = array_map(function($entry) use (&$user, $data){
                // if the sender is the user, set it's userId
                if ($entry['sender'] !== 'assistant') {
                    $entry['sender'] = $user->id;
                }
                $this->chatOpeningEnd($data['user']['externalId']);
                return $entry;
            }, $data['history']);

            $requestBody['history'] = $processedHistory;
        }

        $response = $this->api->chats->create($requestBody);
        if (isset($response->error)) {
            $this->chatOpeningEnd($data['user']['externalId']); 
            return (object) [ 'error' => 'Chat creation failed. '.$response->error->message ];
        }

        $chat = $response->chat;

        if (!$queueActive) {
            // assign the chat to any available agent
            $response = $this->api->chats->assign($chat->id, [ 'secret' => $this->config->get('secret') ]);

            if (isset($response->error)) {
                $this->chatOpeningEnd($data['user']['externalId']);
                return (object) [ 'error' => 'Chat assignation failed. '.$response->error->message ];
            }
        }

        $this->chatOpeningEnd($data['user']['externalId']);
        return (object) [
            'chat' => $chat,
            'existed' => false
        ];
    }

    public function sendMessage($data)
    {
        // Get the userId for later usage
        $user = $this->getUserByExternalId($data['user']['externalId']);
        if (is_null($user)) {
            return (object) [ 'error' => 'User does not exist' ];
        }
        $chat = $this->getActiveChat($user);
        if (is_null($chat)) {
            return (object) [ 'error' => 'Chat does not exist, `openChat` first.' ];
        }
        // Send a message to the chat
        $response = $this->api->chats->sendMessage($chat->id, [
            'secret' => $this->config->get('secret'),
            'chatId' => $chat->id,
            'sender' => $user->id,
            'message' => $data['message']
        ]);
        if (isset($response->error)) {
            return (object) [ 'error' => 'Sending message failed. '.$response->error->message ];
        }
        return $response;
    }

    public function sendMedia($data)
    {
        // Get the userId for later usage
        $user = $this->getUserByExternalId($data['user']['externalId']);
        if (is_null($user)) {
            return (object) [ 'error' => 'User does not exist' ];
        }
        $chat = $this->getActiveChat($user);
        if (is_null($chat)) {
            return (object) [ 'error' => 'Chat does not exist, `openChat` first.' ];
        }
        // Send a media to the chat
        $response = $this->api->media->upload([
            'secret' => $this->config->get('secret'),
            'chatId' => $chat->id,
            'senderId' => $user->id,
            'media' => $data['media']
        ]);
        if (isset($response->error)) {
            return (object) [ 'error' => 'Sending media failed. '.$response->error->message ];
        }
        return $response;
    }

    public function closeChat($data)
    {
        // Get the userId for later usage
        $user = $this->getUserByExternalId($data['user']['externalId']);
        if ($user === null) {
            return (object) [ 'error' => 'User does not exist' ];
        }
        $chat = $this->getActiveChat($user);
        if (is_null($chat)) {
            return (object) [ 'error' => 'Chat does not exist, `openChat` first.' ];
        }
        $response = $this->api->chats->close($chat->id, array(
            'secret' => APP_SECRET,
            'userId' => $user->id
        ));
        if (isset($result->error)) {
            return (object) [ 'error' => 'Closing chat failed. '.$response->error->message ];
        }
        return $response;
    }

    /**
     * Check for available agents in a single room
     * @param  string   $roomId
     * @return boolean
     */
    public function checkAgentsAvailable($roomId = null)
    {
        $roomId = $roomId ? $roomId : $this->config->get('roomId');
        if (!$roomId) {
            return false;
        }
        $response = $this->api->agents->available([ 'roomIds' => $roomId ]);
        return (
            property_exists($response, 'agents') &&
            property_exists($response->agents, $roomId) &&
            $response->agents->{$roomId} > 0
        );
    }

    /**
     * Check for online agents in a single room
     * @param  string   $roomId
     * @return boolean
     */
    public function checkAgentsOnline($roomId = null)
    {
        $roomId = $roomId ? $roomId : $this->config->get('roomId');
        if (!$roomId) {
            return false;
        }
        $response = $this->api->agents->online([ 'roomId' => $roomId ]);
        return (
            property_exists($response, 'agentsOnline') &&
            $response->agentsOnline == true
        );
    }

    /**
     * Signup a new user or update his/her data if it already exists
     * @param  array    $userData
     * @return object
     */
    protected function signupOrUpdateUser($userData)
    {
        $user = null;

        $requestBody = array(
            'name' => $userData['name'],
        );
        if (!empty($userData['externalId'])) {
            $requestBody['externalId'] = $userData['externalId'];
        }
        if (!empty($userData['extraInfo'])) {
            $requestBody['extraInfo'] = (object) $userData['extraInfo'];
        }
        $response = $this->api->users->signup($requestBody);

        // if a user with the same externalId already existed, just update its data
        if (isset($response->error)) {
            if ($response->error->code === HyperChat::USER_ALREADY_EXISTS) {
                $user = $this->getUserByExternalId($requestBody['externalId']);

                $result = $this->updateUser($user->id);
                $user = $result ? $result : $user;
            } else {
                return false;
            }
        } else {
            $user = $response->user;
        }

        return $user;
    }

    /**
     * Update a user's data
     * @param  string $userId
     * @param  array  $data   Data to update
     * @return object         User's new data
     */
    protected function updateUser($userId, $data = null)
    {
        $payload = [ 'secret' => $this->config->get('secret') ];
        if (isset($data['extraInfo'])) {
            $payload['extraInfo'] = $data['extraInfo'];
        } else {
            return false;
        }
        $response = $this->api->users->update($userId, $payload);
        return (isset($response->user)) ? $response->user : false;
    }

    /**
     * Get a user's data using his/her external ID
     * @param  mixed  $extId
     * @return object        User data
     */
    protected function getUserByExternalId($extId)
    {
        $response = $this->api->users->findAll([
            'secret' => $this->config->get('secret'),
            'externalId' => $extId
        ]);
        if (isset($result->error) || empty($response->users)) {
            return null;
        }
        return $response->users[0];
    }

    /**
     * Get the user external ID
     * @param  string $userId
     * @return string
     */
    protected function getUserExternalId($userId)
    {
        $user = $this->getUserById($userId);
        return ($user) ? $user->externalId : false;
    }


    /**
     * Get a user's data using his/her ID
     * @param  string  $id
     * @return object       User data
     */
    protected function getUserById($id)
    {
        $response = $this->api->users->findById($id, [ 'secret' => $this->config->get('secret') ]);
        if (isset($result->error) || empty($response->user)) {
            return null;
        }
        return $response->user;
    }

    /**
     * Get a user's active chat
     * @param  object|string  $user
     * @return object               Chat data
     */
    protected function getActiveChat($user)
    {
        if (is_string($user)) {
            $user = $this->getUserById($user);
        }
        $activeChats = $user->chats;

        foreach ($activeChats as $chatId) {
            $response = $this->api->chats->findById($chatId, [ 'secret' => $this->config->get('secret') ]);
            if (isset($response->error) || empty($response->chat)) {
                return null;
            }
            return $response->chat;
        }
        return null;
    }

    /**
     * Get the chat's info
     * @param  string $chatId
     * @return object
     */
    protected function getChatInfo($chatId)
    {
        $res = $this->api->chats->findById($chatId, [ 'secret' => $this->config->get('secret') ]);
        if (is_object($res) && !empty($res->chat)) {
            return $res->chat;
        }
        return null;
    }

    protected function getUserInfo($userId)
    {
        $res = $this->api->users->findById($userId, [ 'secret' => $this->config->get('secret') ]);
        if (is_object($res) && !empty($res->user)) {
            return $res->user;
        }
        return null;
    }


    /**
     * Perform webhook handshake (only executed on the webhook setup request)
     * @return void
     */
    private function webhookHandshake()
    {
        if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
            // get the webhook secret
            $xHookSecret = $_SERVER['HTTP_X_HOOK_SECRET'];
            // set response header
            header('X-Hook-Secret: '.$xHookSecret);
            // set response status code
            http_response_code(200);
            return true;
        }
        return false;
    }

    /**
     * Gets the value of the chat opening
     * @return boolean
     */
    private function getChatOpening($userId)
    {
        if(isset($_SESSION[$userId]['__opening__'])) {
            return $_SESSION[$userId]['__opening__'];
        }
        return false;
    }

    /**
     * Sets to the value the opening chat variable
     * @return void
     */
    private function setChatOpening($userId,$value)
    {
        $_SESSION[$userId]['__opening__'] = $value;
    }

    /**
     * Sets the opening to false if it is set
     * @return void
     */
    private function chatOpeningEnd($userId)
    {
        if(isset($_SESSION[$userId]['__opening__'])) {
            $_SESSION[$userId]['__opening__'] = false;
        }
    }
}
