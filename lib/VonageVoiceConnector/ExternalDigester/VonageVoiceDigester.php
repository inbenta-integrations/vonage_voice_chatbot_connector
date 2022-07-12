<?php

namespace Inbenta\VonageVoiceConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\VonageVoiceConnector\Helpers\Helper;
use Inbenta\VonageVoiceConnector\ExternalDigester\OptionSelector;
use Vonage\Voice\Webhook\Factory;

class VonageVoiceDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;
    protected $voiceAnswerAttribute;

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'vonageVoice';
        $this->conf = $conf;
        $this->session = $session;
        $this->voiceAnswerAttribute = 'Answer_voice';
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     *	Checks if a request belongs to the digester channel
     */
    public static function checkRequest($request)
    {
        $request = json_decode($request);

        $isMessaging = isset($request->activities[0]);
        if ($isMessaging && count($request->activities)) {
            return true;
        }
        return false;
    }

    /**
     *	Formats a channel request into an Inbenta Chatbot API request
     */
    public function digestToApi($request)
    {
        if ($request->server()['REQUEST_METHOD'] === 'GET') { //First request is GET, show welcome message
            return [['message' => '', 'directCall' => 'sys-welcome']];
        }
        $params = json_decode($request->body(), true);
        $input = Factory::createFromArray($params);
        if ($input->getSpeech() && isset($input->getSpeech()['results'][0]['text'])) {
            $userMessage = $this->checkIfTypeExpected($input->getSpeech()['results'][0]['text']);

            return OptionSelector::checkOptions($userMessage, $this->session, $this->langManager->translate('no'));
        }
        return [];
    }

    /**
     * Check if the reponse correspond to the type defined in previous response
     * @param string $userMessage
     * @return string
     */
    protected function checkIfTypeExpected(string $userMessage): string
    {
        if ($this->session->get('variableToExpect', '') === '') return $userMessage;

        $variableType = $this->getVariableType();
        if ($variableType === 'NUMBER') {
            $userMessageTmp = str_replace(" ", "", trim($userMessage));
            if (is_numeric($userMessageTmp)) {
                $this->session->delete('variableToExpect');
                $this->session->delete('variableDataType');
                return $userMessageTmp;
            }
            $this->processSessionOnVarError();
        } else if ($variableType === 'EMAIL') {
            $userMessageTmp = $userMessage;
            if (strpos($userMessageTmp, " at ") > 0) {
                $userMessageTmp = str_replace(" at ", "@", $userMessageTmp);
            }
            if (strpos($userMessageTmp, " dot ") > 0) {
                $userMessageTmp = str_replace(" dot ", ".", $userMessageTmp);
            }
            $userMessageTmp = str_replace(" ", "", trim(strtolower($userMessageTmp)));
            if (filter_var($userMessageTmp, FILTER_VALIDATE_EMAIL)) {
                $this->session->delete('variableToExpect');
                $this->session->delete('variableDataType');
                return $userMessageTmp;
            }
            $this->processSessionOnVarError();
        } else {
            $this->session->delete('variableToExpect');
            $this->session->delete('variableDataType');
        }
        return $userMessage;
    }

    /**
     * Process session vars for error on backstage variable error
     */
    protected function processSessionOnVarError()
    {
        $countErrors = $this->session->get('variableToExpectError', 0) + 1;
        if ($countErrors === 1) $this->session->set('variableToExpectError', 1);
        if ($countErrors >= $this->conf['default']['forms']['errorRetries']) {
            $this->session->delete('variableToExpect');
            $this->session->delete('variableToExpectError');
            $this->session->delete('variableDataType');
        }
    }

    /**
     * Get the variable type for the expected voice value
     * @return string $variableType
     */
    public function getVariableType(): string
    {
        $variableType = '';
        $variablesTypes = $this->session->get('variablesTypes', []);
        if (count($variablesTypes) > 0) {
            $variableToExpect = $this->session->get('variableToExpect', '_');
            $variableType = isset($variablesTypes[$variableToExpect]) ? $variablesTypes[$variableToExpect] : '';
        }
        if ($variableType === '' && $this->session->get('variableDataType', '') !== '') {
            $variableType = strtoupper($this->session->get('variableDataType'));
        }
        return $variableType;
    }

    /**
     *	Formats an Inbenta Chatbot API response into a channel request
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = array('answers' => $request);
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

            if ($digestedMessage === "__EMPTY__") continue;

            //Check if there are more than one responses from one incoming message
            if (isset($digestedMessage['multiple_output'])) {
                foreach ($digestedMessage['multiple_output'] as $message) {
                    $output[] = $message;
                }
            } else {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     *	Classifies the API message into one of the defined $apiMessageTypes
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $messageResponse = $this->getTextMessage($message);
        if (trim($messageResponse) === '') {
            $messageResponse = '__EMPTY__';
        }

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "") {
            $sidebubble = Helper::cleanMessage($message->attributes->SIDEBUBBLE_TEXT);
            if ($sidebubble !== '') {
                $messageResponse = ['multiple_output' => [
                    $messageResponse,
                    $sidebubble
                ]];
            }
        }

        $actionField = $this->handleMessageWithActionField($message, $lastUserQuestion);
        if (count($actionField) > 0) {
            if (!isset($messageResponse['multiple_output'])) {
                $messageResponse = ['multiple_output' => [$messageResponse]];
            }
            foreach ($actionField as $element) {
                $messageResponse['multiple_output'] = array_merge($messageResponse['multiple_output'], $element);
            }
        }

        return $messageResponse;
    }

    /**
     * Get the text message, first look for "Answer_voice" attribute
     * if not exists or is empty, then look for the "normal"response
     * @param object $message = null
     * @return string
     */
    protected function getTextMessage(object $message = null): string
    {
        $voiceAnswerAttribute = $this->voiceAnswerAttribute;
        if (isset($message->attributes->$voiceAnswerAttribute) && trim($message->attributes->$voiceAnswerAttribute) !== '') {
            return trim($message->attributes->$voiceAnswerAttribute);
        }
        return isset($message->messageList[0]) ? Helper::cleanMessage($message->messageList[0]) : '';
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param string $lastUserQuestion
     * @return array
     */
    protected function handleMessageWithActionField(object $message, string $lastUserQuestion = null): array
    {
        if (!isset($message->actionField)) return [];
        if (empty($message->actionField)) return [];

        $this->validateIfExpectingVariable($message->actionField);
        if ($message->actionField->fieldType === 'default') return [];
        if ($message->actionField->fieldType === 'list') {
            return $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
        }
        return [];
    }

    /**
     * Validate if action field has an expecting value
     * this will help to detect the context of the requested variable
     * @param object $actionField
     */
    protected function validateIfExpectingVariable(object $actionField)
    {
        if (!isset($actionField->variableName)) return;
        if (!isset($actionField->dataType)) return;
        if ($actionField->variableName === '') return;
        if ($actionField->dataType === '') return;
        $this->session->set('variableToExpect', $actionField->variableName);
        $this->session->set('variableDataType', $actionField->dataType);
    }

    /**
     * Set the options for message with list values
     * @param object $listValues
     * @param string $lastUserQuestion = null
     * @return array $output
     */
    protected function handleMessageWithListValues(object $listValues, string $lastUserQuestion = null): array
    {
        $output = [];
        $output['multiple_output'] = [];
        $options = $listValues->values;
        foreach ($options as $index => &$option) {
            $option->list_values = true;
            $option->label = $option->option;
            $output['multiple_output'][] = $option->label;
            if ($index == 5) break;
        }

        if (count($output['multiple_output']) > 0) {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $output;
    }

    /**
     * Response for multiple choice question
     * @param object $message
     * @param string $lastUserQuestion = null
     * @param bool $isPolar = false
     * @return array $output
     */
    protected function digestFromApiMultipleChoiceQuestion(object $message, string $lastUserQuestion = null, $isPolar = false): array
    {
        $output = [];
        $text = isset($message->messageList[0]) ? Helper::cleanMessage($message->messageList[0]) : '';
        $output['multiple_output'] = [$text];

        $options = $message->options;
        foreach ($options as &$option) {
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } elseif ($isPolar) {
                $option->is_polar = true;
            }
            $output['multiple_output'][] = Helper::cleanMessage($option->label);
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);
        return $output;
    }

    /**
     * Response for polar question
     * @param object $message
     * @param string $lastUserQuestion = null
     * @return array
     */
    protected function digestFromApiPolarQuestion(object $message, string $lastUserQuestion = null): array
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }

    /**
     * Response for extended content answer
     * @param object $message
     * @param string $lastUserQuestion = null
     * @return string
     */
    protected function digestFromApiExtendedContentsAnswer(object $message, string $lastUserQuestion = null)
    {
        return isset($message->messageList[0]) ? Helper::cleanMessage($message->messageList[0]) : '';
    }

    /********************** MISC **********************/
    public function buildEscalationMessage()
    {
        return $this->langManager->translate('ask_to_escalate');
    }

    public function buildEscalatedMessage()
    {
        return $this->langManager->translate('creating_chat');
    }

    public function buildNoEscalationConfigMessage()
    {
        return $this->langManager->translate('no_escalation_supported');
    }

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        return [];
    }
    public function buildUrlButtonMessage($message, $urlButton)
    {
        return [];
    }
    public function handleMessageWithImages($messages)
    {
        return [];
    }

    /**
     * Iterate through messages response and creates a single message
     * @param array $messages
     * @param string $singleMessage
     * @return string $singleMessage
     */
    public function setSingleMessage(array $messages, string $singleMessage): string
    {
        $newMessage = '';
        foreach ($messages as $text) {
            $text = trim($text);
            if ($text !== '') {
                if ($this->validateLastCharacter($text)) $newMessage .= $text . '. ';
                else $newMessage .= $text . ' ';
            }
        }
        if (substr($newMessage, -2) === '. ') $newMessage = substr($newMessage, 0, -2);

        $singleMessage .= $this->validateLastCharacter($singleMessage) ? '.' : '';
        $singleMessage = trim($singleMessage . ' ' . $newMessage);

        return $singleMessage;
    }

    /**
     * Check if text is not empty or it doesn't have "." or ":" at the end of the string
     * @param string $text
     * @return bool
     */
    protected function validateLastCharacter(string $text): bool
    {
        return $text !== '' && substr($text, -1) !== '.' && substr($text, -1) !== ':';
    }
}
