<?php

namespace Inbenta\ChatbotConnector;

use \Exception;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\Utils\LanguageManager;
use Inbenta\ChatbotConnector\Utils\ConfigurationLoader;
use Inbenta\ChatbotConnector\Utils\EnvironmentDetector;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\ChatbotConnector\MessengerAPI\MessengerAPIClient;
use Spatie\OpeningHours\OpeningHours;

class ChatbotConnector
{
    public    $conf;                // App Configuration
    public    $lang;                // Language manager
    public    $externalClient;      // External service client
    public    $session;             // Session manager
    protected $botClient;           // Chatbot Client
    protected $digester;            // External requests digester
    protected $chatClient;          // Hyperchat client
    protected $messengerClient;     // Messenger client
    protected $environment;         // Application environment

    const ESCALATION_NO_RESULTS        = '__escalation_type_no_results__';
    const ESCALATION_API_FLAG          = '__escalation_type_api_flag__';
    const ESCALATION_NEGATIVE_RATING   = '__escalation_type_negative_rating__';
    const ESCALATION_DIRECT            = '__escalation_type_callback__';
    const ESCALATION_OFFER             = '__escalation_type_offer__';

    function __construct($appPath)
    {
        // Create base components
        $this->conf         = (new ConfigurationLoader($appPath))->getConf();
        $this->lang         = new LanguageManager($this->conf->get('conversation.default.lang'), $appPath);
        $this->environment     = $this->conf->get('environments.current');

        if (empty($this->conf->get('api.key')) || empty($this->conf->get('api.secret'))) {
            throw new Exception("Empty Chatbot API Key or Secret. Please, review your /conf/custom/api.php file");
        }
    }

    /**
     * 	Initialize class components specific for an external service
     */
    public function initComponents($externalClient, $chatClient, $externalDigester)
    {
        //Load application components
        $this->externalClient       = $externalClient;
        $this->digester             = $externalDigester;
        $this->chatClient           = $chatClient;
    }

    /**
     *	Retrieve Language translations from ExtraInfo
     */
    protected function getTranslationsFromExtraInfo($parentGroupName, $translationsObjectName)
    {
        $translations = [];
        $extraInfoData = $this->botClient->getExtraInfo($parentGroupName);
        if (isset($extraInfoData->results)) {
            foreach ($extraInfoData->results as $element) {
                if ($element->name == $translationsObjectName) {
                    $translations = json_decode(json_encode($element->value), true);
                    break;
                }
            }
            $language = $this->conf->get('conversation.default.lang');
            if (isset($translations[$language]) && count($translations[$language]) && is_array($translations[$language][0])) {
                $this->lang->addTranslations($translations[$language][0]);
            }
        }
    }

    /**
     * 	Handle a request (from external service or from Hyperchat)
     */
    public function handleRequest()
    {
        try {
            // Return 200 OK response
            $this->returnOkResponse();
            // Store request
            $request = file_get_contents('php://input');
            // Translate the request into a ChatbotAPI request
            $externalRequest = $this->digester->digestToApi($request);
            // Check if it's needed to perform any action other than a standard user-bot interaction
            $this->handleNonBotActions($externalRequest);
            // Handle standard bot actions
            $this->handleBotActions($externalRequest);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     *	Check if it's needed to perform any action other than a standard user-bot interaction
     */
    protected function handleNonBotActions($digestedRequest)
    {
        //Check if a survey is running
        $this->handleSurvey($digestedRequest);
        // If there is an active chat, send messages to the agent
        if ($this->chatOnGoing()) {
            $this->validateIsInHyperchatQueue($digestedRequest);
            $this->sendMessagesToChat($digestedRequest);
            die();
        }
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            $this->handleEscalation($digestedRequest);
        }
        // If the user clicked in a Federated Bot option, handle its request
        if (is_array($digestedRequest) && isset($digestedRequest[0]['extendedContentAnswer'])) {
            $selectedAnswer = json_decode(json_encode($digestedRequest[0]['extendedContentAnswer']));
            $answers = $this->session->get('federatedSubanswers');
            if (is_int($selectedAnswer) && is_array($answers) && isset($answers[$selectedAnswer])) {
                $this->displayFederatedBotAnswer($answers[$selectedAnswer]);
            } elseif (is_array($selectedAnswer)) {
                $this->displayFederatedBotAnswer($selectedAnswer);
            }
            die();
        }
    }

    /**
     *	Handle an incoming request for the ChatBot
     */
    public function handleBotActions($externalRequest)
    {
        $needEscalation = false;
        $needContentRating = false;
        $hasFormData = false;
        foreach ($externalRequest as $message) {
            // Check if is needed to execute any preset 'command'
            $this->handleCommands($message);
            // Store the last user text message to session
            $this->saveLastTextMessage($message);
            // Check if is needed to ask for a rating comment
            $message = $this->checkContentRatingsComment($message);
            // Send the messages received from the external service to the ChatbotAPI
            $botResponse = $this->sendMessageToBot($message);
            // Check if escalation to agent is needed
            $needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
            if ($needEscalation) {
                $this->deleteLastMessage($botResponse);
            }
            // Check if it has attached an escalation form
            $hasFormData = $this->checkEscalationForm($botResponse);
            // Check if is needed to display content ratings
            $hasRating = $this->checkContentRatings($botResponse);
            $needContentRating = $hasRating ? $hasRating : $needContentRating;
            // Send the messages received from ChatbotApi back to the external service
            $this->sendMessagesToExternal($botResponse);
            //Check if response has a callback for ticket creation
            $this->checkCallbackTicketCreation($botResponse);
        }
        if ($needEscalation || $hasFormData) {
            $this->handleEscalation();
        }
        // Display content rating if needed and not in chat nor asking to escalate
        if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false)) {
            $this->displayContentRatings($needContentRating);
        }
    }

    /**
     * If there is escalation offer, delete the last message (that contains the polar question)
     */
    protected function deleteLastMessage(&$botResponse)
    {
        if (isset($botResponse->answers) && $this->session->get('escalationType') == static::ESCALATION_OFFER) {
            if (count($botResponse->answers) > 0) {
                $elements = count($botResponse->answers) - 1;
                unset($botResponse->answers[$elements]);
            }
        }
    }

    /**
     * 	Checks if a bot response requires escalation to chat
     */
    protected function checkEscalation($botResponse)
    {
        if (!$this->chatEnabled()) {
            return false;
        }

        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }

        // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            $this->updateNoResultsCount($msg);

            $apiEscalateFlag                     = isset($msg->flags) && in_array('escalate', $msg->flags);
            $noResultsToEscalateReached          = $this->shouldEscalateFromNoResults();
            $negativeRatingsToEscalateReached    = $this->shouldEscalateFromNegativeRating();
            $apiEscalateDirect = isset($msg->actions[0]->parameters->callback) ? $msg->actions[0]->parameters->callback == "escalationStart" : false;
            $apiEscalateOffer = isset($msg->attributes->DIRECT_CALL) ? $msg->attributes->DIRECT_CALL == "escalationOffer" : false;

            if ($apiEscalateFlag || $noResultsToEscalateReached || $negativeRatingsToEscalateReached || $apiEscalateDirect || $apiEscalateOffer) {
                // Store into session the escalation type
                if ($apiEscalateFlag) {
                    $escalationType = static::ESCALATION_API_FLAG;
                } elseif ($noResultsToEscalateReached) {
                    $escalationType = static::ESCALATION_NO_RESULTS;
                } elseif ($negativeRatingsToEscalateReached) {
                    $escalationType = static::ESCALATION_NEGATIVE_RATING;
                } elseif ($apiEscalateOffer) {
                    $escalationType = static::ESCALATION_OFFER;
                    $this->session->set('escalationV2', true);
                } elseif ($apiEscalateDirect) {
                    $escalationType = static::ESCALATION_DIRECT;
                    $this->session->set('escalationV2', true);
                }
                $this->session->set('escalationType', $escalationType);

                return true;
            }
        }
        return false;
    }

    /**
     * 	Check if should escalate to chat because the configured no-results count has been reached
     */
    protected function shouldEscalateFromNoResults()
    {
        $triesBeforeEscalation = $this->conf->get('chat.triesBeforeEscalation');
        return $this->chatEnabled() && $triesBeforeEscalation && $this->session->get('noResultsCount') >= $triesBeforeEscalation;
    }

    /**
     * 	Check if should escalate to chat because the configured negative-rating count has been reached
     */
    protected function shouldEscalateFromNegativeRating()
    {
        $negativeRatingsBeforeEscalation = $this->conf->get('chat.negativeRatingsBeforeEscalation');
        return $this->chatEnabled() && $negativeRatingsBeforeEscalation && $this->session->get('negativeRatingCount') >= $negativeRatingsBeforeEscalation;
    }

    /**
     * 	Updates the number of consecutive no-result answers
     */
    protected function updateNoResultsCount($message)
    {
        $count = $this->session->get('noResultsCount');
        if (isset($message->flags) &&  in_array('no-results', $message->flags)) {
            $count++;
        } else {
            $count = 0;
        }
        $this->session->set('noResultsCount', $count);
    }

    /**
     *  Reduce the escalation counter that triggered the current escalation try
     */
    protected function reduceCurrentEscalationCounter()
    {
        $escalationType = $this->session->get('escalationType');
        if ($escalationType == static::ESCALATION_NO_RESULTS) {
            $this->session->set('noResultsCount', $this->session->get('noResultsCount') - 1);
        } elseif ($escalationType == static::ESCALATION_NEGATIVE_RATING) {
            $this->session->set('negativeRatingCount', $this->session->get('negativeRatingCount') - 1);
        }
    }

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */
    protected function handleEscalation($userAnswer = null)
    {
        // Escalate if it has the form done
        $this->escalateIfFormHasBeenDone();

        // Ask the user if wants to escalate
        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                $this->sendEscalationStart();
            } else {
                if ($this->checkServiceHours()) {
                    if ($this->checkAgents()) {
                        // Ask the user if wants to escalate
                        $this->session->set('askingForEscalation', true);
                        $escalationMessage = $this->digester->buildEscalationMessage();
                        $this->externalClient->sendMessage($escalationMessage);
                    } else {
                        // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
                        if ($this->session->get('escalationV2', false)) {
                            $this->setVariableValue("agents_available", "false");
                            $message = ["directCall" => "escalationStart"];
                            $botResponse = $this->sendMessageToBot($message);
                            $this->sendMessagesToExternal($botResponse);
                        } else {
                            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                        }
                        // Because no agents available, reduce the current escalation counter to escalate on next counter update
                        $this->reduceCurrentEscalationCounter();
                        $this->trackContactEvent("CHAT_NO_AGENTS");
                    }
                } else {
                    // throw out of time message
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('out_of_time')));
                }
            }
        } else {
            // Handle user response to an escalation question
            $this->session->set('askingForEscalation', false);
            // Reset escalation counters
            $this->session->set('noResultsCount', 0);
            $this->session->set('negativeRatingCount', 0);

            if (isset($userAnswer[0]['escalateOption'])) {
                if ($userAnswer[0]['escalateOption']) {
                    if ($this->session->get('escalationType') == static::ESCALATION_OFFER) {
                        $this->session->set('escalationOfferYes', true);
                        $this->escalateIfFormHasBeenDone();
                        $this->sendEscalationStart();
                    } else {
                        $this->escalateToAgent();
                    }
                } else {
                    if ($this->session->get('escalationType') == static::ESCALATION_OFFER) {
                        $message = ["message" => "no"];
                        $botResponse = $this->sendMessageToBot($message);
                        $this->sendMessagesToExternal($botResponse);
                    } else {
                        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('escalation_rejected')));
                        $this->trackContactEvent("CONTACT_REJECTED");
                    }
                    $this->session->delete('escalationType');
                    $this->session->delete('escalationV2');
                }
                die();
            }
        }
    }

    /**
     * 	Tries to start a chat with an agent
     */
    protected function escalateToAgent()
    {
        if ($this->checkServiceHours()) {
            if ($this->checkAgents()) {
                // Start chat
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
                $extraInfo = method_exists($this->externalClient, "getExtraInfo") ? $this->externalClient->getExtraInfo() : [];
                // Build user data for HyperChat API
                $chatData = array(
                    'roomId' => $this->conf->get('chat.chat.roomId'),
                    'user' => array(
                        'name'          => $this->externalClient->getFullName(),
                        'contact'       => $this->externalClient->getEmail(),
                        'externalId'    => $this->externalClient->getExternalId(),
                        'extraInfo'     => $extraInfo
                    )
                );
                $history = $this->chatbotHistory();
                if (count($history) > 0) {
                    $chatData['history'] = $history;
                }
                $response =  $this->chatClient->openChat($chatData);
                if (!isset($response->error) && isset($response->chat)) {
                    $this->session->set('chatOnGoing', $response->chat->id);
                    $this->trackContactEvent("CHAT_ATTENDED", $response->chat->id);
                } else {
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
                }
            } else {
                // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
                if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG || $this->session->get('escalationV2', false)) {

                    if ($this->session->get('escalationV2', false)) {
                        $this->setVariableValue("agents_available", "false");
                        $message = ["directCall" => "escalationStart"];
                        $botResponse = $this->sendMessageToBot($message);
                        $this->sendMessagesToExternal($botResponse);
                    } else {
                        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                    }
                }
                $this->trackContactEvent("CHAT_NO_AGENTS");
            }
        } else {
            // throw out of time message
            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('out_of_time')));
        }
        $this->session->delete('escalationForm');
        $this->session->delete('escalationType');
        $this->session->delete('escalationV2');
    }

    /**
     * Check if there are agents available
     * @return boolean
     */
    protected function checkAgents()
    {
        $queueActive = $this->chatClient->isQueueModeActive();
        return $queueActive ? $this->chatClient->checkAgentsOnline() : $this->chatClient->checkAgentsAvailable();
    }

    /**
     * 	Builds a text message in ChatbotApi format, ready to be sent through method 'sendMessagesToExternal'
     */
    protected function buildTextMessage($text)
    {
        $message = array(
            'type' => 'answer',
            'message' => $text
        );
        return (object) $message;
    }

    /**
     * 	Send messages to Chatbot API
     */
    protected function sendMessageToBot($message)
    {
        try {
            if (isset($message['type']) && $message['type'] !== 'answer') {
                // Send event track to bot
                return $this->sendEventToBot($message);
            } elseif (isset($message['message']) || isset($message['option']) || isset($message['directCall'])) {
                // Send message to bot
                $this->externalClient->showBotTyping();
                $botResponse = $this->botClient->sendMessage($message);
                if (isset($botResponse->message) && $botResponse->message == "Endpoint request timed out") {
                    $botResponse = $this->buildTextMessage($this->lang->translate('api_timeout'));
                }
                return $botResponse;
            }
        } catch (Exception $e) {
            //If session expired, start new conversation and retry
            if ($e->getCode() == 400 && $e->getMessage() == 'Session expired') {
                $this->botClient->startConversation($this->conf->get('conversation.default'), $this->conf->get('conversation.user_type'), $this->environment, $this->getSource());
                return $this->sendMessageToBot($message);
            }
            throw new Exception("Error while sending message to bot: " . $e->getMessage());
        }
    }

    /**
     * 	Send messages to the external service. Messages should be formatted as a ChatbotAPI response
     */
    protected function sendMessagesToExternal($messages)
    {
        // Digest the bot response into the external service format
        $digestedBotResponse = $this->digester->digestFromApi($messages,  $this->session->get('lastUserQuestion'));
        foreach ($digestedBotResponse as $message) {
            $this->externalClient->sendMessage($message);
        }
    }

    /**
     * 	Send messages received from external service to HyperChat
     */
    protected function sendMessagesToChat($digestedRequest)
    {
        foreach ($digestedRequest as $message) {
            $message = (object)$message;
            $data = array(
                'user' => array(
                    'externalId' => $this->externalClient->getExternalId()
                ),
                'message' => isset($message->message) ? $message->message : ''
            );

            if (isset($message->media)) {
                $data['media'] = $message->media;
                unset($data['message']);
                $response = $this->chatClient->sendMedia($data);
            } else {
                $response = $this->chatClient->sendMessage($data);
            }
        }
    }

    /**
     * 	Checks if there is a chat session active for the current user
     */
    protected function chatOnGoing()
    {
        if (!$this->chatEnabled()) {
            return false;
        }

        $chat = $this->session->get('chatOnGoing');
        $chatInfo = $this->chatClient->getChatInformation($chat);
        if ($chat && isset($chatInfo->status)) {
            if ($chatInfo->status != 'closed') {
                return true;
            } else {
                $this->session->set('chatOnGoing', false);
            }
        }
        return false;
    }

    /**
     * 	Return if chat is enabled in configuration
     */
    protected function chatEnabled()
    {
        return $this->conf->get('chat.chat.enabled');
    }

    /**
     * 	Store the last user text message to session
     */
    protected function saveLastTextMessage($message)
    {
        if (isset($message['message']) && is_string($message['message'])) {
            $this->session->set('lastUserQuestion', $message['message']);
        }
    }

    /**
     * 	Check if a bot response should display content-ratings
     */
    protected function checkContentRatings($botResponse)
    {
        $ratingConf = $this->conf->get('conversation.content_ratings');
        if (!$ratingConf['enabled']) {
            return false;
        }

        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }

        // Check messages are answer and have a rate-code
        $rateCode = false;
        foreach ($messages as $msg) {
            $isAnswer            = isset($msg->type) && $msg->type == 'answer';
            $hasEscalationCallBack = isset($msg->actions) ? $msg->actions[0]->parameters->callback == "escalationStart" : false;
            $hasEscalationCallBack2 = isset($msg->attributes->DIRECT_CALL) ? $msg->attributes->DIRECT_CALL == "escalationOffer" : false;
            $hasEscalationRedirect = isset($msg->attributes->DYNAMIC_REDIRECT) ? $msg->attributes->DYNAMIC_REDIRECT == "escalationOffer" : false;
            $hasEscalationFlag   = isset($msg->flags) && in_array('escalate', $msg->flags);
            $hasNoRatingsFlag    = isset($msg->flags) && in_array('no-rating', $msg->flags);
            $hasRatingCode       = isset($msg->parameters->contents->trackingCode->rateCode);

            if (
                $isAnswer && $hasRatingCode && !$hasEscalationFlag && !$hasNoRatingsFlag
                && !$hasEscalationCallBack && !$hasEscalationCallBack2 && !$hasEscalationRedirect
            ) {
                $rateCode = $msg->parameters->contents->trackingCode->rateCode;
            }
        }
        return $rateCode;
    }

    /**
     * 	Checks if a content-rating answer should ask for a comment
     */
    protected function checkContentRatingsComment($message)
    {
        // If is a rating message
        if (isset($message['ratingData'])) {
            // Update negativeRatingCount to escalate if necessary
            $negativeRatingCount = $this->session->get('negativeRatingCount');
            if (isset($message['isNegativeRating']) && $message['isNegativeRating']) {
                $negativeRatingCount += 1;
            } else {
                $negativeRatingCount = 0;
            }
            $this->session->set('negativeRatingCount', $negativeRatingCount);

            // Handle a rating that should ask for a comment
            if ($message['askRatingComment']) {
                // Save the rating data to session to use later, when user sends his comment
                $this->session->set('askingRatingComment', $message['ratingData']);
            }

            // Return rating data to log the rating
            return $message['ratingData'];
        } elseif ($this->session->has('askingRatingComment') && $this->session->get('askingRatingComment') != false) {
            // Send the rating with comment
            $ratingData = $this->session->get('askingRatingComment');
            $ratingData['data']['comment'] = $message['message'];

            // Forget we're asking for a rating comment
            $this->session->set('askingRatingComment', false);
            return $ratingData;
        }
        return $message;
    }

    /**
     * 	Display content rating message
     */
    protected function displayContentRatings($rateCode)
    {
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
        $this->externalClient->sendMessage($ratingMessage);
    }

    /**
     * 	Log an event to the bot
     */
    protected function sendEventToBot($event)
    {
        $bot_tracking_events = ['rate', 'click'];
        if (!in_array($event['type'], $bot_tracking_events)) {
            die();
        }

        $response = $this->botClient->trackEvent($event);
        switch ($event['type']) {
            case 'rate':
                $askingRatingComment    = $this->session->has('askingRatingComment') && $this->session->get('askingRatingComment') != false;
                $willEscalate           = $this->shouldEscalateFromNegativeRating() && $this->checkAgents();
                if ($askingRatingComment && !$willEscalate) {
                    // Ask for a comment on a content-rating
                    return $this->buildTextMessage($this->lang->translate('ask_rating_comment'));
                } else {
                    // Forget we were asking for a rating comment
                    $this->session->set('askingRatingComment', false);
                    // Send 'Thanks' message after rating
                    $textMessage = $this->lang->translate('thanks');
                    if (isset($event['data']['value'])) {
                        if ($event['data']['value'] == 1 && $this->lang->translate('rating_positive') !== 'rating_positive') {
                            $textMessage = $this->lang->translate('rating_positive');
                        } else if ($event['data']['value'] == 2 && $this->lang->translate('rating_negative') !== 'rating_negative') {
                            $textMessage = $this->lang->translate('rating_negative');
                        }
                    }
                    return $this->buildTextMessage($textMessage);
                }

                break;
        }
    }

    /**
     *	Detect some text 'commands' and perform the attached actions
     *	@param $message string
     */
    protected function handleCommands($message)
    {
        if (isset($message['message'])) {
            switch ($message['message']) {
                case 'clear_cached_appdata':
                    $removed = @unlink($this->botClient->appDataCacheFile);
                    $this->sendMessagesToExternal($this->buildTextMessage('Clear cached AppData response: "' . $removed . '"'));
                    die();
                    break;

                case 'clear_user_session':
                    $this->session->clear();
                    $this->sendMessagesToExternal($this->buildTextMessage('User session cleared.'));
                    die();
                    break;

                case 'show_user_id':
                    $this->sendMessagesToExternal($this->buildTextMessage($this->externalClient->getExternalId()));
                    die();
                    break;
            }
        }
    }

    /**
     *	Displays a Federated Bot answer and logs the click
     */
    protected function displayFederatedBotAnswer($answer)
    {
        // Send the Federated Bot message to the external service
        $this->sendMessagesToExternal($answer);

        // Log the content click
        if (
            isset($answer->parameters) &&
            isset($answer->parameters->contents) &&
            isset($answer->parameters->contents->trackingCode) &&
            isset($answer->parameters->contents->trackingCode->clickCode)
        ) {
            $clickData = [
                "type" => "click",
                "data" => [
                    "code" => $answer->parameters->contents->trackingCode->clickCode
                ]
            ];
            $this->sendEventToBot($clickData);
        }
    }

    /**
     *	Return a 200 OK response before continuing with the script execution
     */
    protected function returnOkResponse()
    {
        ob_start();
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        ob_end_flush();
        ob_flush();
        flush();
    }

    /**
     * Function to track CHAT/CONTACT events
     * @param string $type Contact type: "CHAT_ATTENDED", "CHAT_UNATTENDED", "CHAT_NO_AGENTS"
     */
    public function trackContactEvent($type, $chatId = null)
    {
        $data = [
            "type" => $type,
            "data" => [
                "value" => "true"
            ]
        ];
        if (!is_null($chatId)) {
            $chatConfig = $this->conf->get('chat.chat');
            $region = isset($chatConfig['regionServer']) ? $chatConfig['regionServer'] : 'us';
            $data["data"]["value"] = [
                "chatId" => $chatId,
                "appId" => $chatConfig['appId'],
                "region" => $region
            ];
        }

        $this->botClient->trackEvent($data);
    }


    /**
     * Get the configured chatbot source
     * @return string|null configured source or Null if no source defined 
     */
    public function getSource()
    {
        $conf = $this->conf->get('conversation');
        return isset($conf['source']) && $conf['source'] !== '' ? $conf['source'] : null;
    }

    /**
     * Send the data for start escalation
     */
    public function sendEscalationStart()
    {
        if ($this->checkServiceHours()) {
            if (!method_exists($this->externalClient, "setFullName")) {
                $this->escalateToAgent();
            } else {
                if ($this->checkAgents()) {
                    $this->setVariableValue("agents_available", "true");
                } else {
                    $this->setVariableValue("agents_available", "false");
                    $this->session->delete('escalationForm');
                }
                $message = ["directCall" => "escalationStart"];
                $botResponse = $this->sendMessageToBot($message);
                $this->sendMessagesToExternal($botResponse);
            }
        } else {
            // throw out of time message
            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('out_of_time')));
        }
    }

    /**
     * Check if in the $botResponse exists the "escalateToAgent" callback
     * @param object $botResponse
     * @return bool
     */
    public function checkEscalationForm($botResponse)
    {
        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }
        // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            $this->updateNoResultsCount($msg);
            if (isset($msg->actions[0]->parameters->callback) && $msg->actions[0]->parameters->callback == "escalateToAgent") {
                $data = $msg->actions[0]->parameters->data;
                $this->session->set('escalationForm', $data);
                $this->session->set('escalationType', static::ESCALATION_DIRECT);
                $this->session->set('escalationV2', true);
                return true;
            }
        }
        return false;
    }

    /** 
     * Escalate to an agent if the escalation form has been done
     * @return void
     */
    public function escalateIfFormHasBeenDone()
    {
        if ($this->session->get('escalationV2', false)) {
            $escalationFormData = $this->session->get('escalationForm', false);
            if ($escalationFormData) {
                if ($this->session->get('escalationType') == static::ESCALATION_OFFER && !$this->session->get('escalationOfferYes', false)) {
                    return false;
                }
                if (method_exists($this->externalClient, "setFullName") && method_exists($this->externalClient, "setEmail") && method_exists($this->externalClient, "setExtraInfo")) {
                    $this->externalClient->setFullName(trim($escalationFormData->FIRST_NAME . ' ' . $escalationFormData->LAST_NAME));
                    $this->externalClient->setEmail($escalationFormData->EMAIL_ADDRESS);
                    unset($escalationFormData->EMAIL_ADDRESS);
                    unset($escalationFormData->FIRST_NAME);
                    unset($escalationFormData->LAST_NAME);
                    $this->externalClient->setExtraInfo((array) $escalationFormData);
                }
                $this->session->delete('escalationOfferYes');
                $this->escalateToAgent();
                die;
            }
        }
    }

    /** 
     * Set a value of a variable
     * @param string $varName
     * @param string $varValue
     */
    public function setVariableValue($varName, $varValue)
    {
        $variable = [
            "name" => $varName,
            "value" => $varValue
        ];
        $botVariableResponse = $this->botClient->setVariable($variable);

        if (isset($botVariableResponse->success)) {
            return $botVariableResponse->success;
        }
        return false;
    }

    /**
     * Get the history of the current conversation
     */
    protected function chatbotHistory()
    {
        $history = [];
        $historyTmp = $this->botClient->getChatHistory();
        if (is_array($historyTmp) && count($historyTmp) > 0) {
            foreach ($historyTmp as $val) {
                if (isset($val->message) && trim($val->message) !== "") {
                    $history[] = [
                        'sender' => $val->user === 'bot' ? 'assistant' : $val->user,
                        'message' => $val->message,
                        'created' => strtotime($val->datetime)
                    ];
                }
            }
        }
        return $history;
    }

    /**
     * Check the Agents timetable
     *
     * @return boolean
     */
    public function checkServiceHours()
    {
        if (!$this->conf->get('chat.chat.workTimeTableActive')) return true;
        date_default_timezone_set($this->conf->get('chat.chat.timezoneWorkingHours'));
        $openingHours = OpeningHours::create($this->getServiceTimetable());
        return $openingHours->isOpen();
    }

    /**
     * Get the agents timetable from API or config file
     *
     * @return Array
     */
    public function getServiceTimetable()
    {
        $timetable = [];
        // make a request to API to get timetable from CM
        $timetable = $this->getWorkTimeTable();

        // Use default settings if extra info data has not been set
        if (!count($timetable)) {
            $timetable = $this->conf->get('chat.chat.timetable');
        }
        return $timetable;
    }

    /**
     * Get time table from Messenger API
     *
     * @return Array
     */
    public function getWorkTimeTable()
    {
        $timetable = [];
        $response = !is_null($this->messengerClient) ? $this->messengerClient->getWorkTimeTable() : null;
        if ($response && isset($response['weeks'])) {
            foreach ($response['weeks'] as $week) {
                if ($week['queueId'] == $this->conf->get('chat.chat.roomId')) {
                    $timetable = $this->transformTimeTableHours($week);
                }
            }
        }
        if ($response && isset($response['holidays'])) {
            foreach ($response['holidays'] as $holiday) {
                if ($holiday['queueId'] == $this->conf->get('chat.chat.roomId')) {
                    $timetable['exceptions'] = [];
                    foreach ($holiday['days'] as $value) {
                        $timetable['exceptions'] += [date("Y-m-d", strtotime($value)) => []];
                    }
                }
            }
        }
        return $timetable;
    }

    /**
     * Transform the timetable array format
     *
     * @return Array
     */
    protected function transformTimeTableHours($week)
    {
        $weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $timetable = [
            'monday'     => [],
            'tuesday'    => [],
            'wednesday'  => [],
            'thursday'   => [],
            'friday'     => [],
            'saturday'   => [],
            'sunday'     => [],
        ];
        foreach ($weekDays as $keyDay) {
            if (count($week[$keyDay])) {
                foreach ($week[$keyDay] as $day) {
                    array_push($timetable[strtolower($keyDay)], $day['from'] . "-" . $day['to']);
                }
            }
        }
        return $timetable;
    }

    /**
     * Handle the data for the survey
     */
    protected function handleSurvey($userAnswer)
    {
        if (method_exists($this->digester, "nextSurveyQuestion")) {
            $message = isset($userAnswer[0]['message']) ? $userAnswer[0]['message'] : '';
            $surveyElements = $this->session->get('surveyElements');
            if (!is_null($surveyElements) && $this->session->get('surveyLaunch', false)) {
                $nextQuestion = $this->digester->nextSurveyQuestion($surveyElements, $message);
                foreach ($nextQuestion as $question) {
                    if ($question === '__MAKE_SUBMIT__' || $question === '__END_SURVEY__') {
                        $surveyElements = $this->session->get('surveyElements');
                        $this->deleteSurveySession();
                        if ($question === '__MAKE_SUBMIT__') {
                            $this->sendMessagesToExternal($this->buildTextMessage($surveyElements['thanksMessage']));
                            $this->sendSurvey($surveyElements);
                        }
                        break;
                    }
                    if ($question === '__SURVEY_NOT_ACCEPTED__') {
                        $this->deleteSurveySession();
                    } else {
                        if (is_array($question)) $this->externalClient->sendMessage($question);
                        else $this->sendMessagesToExternal($this->buildTextMessage($question));
                    }
                }
                die;
            }
        }
    }

    /**
     * Delete the survey related sessions
     */
    protected function deleteSurveySession()
    {
        $this->session->delete('surveyLaunch');
        $this->session->delete('surveyConfirm');
        $this->session->delete('surveyElements');
        $this->session->delete('surveyExpectedValues');
        $this->session->delete('surveyExpectedLabels');
        $this->session->delete('surveyWrongAnswers');
        $this->session->delete('surveyPendingElement');
        $this->session->delete('surveyAskForContinue');
    }

    /**
     * Send the survey
     */
    protected function sendSurvey($surveyElements)
    {
        $config = $this->conf->get('chat.chat');
        $surveyId = isset($config['survey']['id']) ? $config['survey']['id'] : 0;
        if ($surveyId > 0) {
            $token = $surveyElements['token'];
            $answers = [];
            foreach ($surveyElements['questions'] as $question) {
                if (isset($question['response']) && $question['response'] !== '') {
                    $answers[$question['id']] = $question['response'];
                }
            }
            $response = $this->messengerClient->surveySubmit($answers, $token, $surveyId);
        }
    }

    /**
     * Check if there is a pending confirmation for start a survey
     */
    public function validateIfAskForSurvey()
    {
        if (!is_null($this->session->get('surveyElements')) && method_exists($this->digester, "askForSurvey")) {
            $this->externalClient->setSenderFromId($this->session->get('externalId'));
            $this->processSurveyData($this->session->get('surveyElements'));
            $this->externalClient->sendMessage($this->digester->askForSurvey());
        } else {
            $this->session->delete('surveyConfirm');
        }
        die;
    }

    /**
     * Get the survey data and store in a session
     */
    protected function processSurveyData($surveyData)
    {
        $surveyElements = [];
        if (isset($surveyData->response)) {
            $surveyData->data = $surveyData->response;
        }
        if (!isset($surveyData->error) && isset($surveyData->data->token) && isset($surveyData->data->pages)) {
            $surveyElements = [
                'token' => '',
                'thanksMessage' => '',
                'navigatePage' => [],
                'surveyAccepted' => false,
                'questions' => []
            ];
            $surveyElements['token'] = $surveyData->data->token;
            $surveyElements['thanksMessage'] = $this->lang->translate('thanks');
            foreach ($surveyData->data->settings as $setting) {
                if ($setting->subtype === 'thankyoupage') {
                    $surveyElements['thanksMessage'] = $setting->value->message;
                } else if ($setting->subtype === 'navigatepage') {
                    $surveyElements['navigatePage'] = $setting->value;
                }
            }

            foreach ($surveyData->data->pages as $page) {
                foreach ($page->items as $item) {
                    // Ignore page header
                    if ($item->subtype === 'header') {
                        continue;
                    }
                    $surveyElements['questions'][] = [
                        'id' => $item->id,
                        'page' => $page->id,
                        'type' => $item->type,
                        'subtype' => $item->subtype,
                        'settings' => $item->settings,
                        'response' => '',
                        'answered' => false
                    ];
                }
            }
            $this->session->set('surveyElements', $surveyElements);
        } else {
            $this->session->delete('surveyElements');
        }
    }

    /**
     * Validate if user is in a waiting queue. If user type the command to exit the queue, then the chat is closed
     * If user is not in "waiting" but is in "active" then a session with the ID of the chat is created in true,
     * in order to prevent to check the status of the chat on every message from user
     */
    protected function validateIsInHyperchatQueue($digestedRequest)
    {
        $chat = $this->session->get('chatOnGoing');
        if ($chat && !$this->session->get('chatActive_' . $chat, false)) {
            $chatInfo = $this->chatClient->getChatInformation($chat);
            if (isset($chatInfo->inQueue) && isset($chatInfo->status) && $chatInfo->status == 'waiting' && $chatInfo->inQueue == 1) {
                if (!$this->exitQueueCommandExecuted($digestedRequest, $chatInfo)) {
                    $messageQueue = $this->lang->translate('on_waiting_queue');
                    if ($this->session->get('exitQueueCommand', '') !== '')
                        $messageQueue .= ' ' . $this->lang->translate('on_waiting_queue_exit', ['exitCommand' => $this->session->get('exitQueueCommand')]);
                    $this->externalClient->sendTextMessage($messageQueue);
                }
            } else if ($chatInfo->status == 'active') {
                $this->session->set('chatActive_' . $chat, true);
            }
        }
    }

    /**
     * Check if the exit queue command was executed
     * @param array $digestedRequest
     * @param object $chatInfo
     */
    protected function exitQueueCommandExecuted(array $digestedRequest, object $chatInfo)
    {
        if (
            isset($digestedRequest[0]['message']) &&
            $this->session->get('exitQueueCommand', '') !== '' &&
            strtolower($digestedRequest[0]['message']) == strtolower($this->session->get('exitQueueCommand'))
        ) {
            $user = $this->chatClient->getUserInformation($chatInfo->creator);
            $name = isset($user->name) ? $user->name : (method_exists($this->externalClient, "getFullName") ? $this->externalClient->getFullName() : '');
            $contact = isset($user->contact) ? $user->contact : (method_exists($this->externalClient, "getEmail") ? $this->externalClient->getEmail() : '');
            $externalId = isset($user->externalId) ? $user->externalId : $this->externalClient->getExternalId();
            $extraInfo = isset($user->extraInfo) ? (array) $user->extraInfo : (method_exists($this->externalClient, "getExtraInfo") ? $this->externalClient->getExtraInfo() : []);

            $chatData = [
                'roomId' => $this->conf->get('chat.chat.roomId'),
                'user' => [
                    'name' => $name,
                    'contact' => $contact,
                    'externalId' => $externalId,
                    'extraInfo' => $extraInfo
                ]
            ];
            $this->chatClient->closeChat($chatData);
            $this->externalClient->sendTextMessage($this->lang->translate('chat_closed'));
            $this->session->set('chatOnGoing', false);
            die;
        }
        return false;
    }

    /**
     * Check if bot response has a callback to create a ticket
     * @param object $botResponses
     * @return void
     */
    protected function checkCallbackTicketCreation(object $botResponses): void
    {
        foreach ($botResponses->answers as $botResponse) {
            if (!isset($botResponse->actions[0]->parameters->callback)) continue;
            if ($botResponse->actions[0]->parameters->callback !== "createTicket") continue;
            if (!isset($botResponse->actions[0]->parameters->data)) continue;

            if (is_null($this->messengerClient)) {
                $this->externalClient->sendTextMessage($this->lang->translate('ticket_error'));
                return;
            }
            $formData = $botResponse->actions[0]->parameters->data;
            $queue = $this->conf->has('chat.chat.queue') ? $this->conf->get('chat.chat.queue') : 1;
            $formData->QUEUE = $formData->QUEUE ?? $queue;
            $history = $this->chatbotHistory();

            $ticket = $this->messengerClient->createTicket($formData, $history, $this->conf->get('chat.chat.source'));
            if ($ticket !== "") {
                $this->externalClient->sendTextMessage($this->lang->translate('ticket_created') . $ticket);
                return;
            }
            $this->externalClient->sendTextMessage($this->lang->translate('ticket_error'));
            return;
        }
    }
}
