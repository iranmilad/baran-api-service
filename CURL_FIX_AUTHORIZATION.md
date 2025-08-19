# تصحیح درخواست curl برای SaveCustomer

## ❌ درخواست نادرست (فعلی):
```bash
curl --location 'http://103.216.62.61:8585/RainSaleService.svc/SaveCustomer' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer {{token}}' \
--data '{"customer":{"Address":"خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴","FirstName":"تبسم","LastName":"ایلخان","Mobile":"09902847992","CustomerCode":"09902847992","IsMale":"1","IsActive":"1"}}'
```

## ✅ درخواست صحیح:
```bash
curl --location 'http://103.216.62.61:8585/RainSaleService.svc/SaveCustomer' \
--header 'Content-Type: application/json' \
--header 'Authorization: Basic {{base64_encoded_username_password}}' \
--data '{"customer":{"Address":"خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴","FirstName":"تبسم","LastName":"ایلخان","Mobile":"09902847992","CustomerCode":"09902847992","IsMale":"1","IsActive":"1"}}'
```

## 🔑 نحوه ایجاد Basic Auth Token:

### روش 1: دستی
```bash
# فرض کنید username=myuser و password=mypass
echo -n "myuser:mypass" | base64
# نتیجه: bXl1c2VyOm15cGFzcw==
```

### روش 2: مستقیم در curl
```bash
curl --location 'http://103.216.62.61:8585/RainSaleService.svc/SaveCustomer' \
--user 'username:password' \
--header 'Content-Type: application/json' \
--data '{"customer":{"Address":"خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴","FirstName":"تبسم","LastName":"ایلخان","Mobile":"09902847992","CustomerCode":"09902847992","IsMale":"1","IsActive":"1"}}'
```

## 📋 مثال کامل:
```bash
# جایگزین کردن username و password با مقادیر واقعی
curl --location 'http://103.216.62.61:8585/RainSaleService.svc/SaveCustomer' \
--user 'your_api_username:your_api_password' \
--header 'Content-Type: application/json' \
--data '{
    "customer": {
        "Address": "خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴",
        "FirstName": "تبسم",
        "LastName": "ایلخان", 
        "Mobile": "09902847992",
        "CustomerCode": "09902847992",
        "IsMale": "1",
        "IsActive": "1"
    }
}'
```

## 🚨 نکته مهم:
API شما از Basic Authentication استفاده می‌کند نه Bearer Token. خطای Base64 به این دلیل رخ می‌دهد که سرور سعی می‌کند {{token}} را به عنوان Base64 decode کند اما این یک Bearer token است نه Basic Auth.

## 🔍 بررسی کد Laravel:
کد Laravel شما در تمام درخواست‌ها از این فرمت استفاده می‌کند:
```php
'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
```
