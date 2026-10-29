<?php

use Bandwidth\Api\MessagesApi;
use Bandwidth\Configuration;
use Bandwidth\Model\MessageRequest;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$BW_ACCOUNT_ID = getenv('BW_ACCOUNT_ID');
$BW_CLIENT_ID = getenv('BW_CLIENT_ID');
$BW_CLIENT_SECRET = getenv('BW_CLIENT_SECRET');
$BW_MESSAGING_APPLICATION_ID = getenv('BW_MESSAGING_APPLICATION_ID');
$BW_NUMBER = getenv('BW_NUMBER');

$config = Configuration::getDefaultConfiguration()
    ->setClientId($BW_CLIENT_ID)
    ->setClientSecret($BW_CLIENT_SECRET);
$messagesApi = new MessagesApi(new Client(), $config);

const STATUS_MESSAGES = [
    'message-sending' => 'message-sending type is only for MMS.',
    'message-delivered' => "Your message has been handed off to the Bandwidth's MMSC network, but has not been confirmed at the downstream carrier.",
    'message-failed' => 'For MMS and Group Messages, you will only receive this callback if you have enabled delivery receipts on MMS.',
];

const REPLIES = [
    'stop' => "STOP: OK, you'll no longer receive messages from us.",
    'quit' => "QUIT: OK, you'll no longer receive messages from us.",
    'help' => 'Valid words are: STOP, QUIT, HELP, and INFO. Reply STOP or QUIT to opt out.',
    'info' => 'INFO: This is the test responder service. Reply STOP or QUIT to opt out.',
];
const DEFAULT_REPLY = 'Please respond with a valid word. Reply HELP for help.';

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

function parseCallbacks(Request $request): ?array
{
    $callbacks = json_decode($request->getBody()->getContents(), true);
    if (!is_array($callbacks) || !array_is_list($callbacks)) {
        return null;
    }
    foreach ($callbacks as $callback) {
        if (!is_array($callback)) {
            return null;
        }
    }
    return $callbacks;
}

function autoReply(mixed $text): string
{
    $keyword = is_string($text) ? strtolower(trim($text)) : '';
    return REPLIES[$keyword] ?? DEFAULT_REPLY;
}

function sendReply(MessagesApi $messagesApi, string $accountId, string $applicationId, string $from, string $to, string $text): void
{
    $messageRequest = new MessageRequest([
        'application_id' => $applicationId,
        'to' => [$to],
        'from' => $from,
        'text' => $text,
    ]);

    try {
        $message = $messagesApi->createMessage($accountId, $messageRequest);
        error_log("Sent reply {$message->getId()}\nTo: {$to}\nText: {$text}");
    } catch (Throwable $e) {
        error_log('Failed to send reply: ' . $e->getMessage());
    }
}

$app->post('/callbacks/outbound/messaging/status', function (Request $request, Response $response) {
    $callbacks = parseCallbacks($request);
    if ($callbacks === null) {
        return $response->withStatus(400);
    }
    foreach ($callbacks as $callback) {
        if (!isset(STATUS_MESSAGES[$callback['type'] ?? ''])) {
            return $response->withStatus(400);
        }
    }

    foreach ($callbacks as $callback) {
        error_log(STATUS_MESSAGES[$callback['type']]);
    }
    return $response->withStatus(204);
});

$app->post('/callbacks/inbound/messaging', function (Request $request, Response $response) use ($messagesApi, $BW_ACCOUNT_ID, $BW_MESSAGING_APPLICATION_ID, $BW_NUMBER) {
    $callbacks = parseCallbacks($request);
    if ($callbacks === null) {
        return $response->withStatus(400);
    }
    foreach ($callbacks as $callback) {
        if (($callback['type'] ?? null) !== 'message-received') {
            return $response->withStatus(400);
        }
    }

    foreach ($callbacks as $callback) {
        $message = $callback['message'];
        error_log("{$callback['description']}\nTo: {$message['to'][0]}\nFrom: {$message['from']}\nText: {$message['text']}");
        sendReply($messagesApi, $BW_ACCOUNT_ID, $BW_MESSAGING_APPLICATION_ID, $BW_NUMBER, $message['from'], autoReply($message['text'] ?? null));
    }
    return $response->withStatus(204);
});

$app->run();
