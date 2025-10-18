# Tantooo API Integration - Complete Summary

## Overview
Complete Tantooo API integration for Laravel application with comprehensive product management capabilities, universal token system, and WooCommerce-compatible sync methods.

## Architecture

### Files Structure
```
app/
├── Traits/
│   └── Tantooo/
│       └── TantoooApiTrait.php (Main API integration trait)
├── Http/
│   └── Controllers/
│       └── Tantooo/
│           ├── TantoooDataController.php (Data retrieval)
│           └── TantoooProductController.php (Product operations)
└── Models/
    └── License.php (Enhanced with universal token management)

routes/
└── api.php (Tantooo routes added)

database/
└── migrations/
    └── add_universal_token_fields_to_licenses_table.php
```

## API Endpoints

### 1. Main Data Retrieval
```
GET /api/v1/tantooo/data/categories   - Get all categories
GET /api/v1/tantooo/data/colors      - Get all colors  
GET /api/v1/tantooo/data/sizes       - Get all sizes
GET /api/v1/tantooo/data/all         - Get all main data
```

### 2. Product Sync (WooCommerce Compatible)
```
POST /api/v1/tantooo/products/sync      - Single product sync
POST /api/v1/tantooo/products/bulk-sync - Multiple products sync
```

### 3. Product Updates (New Methods)
```
POST /api/v1/tantooo/products/update-stock - Update product stock
POST /api/v1/tantooo/products/update-info  - Update product information
```

### 4. Product Retrieval (New Method)
```
GET /api/v1/tantooo/products/list - Get products list with pagination
```

## TantoooApiTrait Methods

### Core Methods
1. **getTantoooToken($license)** - Get/refresh token for API access
2. **getTantoooMainData($license)** - Retrieve categories, colors, sizes

### Product Sync Methods  
3. **syncProductsToTantoooApi($license, $products)** - Sync products to Tantooo
4. **bulkSyncProductsToTantoooApi($license, $products)** - Bulk sync products

### Product Update Methods (NEW)
5. **updateProductStockInTantoooApi($license, $token, $code, $count)** - Direct stock update
6. **updateProductInfoInTantoooApi($license, $token, $code, $title, $price, $discount)** - Direct info update
7. **updateProductStockWithToken($license, $code, $count)** - Stock update with auto token
8. **updateProductInfoWithToken($license, $code, $title, $price, $discount)** - Info update with auto token

### Product Retrieval Methods (NEW)
9. **getProductsFromTantoooApi($license, $page, $countPerPage)** - Get products with auto token
10. **getProductsFromTantoooApiWithToken($license, $token, $page, $countPerPage)** - Get products with token

## Request/Response Examples

### Stock Update
**Request:**
```json
POST /api/v1/tantooo/products/update-stock
Authorization: Bearer JWT_TOKEN
Content-Type: application/json

{
    "code": "PRODUCT_CODE", 
    "count": 10
}
```

**Response:**
```json
{
    "success": true,
    "message": "Product stock updated successfully in Tantooo API",
    "data": {
        "code": "PRODUCT_CODE",
        "count": 10,
        "response": "API_RESPONSE_DATA"
    }
}
```

### Product Info Update
**Request:**
```json
POST /api/v1/tantooo/products/update-info
Authorization: Bearer JWT_TOKEN
Content-Type: application/json

{
    "code": "PRODUCT_CODE",
    "title": "Product New Title",
    "price": 150000,
    "discount": 5
}
```

**Response:**
```json
{
    "success": true,
    "message": "Product information updated successfully in Tantooo API",
    "data": {
        "code": "PRODUCT_CODE",
        "title": "Product New Title", 
        "price": 150000,
        "discount": 5,
        "response": "API_RESPONSE_DATA"
    }
}
```

### Get Products List
**Request:**
```json
GET /api/v1/tantooo/products/list?page=1&count_per_page=100
Authorization: Bearer JWT_TOKEN
```

**Response:**
```json
{
    "success": true,
    "message": "لیست محصولات با موفقیت دریافت شد",
    "data": {
        "products": [
            {
                "id": 88,
                "title": "ست شومیز شلوار زنانه",
                "price": 3480000,
                "price_all": 3480000,
                "discount": 0,
                "discount_price": 0,
                "images": "[{\"id\":1,\"img\":\"url\"}]",
                "active": 1,
                "count": 3,
                "id_color": 42,
                "color_name": "مشکی",
                "id_size": 88,
                "size_name": "فری سایز 1 (38 تا 40)",
                "category_1_name": "ست شومیز شلوار زنانه",
                "sku": "",
                "code": ""
            }
        ],
        "total_count": 74,
        "current_page": 1,
        "per_page": 100,
        "msg": 0,
        "error": []
    }
}
```

## Universal Token Management

### Database Fields (License Model)
```sql
ALTER TABLE licenses ADD COLUMN token TEXT NULL;
ALTER TABLE licenses ADD COLUMN token_expires_at DATETIME NULL;
```

### Token Methods
```php
// Check if token is valid
$license->isTokenValid()

// Update token
$license->updateToken($token, $expiresAt)

// Clear token  
$license->clearToken()
```

## Validation Rules

### Stock Update
- `code`: required|string
- `count`: required|integer|min:0

### Product Info Update  
- `code`: required|string
- `title`: required|string
- `price`: required|numeric|min:0
- `discount`: nullable|numeric|min:0|max:100

### Get Products List
- `page`: nullable|integer|min:1
- `count_per_page`: nullable|integer|min:1|max:200

### Product Sync
- `products`: required|array
- `products.*.id`: required
- `products.*.name`: required|string
- `products.*.price`: required|numeric|min:0

## Tantooo API Functions Mapping

| Laravel Method | Tantooo API Function | Purpose |
|---------------|---------------------|---------|
| getTantoooMainData | get_main_data | Get categories, colors, sizes |
| syncProductsToTantoooApi | add_product | Add/sync single product |
| bulkSyncProductsToTantoooApi | add_product | Bulk add/sync products |
| updateProductStockInTantoooApi | change_count_sub_product | Update product stock |
| updateProductInfoInTantoooApi | update_product_sku_code | Update product information |
| getProductsFromTantoooApi | get_sub_main | Get products list with pagination |

## Usage Examples

### In Controller
```php
use App\Traits\Tantooo\TantoooApiTrait;

class ProductController extends Controller 
{
    use TantoooApiTrait;

    public function updateStock(Request $request)
    {
        $license = Auth::user()->license;
        
        // Method 1: With automatic token management (Recommended)
        $result = $this->updateProductStockWithToken(
            $license, 
            $request->code, 
            $request->count
        );
        
        // Method 2: With manual token management
        $token = $this->getTantoooToken($license);
        $result = $this->updateProductStockInTantoooApi(
            $license,
            $token, 
            $request->code, 
            $request->count
        );
        
        return response()->json($result);
    }
}
```

### In Job/Queue
```php
use App\Traits\Tantooo\TantoooApiTrait;

class UpdateProductStockJob implements ShouldQueue
{
    use TantoooApiTrait;

    public function handle()
    {
        $license = License::find($this->licenseId);
        
        $result = $this->updateProductStockWithToken(
            $license,
            $this->productCode,
            $this->newCount
        );
    }
}
```

## Security Features

1. **JWT Authentication** - All endpoints require valid JWT token
2. **Input Validation** - Comprehensive validation for all inputs
3. **Token Management** - Automatic token refresh and expiration handling
4. **Error Logging** - Complete error tracking and logging
5. **Rate Limiting** - Built-in Laravel rate limiting support

## Error Handling

### Common Error Responses
```json
{
    "success": false,
    "message": "Error description",
    "error": "Detailed error information"
}
```

### Error Types
- **Authentication Error**: Invalid or expired JWT token
- **Validation Error**: Invalid input parameters
- **API Error**: Tantooo API communication failure
- **Token Error**: Tantooo token expired or invalid
- **Network Error**: Connection issues

## Testing

### Run Tests
```bash
# Test all new methods
php test_tantooo_product_update_methods.php

# Test API integration
php test_tantooo_api_integration.php
```

### Manual Testing with cURL
```bash
# Stock Update
curl -X POST http://localhost/api/v1/tantooo/products/update-stock \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"PRODUCT_CODE","count":10}'

# Get products list
curl -X GET http://localhost/api/v1/tantooo/products/list?page=1&count_per_page=50 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

## Migration Required

Run this migration to add universal token fields:
```bash
php artisan migrate
```

## Performance Considerations

1. **Token Caching** - Tokens are cached until expiration
2. **Bulk Operations** - Use bulk-sync for multiple products
3. **Queue Integration** - Use queues for large operations
4. **Error Retry** - Automatic retry logic for API failures

## Monitoring and Logging

All API calls are logged with:
- Request parameters
- Response data  
- Execution time
- Error details (if any)

Check logs at: `storage/logs/laravel.log`

---

## Status: ✅ COMPLETE

All Tantooo API integration features have been successfully implemented:

- ✅ Universal token management system
- ✅ Main data retrieval (categories, colors, sizes)
- ✅ WooCommerce-compatible sync methods
- ✅ Specific product update methods (stock & info)
- ✅ Product list retrieval with pagination
- ✅ Comprehensive error handling and validation
- ✅ Complete documentation and testing

**Ready for production use!**
