<?php
return function ($context) {
    return $context->res->json([
        'status' => 'success',
        'message' => 'Appwrite Function is alive 🚀',
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $context->req->method,
        'query' => $context->req->query,
        'input' => $context->req->body
    ]);
};
