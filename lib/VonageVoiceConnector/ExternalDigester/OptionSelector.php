<?php

namespace Inbenta\VonageVoiceConnector\ExternalDigester;

use Inbenta\VonageVoiceConnector\Helpers\Helper;

class OptionSelector
{
    /**
     * Check if the response has options
     * @param string $userMessage
     * @param object $session
     * @param string $tagNo
     * @return array $output
     */
    public static function checkOptions(string $userMessage, object $session, string $tagNo)
    {
        $output = [['message' => $userMessage]];
        if ($session->has('options')) {

            $lastUserQuestion = $session->get('lastUserQuestion');
            $options = $session->get('options');
            $session->delete('options');
            $session->delete('lastUserQuestion');

            $selection = self::loopThroughOptions($options, $userMessage, $lastUserQuestion);

            $userMessageTmp = self::optionsNoSelection($selection, $options, $tagNo, $session, $lastUserQuestion);
            $userMessage = $userMessageTmp == "" ? $userMessage : $userMessageTmp;

            $output = self::optionsMakeOutput($selection, $userMessage);
        }
        return $output;
    }

    /**
     * Loop through message options and selects one if match with user message
     * @param array $options
     * @param string $userMessage
     * @param string $lastUserQuestion = null
     * @return array $selection;
     */
    protected static function loopThroughOptions(array $options, string $userMessage, string $lastUserQuestion = null): array
    {
        $selection = [
            "selectedOption" => false,
            "selectedOptionText" => "",
            "isListValues" => false,
            "isPolar" => false,
            "optionSelected" => false
        ];
        foreach ($options as $option) {
            if (isset($option->list_values)) {
                $selection["isListValues"] = true;
            } else if (isset($option->is_polar)) {
                $selection["isPolar"] = true;
            }
            if (Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($option->label)) {
                if ($selection["isListValues"]) {
                    $selection["selectedOptionText"] = $option->label;
                } else {
                    $selection["selectedOption"] = $option;
                    $lastUserQuestion = isset($option->title) && !$selection["isPolar"] ? $option->title : $lastUserQuestion;
                }
                $selection["optionSelected"] = true;
                break;
            }
        }
        return $selection;
    }

    /**
     * If the option exists but it does not have a selection enters here
     * @param array $selection
     * @param array $options
     * @param string $tagNo
     * @param object $session
     * @param string $lastUserQuestion = null
     * @return string
     */
    protected static function optionsNoSelection(array $selection, array $options, string $tagNo, object $session, string $lastUserQuestion = null): string
    {
        if (!$selection["optionSelected"]) {
            if ($selection["isListValues"]) { //Set again options for variable
                if ($session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                    $session->set('options', $options);
                    $session->set('lastUserQuestion', $lastUserQuestion);
                    $session->set('optionListValues', 1);
                } else {
                    $session->delete('options');
                    $session->delete('lastUserQuestion');
                    $session->delete('optionListValues');
                }
            } else if ($selection["isPolar"]) { //For polar, on wrong answer, goes for NO
                return $tagNo;
            }
        }
        return "";
    }

    /**
     * Creates the output from the option selected
     * @param array $selection
     * @param string $userMessage
     * @return array
     */
    protected static function optionsMakeOutput(array $selection, string $userMessage): array
    {
        if ($selection["selectedOption"]) {
            return [['option' => $selection["selectedOption"]->value]];
        } else if ($selection["selectedOptionText"] !== "") {
            return [['message' => $selection["selectedOptionText"]]];
        }
        return [['message' => $userMessage]];
    }
}
