@echo off
echo Testing Product Stock API...

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

REM Note: You need to extract token from token_response.json manually
REM For automated testing, use the PHP script instead

echo.
echo === Test 1: Single unique_id ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{\"unique_id\":\"80DEB248-1924-467C-8745-004BAF851746\"}" ^
     -k

echo.
echo.
echo === Test 2: Multiple unique_ids ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{\"unique_ids\":[\"80DEB248-1924-467C-8745-004BAF851746\",\"29FDC941-FD16-4AE5-AB94-013CDE27CDBC\"]}" ^
     -k

echo.
echo.
echo === Test 3: Invalid request ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{}" ^
     -k

echo.
echo.
echo Testing completed.
pause
