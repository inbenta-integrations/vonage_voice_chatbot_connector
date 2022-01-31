<?php

namespace Inbenta\VonageVoiceConnector\ExternalAPI;

use \Exception;
use Vonage\Voice\Webhook;
use Vonage\Voice\NCCO\NCCO;
use Vonage\Voice\NCCO\Action\Talk;
use Vonage\Voice\NCCO\Action\Input;
use Vonage\Voice\Endpoint\Phone;
use Vonage\Voice\NCCO\Action\Connect;
use Klein\Request;


class VonageVoiceAPIClient
{
    protected $url = "";
    protected $voiceLang = "en-US";
    protected $escalationPhoneNumber = "";
    protected $vonagePhoneNumber = "";

    public function __construct(array $vonageConfig)
    {
        $this->voiceLang = $vonageConfig['lang'] !== '' ? $vonageConfig['lang'] : $this->voiceLang;
    }

    /**
     * Create the external id
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (!$request) {
            $request = (object) $_GET;
        }
        return isset($request->conversation_uuid) ? 'vv-' . $request->conversation_uuid : null;
    }

    /**
     * Set the url connector for responses
     * @param Request $request
     */
    public function setUrlConnector(Request $request)
    {
        $this->url = $request->server()['HTTP_X_FORWARDED_PROTO'] . '://';
        $this->url .= $request->server()['HTTP_HOST'];
        $this->url .= '/asr';
    }

    /**
     * Set the phone numbers of Vonage and escalation
     * @param Request $request
     * @param string $escalationPhoneNumber
     */
    public function setPhoneNumbers(Request $request, string $escalationPhoneNumber)
    {
        $params = method_exists($request, 'body') ? json_decode($request->body(), true) : [];
        $this->vonagePhoneNumber = isset($params['to']) ? $params['to'] : '';
        $this->escalationPhoneNumber = $escalationPhoneNumber;
    }

    /**
     * Overwritten, not necessary with Vonage Voice
     */
    public function showBotTyping($show = true)
    {
        return true;
    }

    /**
     * Creates the structure for Vonage response
     * @param string $message
     * @return array
     */
    public function vonageStructure(string $message): array
    {
        $ncco = new NCCO();
        $ncco->addAction(Talk::factory($message, ['language' => $this->voiceLang]));

        if ($this->escalationPhoneNumber !== '' && $this->vonagePhoneNumber !== '') {
            $escalationAction = $this->escalationVonageAction();
            $ncco->addAction($escalationAction);
        } else {
            $inputAction = new Input();
            $inputAction
                ->setSpeechEndOnSilence(true)
                ->setSpeechLanguage($this->voiceLang)
                ->setEventWebhook(new Webhook($this->url));
            $ncco->addAction($inputAction);
        }

        return $ncco->toArray();
    }

    /**
     * Return the "action" for transfer
     */
    public function escalationVonageAction()
    {
        $numberToConnect = new Phone($this->escalationPhoneNumber);
        $action = new Connect($numberToConnect);
        $action->setFrom($this->vonagePhoneNumber);
        return $action;
    }
}
