@echo off
echo Testing Product Stock API with Warehouse API...

REM Replace these with your actual API credentials
set API_KEY=YOUR_API_KEY
set API_SECRET=YOUR_API_SECRET
set BASE_URL=http://localhost/baran-api-service/public/api/v1

echo.
echo === Getting Authentication Token ===
curl -X POST "%BASE_URL%/login" ^
     -H "Content-Type: application/json" ^
     -d "{\"api_key\":\"%API_KEY%\",\"api_secret\":\"%API_SECRET%\"}" ^
     -k > token_response.json

echo Token saved to token_response.json
echo.

REM Extract token manually or replace YOUR_TOKEN_HERE with actual token
echo === Test 1: Multiple unique_ids with Warehouse API ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{\"unique_ids\":[\"80DEB248-1924-467C-8745-004BAF851746\",\"29FDC941-FD16-4AE5-AB94-013CDE27CDBC\",\"283bff71-7a55-4610-acd5-c8852dd147f3\"]}" ^
     -k > warehouse_response.json

echo.
echo Response saved to warehouse_response.json
echo.

echo === Test 2: Single unique_id (Validation Error Test) ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{\"unique_id\":\"80DEB248-1924-467C-8745-004BAF851746\"}" ^
     -k > validation_error_response.json

echo.
echo Validation error response saved to validation_error_response.json
echo.
echo.
echo === Response Analysis ===
echo Checking successful response structure...
type warehouse_response.json

echo.
echo Checking validation error response...
type validation_error_response.json

echo.
echo.
echo === Configuration Requirements ===
echo Please ensure the following are configured:
echo 1. warehouse_api_url in users table (e.g., http://103.216.62.61:4645)
echo 2. warehouse_api_username in users table
echo 3. warehouse_api_password in users table
echo 4. enable_stock_update in user_settings table
echo 5. default_warehouse_code in user_settings table
echo.
echo Note: API now only accepts unique_ids parameter (array format)
echo 6. WooCommerce API credentials

echo.
echo Testing completed.
pause
