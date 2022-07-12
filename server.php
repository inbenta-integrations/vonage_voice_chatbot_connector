<?php

require "vendor/autoload.php";

use Inbenta\VonageVoiceConnector\VonageVoiceConnector;
use Klein\Klein as Router;
use Klein\Request;
use Klein\Response;

// Instance the Router
$router = new Router();

$appPath = __DIR__ . '/';

//Start conversation
$router->respond('GET', '/answer', function (Request $request, Response $response) use ($appPath) {
    $app = new VonageVoiceConnector($appPath, $request); // Instance Connector
    return $app->handleUserMessage($response);
});

//Flow of the conversation
$router->respond('POST', '/asr', function (Request $request, Response $response) use ($appPath) {
    $app = new VonageVoiceConnector($appPath, $request); // Instance Connector
    return $app->handleUserMessage($response);
});

//Check status
$router->respond('GET', '/events', function (Request $request, Response $response) {
    $message = ['status' => ''];
    if (isset($request->params()['status'])) {
        $message['status'] = $request->params()['status'];
    }
    return $response->json($message);
});

//Root url
$router->respond('GET', '/', function (Request $request, Response $response) {
    $message = ['message' => 'Vonage voice'];
    return $response->json($message);
});

$router->dispatch();
