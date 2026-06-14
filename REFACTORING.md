# iVehicle - Refactored Version

## Overview

This refactored version of iVehicle introduces a modern, object-oriented architecture while maintaining backward compatibility with the existing codebase. The refactoring follows PSR-4 autoloading standards and implements common design patterns for better maintainability, testability, and scalability.

## New Architecture

### Directory Structure

```
vehicle-service-tracker/
├── src/                          # New refactored source code
│   ├── Database.php              # Singleton database connection manager
│   ├── Controllers/              # Request handlers (to be added)
│   ├── Models/                   # Data access layer
│   │   ├── Model.php             # Base model with CRUD operations
│   │   ├── User.php              # User model
│   │   ├── Vehicle.php           # Vehicle model
│   │   └── ServiceRecord.php     # Service record model
│   ├── Services/                 # Business logic layer
│   │   └── EmailService.php      # Email handling service
│   ├── Middleware/               # HTTP middleware
│   │   └── AuthMiddleware.php    # Authentication middleware
│   └── Helpers/                  # Utility classes
│       └── Validator.php         # Form validation helper
├── includes/                     # Legacy includes (maintained for compatibility)
│   ├── config.php                # Configuration (still used)
│   ├── EmailHelper.php           # Legacy email helper (deprecated)
│   ├── header.php                # Common header
│   └── footer.php                # Common footer
└── ...                           # Other existing files
```

## Key Improvements

### 1. Database Layer (`src/Database.php`)

**Before:** Global function `getDBConnection()` with static PDO instance
**After:** Singleton pattern with type hints and proper error handling

```php
// Old way (still works)
$pdo = getDBConnection();

// New way (recommended)
use App\Database\Database;
$db = Database::getInstance();
$pdo = $db->getConnection();

// Or use convenience methods
$results = $db->fetchAll("SELECT * FROM users WHERE id = ?", [$userId]);
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
```

### 2. Model Layer (`src/Models/`)

**Before:** SQL queries scattered throughout PHP files
**After:** Dedicated model classes with reusable methods

```php
// Old way
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE user_id = ? AND is_active = 1");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// New way
use App\Models\Vehicle;
$vehicleModel = new Vehicle();
$vehicles = $vehicleModel->getUserVehicles($userId);
```

#### Available Model Methods

**User Model:**
- `findByEmail(string $email)` - Find user by email
- `verifyCredentials(string $email, string $password)` - Verify login credentials
- `createUser(array $data)` - Create new user
- `getStatistics(int $userId)` - Get user dashboard statistics
- `emailExists(string $email)` - Check if email exists

**Vehicle Model:**
- `getUserVehicles(int $userId)` - Get all user vehicles
- `findWithServiceRecord(int $id)` - Get vehicle with latest service
- `getVehiclesNeedingService(int $userId, int $threshold)` - Get vehicles due for service
- `createVehicle(array $data)` - Create new vehicle
- `updateMileage(int $id, int $mileage)` - Update vehicle mileage
- `search(int $userId, string $query)` - Search vehicles

**ServiceRecord Model:**
- `getByVehicle(int $vehicleId)` - Get service records for vehicle
- `getUpcomingServices(int $userId, int $threshold)` - Get upcoming services
- `getOverdueServices(int $userId)` - Get overdue services
- `createService(array $data)` - Create service record
- `getByDateRange(int $userId, string $start, string $end)` - Get services by date range

### 3. Email Service (`src/Services/EmailService.php`)

**Before:** Monolithic `EmailHelper` class with inline HTML templates
**After:** Clean service class with sprintf-based template building

```php
// Old way (still works)
$emailHelper = new EmailHelper($pdo);
$emailHelper->sendWelcomeEmail($email, $token, $firstName);

// New way (recommended)
use App\Services\EmailService;
$emailService = new EmailService($pdo);
$emailService->sendWelcomeEmail($email, $token, $firstName);
```

### 4. Authentication Middleware (`src/Middleware/AuthMiddleware.php`)

**Before:** `requireAuth()` function in config.php
**After:** Dedicated middleware class with additional features

```php
// Old way (still works)
requireAuth();

// New way (recommended)
use App\Middleware\AuthMiddleware;
AuthMiddleware::check();

// Additional features
$userId = AuthMiddleware::getCurrentUserId();
if (AuthMiddleware::isLoggedIn()) { ... }

// CSRF protection
$token = AuthMiddleware::generateCsrfToken();
if (AuthMiddleware::verifyCsrfToken($_POST['csrf_token'])) { ... }
```

### 5. Validation Helper (`src/Helpers/Validator.php`)

**Before:** Manual validation checks scattered throughout forms
**After:** Fluent validation API

```php
// Old way
$errors = [];
if (empty($email)) $errors[] = 'Email is required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';

// New way
use App\Helpers\Validator;

$validator = new Validator($_POST);
$validator->rules([
    'email' => 'required|email|max:255',
    'password' => 'required|min:8',
    'year' => 'required|integer|between:1900,2025'
]);

if ($validator->fails()) {
    $errors = $validator->errors();
}
```

## Migration Guide

### Phase 1: Use New Classes Alongside Legacy Code

The new architecture is designed to work alongside existing code. You can gradually adopt new classes:

```php
<?php
// In your existing PHP files
require_once 'includes/config.php';  // Still needed for constants
requireAuth();                       // Still works

// Add new imports
use App\Models\Vehicle;
use App\Models\ServiceRecord;

// Use new models
$vehicleModel = new Vehicle();
$vehicles = $vehicleModel->getUserVehicles(getCurrentUserId());
```

### Phase 2: Refactor Individual Pages

Start with simpler pages and work towards more complex ones:

1. **Start with:** `vehicles.php`, `service-history.php`
2. **Then:** `add-vehicle.php`, `add-service.php`
3. **Finally:** `index.php`, `expenses.php`, `reports.php`

### Phase 3: Update Cron Jobs

Update cron jobs to use new services:

```php
// cron/service-reminder-cron.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Services\EmailService;
use App\Models\Vehicle;

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);
$vehicleModel = new Vehicle();

// Use new methods
$vehicles = $vehicleModel->getVehiclesNeedingService($userId, 1500);
```

## Autoloading

Add to your `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

Then run:
```bash
composer dump-autoload
```

If not using Composer, manually require the files:

```php
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});
```

## Backward Compatibility

All existing functionality remains intact:
- ✅ `includes/config.php` functions still work
- ✅ `EmailHelper` class still available
- ✅ Session management unchanged
- ✅ Database connection via `getDBConnection()` still works
- ✅ All existing pages continue to function

## Benefits of Refactoring

1. **Separation of Concerns**: Database logic, business logic, and presentation are separated
2. **Testability**: Classes can be unit tested independently
3. **Reusability**: Model methods can be reused across multiple pages
4. **Maintainability**: Changes to database schema only affect model classes
5. **Type Safety**: Type hints catch errors early
6. **IDE Support**: Better autocomplete and navigation
7. **Scalability**: Easy to add new features without modifying existing code

## Next Steps

Consider these future improvements:

1. **Controllers**: Move page logic from PHP files to controller classes
2. **Views**: Separate HTML into template files
3. **Routing**: Implement a simple router for cleaner URLs
4. **Dependency Injection**: Use a container for managing dependencies
5. **Unit Tests**: Add PHPUnit tests for models and services
6. **API**: Create a REST API for mobile app integration

## Support

For questions or issues with the refactored code, please refer to the inline documentation in each class file.
