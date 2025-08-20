<?php

require_once(__DIR__ . '/../vendor/autoload.php');

use Appwrite\Client;
use Appwrite\Services\Users;

return function ($context) {
    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_API_ENDPOINT'))
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID'))
        ->setKey($context->req->headers['x-appwrite-key']);

    $users = new Users($client);

    try {
        $response = $users->list();
        $context->log('Total users: ' . $response['total']);

        return $context->res->json([
            'status' => 'success',
            'totalUsers' => $response['total'],
            'users' => $response['users']
        ]);
    } catch (Throwable $error) {
        $context->error('Could not list users: ' . $error->getMessage());

        return $context->res->json([
            'status' => 'error',
            'message' => $error->getMessage()
        ]);
    }
};
