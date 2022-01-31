### OBJECTIVE

This Vonage Voice connector extends from the [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector) library. It translates Vonage Voice messages into the Inbenta Chatbot API format and vice versa.

### FUNCTIONALITIES
This connector inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Send information to webhook through forms

### HOW TO CUSTOMIZE

**Custom Behaviors**

If you need to customize the bot flow, you need to modify the class `VonageVoiceConnector.php`. This class extends from the ChatbotConnector and here you can override all the parent methods.


### STRUCTURE

The `VonageVoiceConnector` folder has some classes needed to use the ChatbotConnector with Vonage Voice. These classes are used in the VonageVoiceConnector constructor in order to provide the application with the components needed to send information to Vonage Voice.

**External API folder**

Inside this folder there is the Vonage Voice client which allows the bot to set the message that Vonage Voice will read.


**External Digester folder**

This folder contains the class VonageVoiceDigester. This class is a kind of "translator" between the Chatbot API and Vonage Voice. Mainly, the work performed by this class is to convert a message from the Chatbot API into a message accepted by Vonage Voice. It also does the inverse work, translating messages from Vonage Voice into the format required by the Chatbot API.
