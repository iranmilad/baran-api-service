@echo off
echo Testing Product Stock API with WordPress Update...

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
echo === Test: Multiple unique_ids with WordPress Update ===
curl -X POST "%BASE_URL%/stock" ^
     -H "Content-Type: application/json" ^
     -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
     -d "{\"unique_ids\":[\"80DEB248-1924-467C-8745-004BAF851746\",\"29FDC941-FD16-4AE5-AB94-013CDE27CDBC\",\"283bff71-7a55-4610-acd5-c8852dd147f3\"]}" ^
     -k > stock_response.json

echo.
echo Response saved to stock_response.json
echo.
echo === Response Content ===
type stock_response.json

echo.
echo.
echo Testing completed.
pause
