<?php
namespace Inbenta\ChatbotConnector\ExternalDigester\Channels;

abstract class DigesterInterface
{
	protected $apiMessageTypes = array(
		'answer',
		'polarQuestion',
		'multipleChoiceQuestion',
		'extendedContentsAnswer'
	);

	/**
	 *	Checks if a request belongs to the digester channel
	 */
	abstract public static function checkRequest($request);

	/**
	 *	Formats a channel request into an standard request
	 */
	abstract public function digestToApi($request);

	/**
	 *	Formats an API request into an external channel request
	 */
	abstract public function digestFromApi($request, $lastUserQuestion);

	/**
	 *	Returns the channel name
	 */
	abstract public function getChannel();

	/**
	 *	Formats a message to let the user rate the displayed content
	 */
	abstract public function buildContentRatingsMessage($ratingOptions, $rateCode);

	/**
	 *	Split a text message into multiple bubbles if an HTML image tag is found (<img src="xx">)
	 */
	abstract protected function handleMessageWithImages($message);

	/**
	 *	Send a message with a button to open a URL
	 */
	abstract protected function buildUrlButtonMessage($message, $urlButton);

	/**
	 *	Ask the user if wants to escalate with an agent
	 */
	abstract public function buildEscalationMessage();
}
