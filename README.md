### OBJECTIVE

This library connects the Inbenta Chatbot API with external messaging services like Facebook, Line, Skype, etc, and applies some logic like asking for the content rating, handling HyperChat escalation, etc. It doesn't work alone, but rather must be included as a dependency in the final UI, in **composer.json**. Then it has to be extended from the connector for the external service that also should be included in the final UI (as a Composer dependency or embedded in the code). Additionally, the final UI needs some configuration files and language translation files. Here you have a few examples of final UIs for different external services:
* [Facebook](https://github.com/inbenta-integrations/facebook_chatbot_template)

You can see that the final UI has **/conf** and **/lang** folders, the **server.php** file and the **composer.json** file, which loads all the non-embedded dependencies like (this library, and the base HyperChat libraries).

### FUNCTIONALITIES
Currently, the features provided by this application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat after a number of negative content ratings
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms

Note that some components of this application must be extended and implemented because a specific implementation is needed for the target external service.


### COMPONENTS

**External digester**

This is a class that converts the messages received from the external service into the format of the Chatbot API. This class will also convert the messages from the API into the external service format too.


**External client**

This is a class that sends the messages received from the bot to the external service. This class is also responsible for handling security/authorization challenges sent from the external service.


**HyperChat adapter for external client**

This component extends from the default HyperChatClient provided by Product chapter. It is responsible for creating an instance for the external client from the user `external_id` parameter stored in HyperChat. This behavior allows us to handle HyperChat events without the need of having a running instance for the chatbot. HyperChat will send the messages directly from the external client to the agent.

In this example we can see how to create a Facebook API Client instance. The Facebook API Client needs to know the userId so we need to retrieve this data and return a working API Client:
```php
    //Instances an external client for Facebook
    protected function instanceExternalClient($externalId, $appConf){
        $facebookId = FacebookAPIClient::getIdFromExternalId($externalId);
        $externalClient = new FacebookAPIClient($appConf->get('fb.page_access_token'));
        $externalClient->setSenderFromId( $facebookId );
        return $externalClient;
    }
);
```


**Utils**

Inside the utils directory there are certain components used for the basic management of the application such as Session manager, Language manager, Environment detector, etc.


### CONFIGURATION STRUCTURE

The structure of the configuration folder should be implemented on the application that extends this library. It is inherited from the Bot template and follows the same structure and overriding logic.

- **DEFAULT CONFIGURATION**

Default configuration is meant to define the possible configuration files and the default values for each configuration parameter. It's not meant to be modified to configure the project, for this we'll have the `custom` configuration folder.

Every configuration file will and should be placed inside the `conf/default` folder.  This means that all configuration values should have a default representative value even if it's null. The default configuration will always be loaded at the initialization and will be overwritten by custom configuration and environment configuration.

- **CUSTOM CONFIGURATION**

The custom configuration folder (`conf/custom/`) contains the files that have a different configuration from the default folder. If you want to configure any parameter, just add it to the files in the custom configuration folder or create the files if they don't exist (they should exist in the default configuration).

- **CONFIGURATION BY ENVIRONMENT**

Sometimes, we may need different configurations depending on the environment.

By default, the bot is prepared to work with three environments: `production`, `development` and `preproduction`. This means that, if you create the corresponding folder inside the conf directory, the bot will look for any configuration files placed there to overwrite the `custom` or `default` configuration when it detects that it's running on that environment.

Setting the configuration for each environment is as simple as creating a folder for the desired environment (at the same level as the default folder) and placing the files we want to modify from the `custom` or `default` configuration there.
You don’t need to set the whole configuration, just the parameters that you want to modify.

- **CONFIGURATION FILES**

The configuration files that will be looked for in the default folder are defined in `/conf/configurationFiles.php`.
This file contains an array where the key corresponds with the configuration namespace and the value contains the relative path for the file inside the default folder. If you want to create a new configuration file you should set its namespace and path here and place the file inside the default folder.

```php
return array(
    // Chatbot API credentials
    'api' => '/api.php',

    // Hyperchat configuration
    'chat' => '/chat.php',

    // Chatbot API conversation
    'conversation' => '/conversation.php',

    //Environments
    'environments' => '/environments.php',
);
```
