### STRUCTURE
The lang folder should contain a file for each language that the bot will speak. The name of the file should match the value specified to the bot in `/conf/current-conf-folder/conversation.php` at `default/lang` parameter. The values accepted are described in Chatbot API Routes `/conversation`

Here is an example of a lang file:
```php
    return array(
    	'ask_to_escalate' => 'Do you want to start a chat with a human agent? Say Yes or No',
    	'creating_chat' => 'I will try to connect you with an agent. Please wait.',
        'on_empty_message' => 'Waiting for your response',
    	'yes' => 'Yes',
    	'no' => 'No',
        'thanks' => 'Thanks!',
    );
```
