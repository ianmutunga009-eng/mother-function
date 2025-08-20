<?php

declare(strict_types=1);

// ✅ Basic Appwrite Function sanity check
header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'message' => 'Function deployed via GitHub is working 🎉',
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'query' => $_GET,
    'input' => json_decode(file_get_contents('php://input'), true),
];

// 🔥 Output JSON response
echo json_encode($response);
