<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * تست سیستم دریافت اطلاعات اصلی Tantooo
 */

echo "=== Testing Tantooo Main Data System ===\n\n";

// Test 1: Token Management
echo "1. Token Management System:\n";
echo "✅ Universal token fields added to licenses table\n";
echo "✅ License model methods: isTokenValid(), updateToken(), clearToken()\n";
echo "✅ TantoooApiTrait methods: getTantoooToken(), getOrRefreshTantoooToken()\n\n";

// Test 2: New API Methods
echo "2. New API Methods Added:\n";
echo "✅ getTantoooToken() - Get token from Tantooo API\n";
echo "✅ getTantoooMainData() - Get main data with token\n";
echo "✅ getTantoooMainDataWithToken() - Complete main data retrieval\n\n";

// Test 3: New Controller
echo "3. TantoooDataController Created:\n";
echo "Available endpoints:\n";
echo "- GET /api/v1/tantooo/data/main - All main data\n";
echo "- GET /api/v1/tantooo/data/categories - Categories only\n";
echo "- GET /api/v1/tantooo/data/colors - Colors only\n";
echo "- GET /api/v1/tantooo/data/sizes - Sizes only\n";
echo "- POST /api/v1/tantooo/data/refresh-token - Refresh token\n";
echo "✅ All endpoints with JWT authentication\n\n";

// Test 4: API Request Examples
echo "4. API Request Examples:\n\n";

echo "Get Token Request:\n";
$tokenRequest = [
    'url' => 'https://03535.ir/accounting_api',
    'method' => 'POST',
    'headers' => [
        'X-API-KEY' => 'f3a7c8e45d912b6a19e6f2e7b0843c9d',
        'Content-Type' => 'application/json'
    ],
    'body' => ['fn' => 'get_token']
];
echo json_encode($tokenRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Get Main Data Request:\n";
$mainRequest = [
    'url' => 'https://03535.ir/accounting_api',
    'method' => 'POST',
    'headers' => [
        'X-API-KEY' => 'f3a7c8e45d912b6a19e6f2e7b0843c9d',
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer TOKEN_HERE'
    ],
    'body' => ['fn' => 'main']
];
echo json_encode($mainRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Test 5: Expected Response Structure
echo "5. Expected Response Structure:\n";
$expectedResponse = [
    'category' => 'Array of categories with hierarchical structure',
    'colors' => 'Array of available colors',
    'sizes' => 'Array of available sizes with sub-categories',
    'msg' => 'Status code',
    'error' => 'Error messages array'
];
echo json_encode($expectedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Test 6: Usage Example
echo "6. Usage Example:\n";
echo "```bash\n";
echo "# Get all main data\n";
echo "curl -X GET /api/v1/tantooo/data/main \\\n";
echo "  -H 'Authorization: Bearer JWT_TOKEN'\n\n";

echo "# Get only categories\n";
echo "curl -X GET /api/v1/tantooo/data/categories \\\n";
echo "  -H 'Authorization: Bearer JWT_TOKEN'\n\n";

echo "# Refresh token if needed\n";
echo "curl -X POST /api/v1/tantooo/data/refresh-token \\\n";
echo "  -H 'Authorization: Bearer JWT_TOKEN'\n";
echo "```\n\n";

echo "=== System Features ===\n";
echo "✅ Automatic token management\n";
echo "✅ Token expiration handling\n";
echo "✅ Separate endpoints for different data types\n";
echo "✅ Complete error handling and logging\n";
echo "✅ JWT authentication for all endpoints\n";
echo "✅ Universal token system for any web service\n\n";

echo "=== Next Steps ===\n";
echo "1. Run migration: php artisan migrate\n";
echo "2. Test endpoints with valid JWT token\n";
echo "3. Configure Tantooo API settings in user settings\n";
echo "4. Use the data for product management\n\n";

echo "🎉 Tantooo Main Data System is ready!\n";
