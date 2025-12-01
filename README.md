# Flash Sale API

A high-performance Laravel API for managing flash sales with stock reservation, order processing, and payment webhook handling.

## Assumptions & Invariants

### Assumptions

1. **Hold Duration**: Each hold expires after **120 seconds (2 minutes)** from creation
2. **Single Product Focus**: The system is designed for flash sales, typically focusing on one product at a time
3. **Stock Reservation Model**: Holds reserve stock but do not consume it until an order is paid
4. **Webhook Retry Logic**: Payment providers will retry webhooks if they receive 404 responses
5. **Concurrent Access**: The system handles high concurrent load using pessimistic locking
6. **Cache TTL**: Product available stock is cached for **10 seconds** to handle burst traffic

### Invariants

The following invariants **must never be violated**:

1. **Stock Conservation**: Total reserved stock (sum of all active holds) + paid orders never exceeds original product stock

    - Formula: `sum(active_holds.quantity) + sum(paid_orders.quantity) ≤ product.stock`

2. **Hold Uniqueness**: Each hold can only be used once. Once `is_used = true`, a hold cannot be used to create another order

3. **Idempotency Guarantee**: Each `idempotency_key` can only result in one payment state. Processing the same webhook with the same `idempotency_key` multiple times produces identical results

4. **Expired Hold Exclusion**: Expired holds (`expires_at < NOW()`) do not count toward reserved stock. Only active holds (`is_used = false` AND `expires_at > NOW()`) are included in available stock calculations

5. **Transaction Atomicity**: All critical operations (hold creation, order creation, payment processing) are wrapped in database transactions. Deadlocks are automatically retried up to 3 times with exponential backoff

## How to Run the App

### Prerequisites

-   PHP 8.2 or higher
-   Composer
-   MySQL
-   Laravel 12.x

### Setup Instructions

1. **Install dependencies**:

    ```bash
    composer install
    ```

2. **Configure environment**:

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

3. **Configure database** in `.env`:

    ```env
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=flash_sale_db
    DB_USERNAME=your_username
    DB_PASSWORD=your_password
    ```

4. **Run migrations**:

    ```bash
    php artisan migrate
    ```

5. **Run seeders**:

    ```bash
    php artisan db:seed
    ```

    This creates:

    - One product: "Flash Sale Product 1" with price 99.99 and stock 100
    - One user: "User" with email "user@gmail.com"
    - Sample data for holds, orders, and payments

6. **Start the server**:
    ```bash
    php artisan serve
    ```
    API available at `http://localhost:8000`

### Cache Configuration

-   **Default Cache Driver**: `database` (configured in `config/cache.php`)
-   **Cache Key Format**: `product_{id}_available_stock`
-   **TTL**: 10 seconds for product available stock
-   **Setup**: Cache table is created automatically when running `php artisan migrate`

## How to Run Tests

```bash
php artisan test
```

Or using PHPUnit:

```bash
./vendor/bin/phpunit
```

**Test Files**:

-   `tests/Feature/ConcurrentHoldTest.php`: Tests concurrent hold creation and stock boundary conditions
-   `tests/Feature/WebhookIdempotencyTest.php`: Tests webhook idempotency and out-of-order handling
-   `tests/Feature/ProcessExpiredHoldsTest.php`: Tests expired hold processing and cache invalidation

**Test Configuration**: Tests use `array` cache driver and `RefreshDatabase` trait (configured in `phpunit.xml`)

## Where to See Logs/Metrics

### Log Files

**Location**: `storage/logs/laravel.log`

**View logs**:

```bash
tail -f storage/logs/laravel.log
tail -50 storage/logs/laravel.log | grep ERROR
```

**Logged Events**:

-   Hold creation: product_id, qty, available_stock before/after, success/failure, processing time
-   Order creation: hold_id, hold status, success/failure
-   Payment webhook: order_id, idempotency_key, is_duplicate, previous_status, new_status, processing time
-   Hold expiry: count of expired holds, affected product IDs, timestamp
-   Deadlocks: operation, retry count, final result
-   Cache failures: operation, cache_key, error

### Metrics

**View via Tinker**:

```bash
php artisan tinker
```

```php
$metrics = app(\App\Services\MetricsService::class);
$metrics->getAllMetrics();
```

**Metrics Tracked**:

-   Webhook duplicates count
-   Deadlock retries count
-   Average hold creation time (ms)
-   Average webhook processing time (ms)
-   Cache hit rate for product available stock

## API Endpoints

### GET /api/products/{id}

Get product details with available stock.

**URL**: `GET http://localhost:8000/api/products/1`

**Response** (200):

```json
{
    "id": 1,
    "name": "Flash Sale Product 1",
    "price": 99.99,
    "total_stock": 100,
    "available_stock": 87
}
```

**Error Responses**:

-   `404`: Product not found

---

### POST /api/holds

Create a temporary stock reservation (hold). Valid for 2 minutes.

**URL**: `POST http://localhost:8000/api/holds`

**Request Headers**:

```
Content-Type: application/json
Accept: application/json
```

**Request Body**:

```json
{
    "product_id": 1,
    "qty": 5
}
```

**Validation Rules**:

-   `product_id`: required, integer, must exist in products table
-   `qty`: required, integer, minimum 1

**Success Response** (201):

```json
{
    "hold_id": 42,
    "product_id": 1,
    "quantity": 5,
    "expires_at": "2025-11-30T19:57:53+00:00"
}
```

**Error Responses**:

-   `404`: Product not found
-   `400`: Insufficient stock available
-   `422`: Validation errors
    ```json
    {
        "message": "Validation failed",
        "errors": {
            "product_id": ["The product_id field is required."],
            "qty": ["The qty must be at least 1."]
        }
    }
    ```

**Example cURL**:

```bash
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "product_id": 1,
    "qty": 5
  }'
```

---

### POST /api/orders

Convert a valid hold into an order with pending status.

**URL**: `POST http://localhost:8000/api/orders`

**Request Headers**:

```
Content-Type: application/json
Accept: application/json
```

**Request Body**:

```json
{
    "hold_id": 42
}
```

**Validation Rules**:

-   `hold_id`: required, integer, must exist in holds table

**Success Response** (201):

```json
{
    "order_id": 101,
    "hold_id": 42,
    "status": "pending"
}
```

**Error Responses**:

-   `404`: Hold not found
-   `400`: Hold has expired
-   `400`: Hold has already been used
-   `422`: Validation errors
    ```json
    {
        "message": "Validation failed",
        "errors": {
            "hold_id": ["The hold_id field is required."]
        }
    }
    ```

**Example cURL**:

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "hold_id": 42
  }'
```

---

### POST /api/payments/webhook

Process payment confirmation/failure notification. Idempotent - same `idempotency_key` returns same result.

**URL**: `POST http://localhost:8000/api/payments/webhook`

**Request Headers**:

```
Content-Type: application/json
Accept: application/json
```

**Request Body** (Success):

```json
{
    "order_id": 101,
    "idempotency_key": "unique-key-from-payment-provider-123",
    "status": "success"
}
```

**Request Body** (Failure):

```json
{
    "order_id": 101,
    "idempotency_key": "unique-key-from-payment-provider-123",
    "status": "failed"
}
```

**Validation Rules**:

-   `order_id`: required, integer
-   `idempotency_key`: required, string, minimum 1 character
-   `status`: required, string, must be either "success" or "failed"

**Success Response** (200) - Payment Success:

```json
{
    "order_id": 101,
    "status": "paid"
}
```

**Success Response** (200) - Payment Failed:

```json
{
    "order_id": 101,
    "status": "cancelled"
}
```

**Success Response** (200) - Duplicate Request (Idempotent):

```json
{
    "order_id": 101,
    "status": "paid"
}
```

Returns the same response as the first request with the same `idempotency_key`.

**Error Responses**:

-   `404`: Order not found
-   `400`: Status must be either "success" or "failed"
-   `422`: Validation errors
    ```json
    {
        "message": "Validation failed",
        "errors": {
            "idempotency_key": ["The idempotency_key field is required."],
            "status": ["The status must be either \"success\" or \"failed\"."]
        }
    }
    ```

**Example cURL** (Success):

```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "order_id": 101,
    "idempotency_key": "payment-provider-key-123",
    "status": "success"
  }'
```

**Example cURL** (Failure):

```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "order_id": 101,
    "idempotency_key": "payment-provider-key-123",
    "status": "failed"
  }'
```

## Scheduled Tasks

Process expired holds every minute:

```bash
php artisan schedule:run
```

**Production Crontab**:

```
* * * * * cd /var/www/flash-sale-api && php artisan schedule:run >> /dev/null 2>&1
```
