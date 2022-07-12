<?php

namespace Inbenta\VonageVoiceConnector;

use \Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\VonageVoiceConnector\ExternalAPI\VonageVoiceAPIClient;
use Inbenta\VonageVoiceConnector\ExternalDigester\VonageVoiceDigester;
use Inbenta\VonageVoiceConnector\Helpers\Helper;
use \Firebase\JWT\JWT;
use Klein\Request;
use Klein\Response;


class VonageVoiceConnector extends ChatbotConnector
{
    private $messages = "";
    private $request;

    public function __construct(string $appPath, Request $request)
    {
        // Initialize and configure specific components for VonageVoice
        try {

            parent::__construct($appPath);
            $this->request = $request;

            //Validate security header
            $this->securityCheck();

            // Initialize base components
            $externalId = $this->getExternalIdFromRequest();
            $this->session = new SessionManager($externalId);

            $conversationConf = array(
                'configuration' => $this->conf->get('conversation.default'),
                'userType'      => $this->conf->get('conversation.user_type'),
                'environment'   => $this->environment,
                'source'        => $this->conf->get('conversation.source')
            );

            //
            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            $this->getVariablesTypes();

            //
            $externalClient = new VonageVoiceAPIClient(
                $this->conf->get('vonage')
            ); // Instance VonageVoice client

            // Instance VonageVoice digester
            $externalDigester = new VonageVoiceDigester(
                $this->lang,
                $this->conf->get('conversation'),
                $this->session
            );

            $this->initComponents($externalClient, null, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Get the external id from request
     *
     * @return String 
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a VonageVoice message request
        $externalId = VonageVoiceAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            session_write_close();
            throw new Exception('Invalid request!');
        }
        return $externalId;
    }

    /**
     * Check if the request matches the security needs
     * @throws Exception
     */
    protected function securityCheck()
    {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Invalid request!');
        }
        $conf = $this->conf->get('vonage');
        $keys = $this->getKeysFromToken($headers['Authorization']);
        if (!($this->validKeys($keys) && $this->validConfig($conf) && $this->validateKeysConfig($keys, $conf))) {
            throw new Exception('Invalid request!');
        }
    }

    /**
     * Gets keys from token
     * @param string $token
     * @return object
     */
    protected function getKeysFromToken(string $token): object
    {
        $elements = explode('.', $token);
        $payloadb64 = isset($elements[1]) ? $elements[1] : '';

        if ($payloadb64 == '') return (object) [];

        $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($payloadb64));
        return $payload;
    }

    /**
     * Check if keys are valid
     * @param object $keys
     * @return bool
     */
    protected function validKeys(object $keys): bool
    {
        return isset($keys->api_key)
            && isset($keys->application_id)
            && $keys->api_key !== ''
            && $keys->application_id !== '';
    }

    /**
     * Check if config has values
     * @param array $conf
     * @return bool
     */
    protected function validConfig(array $conf): bool
    {
        return isset($conf['appId'])
            && isset($conf['apiKey'])
            && $conf['appId'] !== ''
            && $conf['apiKey'] !== '';
    }

    /**
     * Check if values from config are the same from the request
     * @param object $keys
     * @param array $conf
     * @return bool
     */
    protected function validateKeysConfig(object $keys, array $conf): bool
    {
        return $keys->api_key === $conf['apiKey']
            && $keys->application_id === $conf['appId'];
    }

    /**
     * Check if request has the needed data
     * @throws Exception
     */
    protected function validateRequest()
    {
        if (!method_exists($this->request, 'server'))
            throw new Exception('Request error');
        if ($this->request->server()['REQUEST_METHOD'] === 'POST' && $this->request->body() === '')
            throw new Exception('POST request error');
        if (isset($this->request->params()['conversation_uuid']) && $this->request->params()['conversation_uuid'] === '')
            throw new Exception('GET request error');
        if (!isset($this->request->server()['HTTP_X_FORWARDED_PROTO']) || !isset($this->request->server()['HTTP_HOST']))
            throw new Exception('Server error');
    }

    /**
     * Get the variables types defined in Backstage (METADATA_[variableName]_TYPE)
     * this will help to understand the context from variables, example: numbers
     */
    protected function getVariablesTypes()
    {
        if (!$this->session->get('variablesTypes', false)) {
            $variables = [];
            $variablesChatbot = $this->botClient->getVariables();

            foreach ($variablesChatbot as $key => $variable) {
                if (strpos($key, "metadata_") === false) continue;
                if (strpos($key, "_type") === false) continue;
                $key = str_replace(["metadata_", "_type"], "", $key);
                $variables[$key] = $variable->value;
            }
            $this->session->set('variablesTypes', $variables);
        }
    }

    /**
     * Handle message from user
     * @param Response $response
     * @return Response $response->json
     */
    public function handleUserMessage(Response $response)
    {
        try {
            $this->validateRequest();
            $this->externalClient->setUrlConnector($this->request);

            $message = $this->handleRequest();
            $message = trim($message) === '' ? $this->lang->translate('on_empty_message') : $message;
            $variableType = $this->digester->getVariableType();
            $vonageResponse = $this->externalClient->vonageStructure($message, $variableType);

            return $response->json($vonageResponse);
        } catch (Exception $e) {
            return $response->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Overwritten
     */
    public function handleRequest()
    {
        try {
            // Translate the request into a ChatbotAPI request
            $externalRequest = $this->digester->digestToApi($this->request);

            // Check if it's needed to perform any action other than a standard user-bot interaction
            $nonBotResponse = $this->handleNonBotActions($externalRequest);
            if ($nonBotResponse !== '') {
                return $nonBotResponse;
            }
            // Handle standard bot actions
            $this->handleBotActions($externalRequest);
            // Send all messages
            return $this->messages;
        } catch (Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }

    /**
     * Overwritten
     */
    protected function handleNonBotActions($digestedRequest)
    {
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            return $this->handleEscalation($digestedRequest);
        }
        return '';
    }

    /**
     * Overwritten
     */
    protected function sendMessagesToExternal($messages)
    {
        // Digest the bot response into the external service format
        $digestedBotResponse = $this->digester->digestFromApi($messages, $this->session->get('lastUserQuestion'));
        $this->mergeMessages($digestedBotResponse);
    }

    /**
     * Makes the merge when messages are array
     * @param array $messages
     */
    protected function mergeMessages(array $messages)
    {
        $this->messages = $this->digester->setSingleMessage($messages, $this->messages);
    }

    /**
     * Overwritten
     */
    protected function handleEscalation($userAnswer = null)
    {
        $escalationMessage = '';
        if (!$this->session->get('askingForEscalation', false)) {
            $escalationMessage = $this->messageOnNoAskingForEscalation();
        } else {
            $escalationMessage = $this->messageOnAskingForEscalation($userAnswer);
        }
        $this->mergeMessages([$escalationMessage]);
        return $escalationMessage;
    }

    /**
     * Validate if not empty the phone for make escalation
     * @return bool
     */
    protected function validatePhoneTransferExists(): bool
    {
        return $this->conf->get('chat.chat.phoneNumber') !== '';
    }

    /**
     * Return the message when there is no an answer asking for escalation
     * @return string
     */
    protected function messageOnNoAskingForEscalation(): string
    {
        if ($this->validatePhoneTransferExists()) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                return $this->escalateToAgent();
            } else {
                if ($this->checkServiceHours()) {
                    // Ask the user if wants to escalate
                    $this->session->set('askingForEscalation', true);
                    return $this->digester->buildEscalationMessage();
                } else {
                    return $this->lang->translate('out_of_time');
                }
            }
        }
        return $this->digester->buildNoEscalationConfigMessage();
    }

    /**
     * Check user response for escalation ("yes" or "no") and show the response
     * @param array $userAnswer
     * @return string
     */
    protected function messageOnAskingForEscalation(array $userAnswer): string
    {
        // Handle user response to an escalation question
        $this->session->set('askingForEscalation', false);
        // Reset escalation counters
        $this->session->set('noResultsCount', 0);
        $this->session->set('negativeRatingCount', 0);

        //Confirm the escalation
        $yesTag = $this->lang->translate('yes');
        if (
            count($userAnswer) && isset($userAnswer[0]['message']) &&
            Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($yesTag)
        ) {
            return $this->escalateToAgent();
        }
        //Any other response that is different to "yes" (or similar) it's going to be considered as "no"
        $message = ["option" => strtolower($this->lang->translate('no'))];
        $botResponse = $this->sendMessageToBot($message);
        $this->sendMessagesToExternal($botResponse);
        return $this->messages;
    }

    /**
     * Overwritten
     * Make the structure for VonageVoice transfer
     */
    protected function escalateToAgent()
    {
        $this->session->delete('escalationType');
        $this->session->delete('escalationV2');
        if ($this->checkServiceHours()) {
            $this->trackContactEvent("CHAT_ATTENDED");
            $this->externalClient->setPhoneNumbers($this->request, $this->conf->get('chat.chat.phoneNumber'));

            return $this->digester->buildEscalatedMessage();
        }
        $this->trackContactEvent("CHAT_NO_AGENTS");
        // throw out of time message
        return $this->lang->translate('out_of_time');
    }
}
