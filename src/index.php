require_once 'src/Pesapal/Token.php';
require_once 'src/Pesapal/Order.php';
require_once 'src/Pesapal/IPNHandler.php';

use Pesapal\Token;
use Pesapal\Order;
use Pesapal\IPNHandler;

return function($context) {
    // Get request method and path
    $method = $context->req->method;
    $path = $context->req->path ?? '/';
    
    // Route the request
    switch ($path) {
        case '/ping':
            return $context->res->json(['status' => 'alive', 'timestamp' => time()]);
            
        case '/create-order':
            if ($method !== 'POST') {
                return $context->res->json(['error' => 'Method not allowed'], 405);
            }
            
            try {
                $orderHandler = new Order($context);
                $result = $orderHandler->createOrder($context->req->body);
                return $context->res->json($result);
            } catch (Exception $e) {
                $context->error('Order creation failed: ' . $e->getMessage());
                return $context->res->json(['error' => 'Order creation failed'], 500);
            }
            
        case '/ipn':
            if ($method !== 'POST') {
                return $context->res->json(['error' => 'Method not allowed'], 405);
            }
            
            try {
                $ipnHandler = new IPNHandler($context);
                $result = $ipnHandler->processIPN($context->req->body);
                return $context->res->json($result);
            } catch (Exception $e) {
                $context->error('IPN processing failed: ' . $e->getMessage());
                return $context->res->json(['error' => 'IPN processing failed'], 500);
            }
            
        default:
            return $context->res->json(['error' => 'Route not found'], 404);
    }
};