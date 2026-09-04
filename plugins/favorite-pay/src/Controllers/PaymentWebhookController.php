<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Pay\Contracts\WebhookServiceInterface;

class PaymentWebhookController
{
    private Application $app;
    private WebhookServiceInterface $webhookService;

    public function __construct(Application $app, WebhookServiceInterface $webhookService)
    {
        $this->app = $app;
        $this->webhookService = $webhookService;
    }

    public function handle(Request $request, string $gateway): Response
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    $headers[strtolower((string)$k)] = $v;
                    $headers[(string)$k] = $v;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
                $headers[$name] = $value;
            }
        }

        $rawBody = file_get_contents('php://input');
        $payload = !empty($rawBody) ? $rawBody : $request->all();

        $result = $this->webhookService->handle($gateway, $headers, $payload);

        return Response::json([
            'status'            => $result->isSuccess() ? 'success' : 'error',
            'message'           => $result->getMessage(),
            'already_processed' => $result->isAlreadyProcessed(),
        ], $result->getStatusCode());
    }
}
