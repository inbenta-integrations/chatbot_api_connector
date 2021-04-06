<?php
namespace Inbenta\ChatbotConnector\HyperChatAPI;

class ChatExternalService 
{
    protected $externalClient;
    protected $lang;
    protected $session;

    function __construct($externalClient = null, $langManager = null, $sessionManager = null)
    {
        $this->externalClient = $externalClient;
        $this->lang = $langManager;
        $this->session = $sessionManager;
    }

    /**
     * Inform if the external client has been initialized
     * @return Boolean
     */
    public function validExternalClient()
    {
        return $this->externalClient !== null;
    }

    /**
     * Notify the external service that a chat has been closed
     *
     * @param  string  $chat
     * @param  string  $targetUser  User to whom the event is sent
     * @param  boolean $isSystem    Whether the chat closer is the system or not
     * @param  boolean $isSystem    Whether the chat has been attended or not
     * @param  boolean $closerUser  User that closed the chat (if it's not the system, null instead)
     */
    public function notifyChatClose($chat, $targetUser, $isSystem, $isAttended, $closerUser = null)
    {
        $this->externalClient->sendTextMessage($this->lang->translate('chat_closed'));
        $this->session->set('chatOnGoing', false);
    }

    /**
     * Notify the external service that a chat has been started
     *
     * @param  string  $chat
     * @param  string  $targetUser  User to whom the event is sent
     * @param  object  agent        Data object of the agent accepting the invitation
     */
    public function notifyChatStart($chat, $targetUser, $agent)
    {
        $name = !empty($agent->nickname) ? $agent->nickname : $agent->name;
        $this->externalClient->sendTextMessage($this->lang->translate('agent_joined', ['agentName' => $name]));
    }

    /**
     * Notify the external service that the queue position has been updated
     *
     * @param  string  $chat
     * @param  string  $user    User waiting
     * @param  object  data     Data object with information on the queue status (queuePosition property)
     */
    public function notifyQueueUpdate($chat, $user, $data)
    {
        $pos = $data['queuePosition'];
        if ($pos > 0) {
            $queue_pos_label = $pos === 1 ? 'queue_estimation_first' : 'queue_estimation';
            $this->externalClient->sendTextMessage($this->lang->translate($queue_pos_label, ['queuePosition' => $pos]));
        }
    }

    /**
     * Send a message from the agent to the external service
     *
     * @param  string $chat
     * @param  string $targetUser User to whom the event is sent
     * @param  string $senderUser User that sends the message
     * @param  string $message   Message to send
     * @param  int    $created   Message creation timestamp
     */
    public function sendMessageFromAgent($chat, $targetUser, $senderUser, $message, $created)
    {
        $type = isset($message['type']) ? $message['type'] : 'unknown';
        switch ($type) {
            case 'text':
                $this->externalClient->sendTextMessage($message['message']);
                break;

            case 'media':
                $this->externalClient->sendAttachmentMessageFromHyperChat($message['message']);
                break;
        }
    }

    /**
     * Send a message from the system to the external service
     *
     * @param  string $extChatId External chat ID
     * @param  string $extUserId External user ID (which would be "system")
     * @param  string $message   Message to send
     * @param  int    $created   Message creation timestamp
     */
    public function sendMessageFromSystem($extChatId, $extUserId, $message, $created)
    {
        $this->externalClient->sendTextMessage($message);
    }

    /**
     * Notify the external service that the agent is typing
     *
     * @param  string $chat
     * @param  string $targetUser User to whom the event is sent
     */
    public function sendTypingActive($chat, $targetUser)
    {
        $this->externalClient->showBotTyping();
    }

    /**
     * Notify the external service that the agent has stopped typing
     *
     * @param  string $chat
     * @param  string $targetUser User to whom the event is sent
     */
    public function sendTypingPaused($chat, $targetUser)
    {
        $this->externalClient->showBotTyping(false);
    }
}
