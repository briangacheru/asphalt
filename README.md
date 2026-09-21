# iVehicle - Vehicle Service Tracker

A comprehensive web-based vehicle management system for tracking maintenance, services, fuel logs, expenses, and service reminders.

## Features

- **Dashboard**: Overview of vehicle statistics, upcoming services, and recent activity
- **Vehicle Management**: Add, edit, and track multiple vehicles with detailed information
- **Service Records**: Log maintenance and service history with costs and mileage
- **Fuel Log**: Track fuel consumption and mileage between fill-ups (at most 3 records per vehicle per day, minimum 500 per fill-up — see `App\Services\FuelLogRules`)
- **Expense Tracking**: Record and categorize vehicle-related expenses
- **Service Reminders**: Automated email reminders for upcoming maintenance
- **Insurance Tracking**: Record insurance policies and stickers per vehicle, with sticky in-app alerts and daily email reminders starting 14 days before expiry (and continuing daily until renewed)
- **Driving Licence Tracking**: Record driving licence details and a scan per user, with sticky in-app alerts and daily email reminders starting 60 days before expiry (and continuing daily until renewed)
- **Document Expiry Tracking**: Give any uploaded vehicle document (inspection certificate, road tax, permit) an expiry date and get in-app alerts plus a daily per-vehicle digest email starting 14 days before expiry (and continuing daily until the document is replaced or removed)
- **Monthly Reports**: Generate reports on spending and maintenance history
- **Email Notifications**: Automated monthly check emails and service reminders
- **Multi-user Support**: User authentication with registration and password recovery
- **JSON API**: Bearer-token authenticated API under `/api/` for the companion iOS app — see [api/README.md](api/README.md)

## Requirements

- PHP 8.1 or higher
- MySQL 5.7 or higher / MariaDB
- Apache/Nginx web server
- Composer (for dependency management)

## Installation

### 1. Clone the Repository

```bash
git clone <repository-url>
cd vehicle-service-tracker
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Database Setup

Create a MySQL database named `vehicle_service_tracker`:

```sql
CREATE DATABASE vehicle_service_tracker;
```

Import the database schema (if a SQL file exists) or run the installation script.

### 4. Configuration

Create a `.env` file in the project root (read by `App\Helpers\Environment`; every key has a default in `App\Helpers\Config`):

```ini
# Database
DB_HOST=localhost
DB_NAME=vehicle_service_tracker
DB_USER=your_db_user
DB_PASS=your_db_password

# Email (PHPMailer SMTP)
SMTP_HOST=your_smtp_host
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=your_email@example.com
SMTP_PASS=your_email_password
ADMIN_EMAIL=admin@example.com
FROM_EMAIL=noreply@example.com
FROM_NAME=iVehicle

# Application
APP_NAME=iVehicle
APP_URL=http://your-domain.com

# Base64 32-byte key for the encrypted IDs used in URLs
# php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
ID_ENCRYPTION_KEY=

# Optional: Google sign-in and browser push (see sections below)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

### 5. Set Permissions

Ensure the `uploads/` directory is writable:

```bash
chmod -R 755 uploads/
chown -R www-data:www-data uploads/
```

### 6. Web Server Configuration

#### Apache

Ensure mod_rewrite is enabled and create a `.htaccess` file if needed.

#### Nginx

Configure PHP-FPM and ensure proper routing for the application.

## Usage

### Access the Application

Navigate to your configured `APP_URL` in a web browser.

### User Registration

1. Click "Register" on the login page
2. Fill in your details and verify your email
3. Login with your credentials

### Adding a Vehicle

1. Go to "Add Vehicle" from the dashboard
2. Enter vehicle details (make, model, year, VIN, etc.)
3. Upload vehicle photo (optional)
4. Save the vehicle

### Logging Services

1. Select a vehicle from the dashboard
2. Click "Add Service Record"
3. Enter service details, cost, and mileage
4. Set next service interval (mileage or date)

### Setting Up Cron Jobs

For automated email reminders, add these cron jobs:

```bash
# Monthly reminder emails (9:00 AM on the 1st of every month)
0 9 1 * * php /path/to/vehicle-service-tracker/cron/monthly-reminder.php

# Service reminder emails (daily at 8:00 AM)
0 8 * * * php /path/to/vehicle-service-tracker/cron/service-reminder-cron.php

# Insurance expiry alerts (daily at 8:00 AM)
0 8 * * * php /path/to/vehicle-service-tracker/cron/insurance-reminder-cron.php

# Driving licence expiry alerts (daily at 8:00 AM)
0 8 * * * php /path/to/vehicle-service-tracker/cron/driving-license-reminder-cron.php

# Vehicle document expiry alerts (daily at 8:00 AM)
0 8 * * * php /path/to/vehicle-service-tracker/cron/document-expiry-reminder-cron.php

# Maintenance schedule due/overdue alerts (daily at 8:00 AM)
0 8 * * * php /path/to/vehicle-service-tracker/cron/maintenance-reminder-cron.php

# Nightly database backup to uploads/backups/ (2:00 AM)
0 2 * * * php /path/to/vehicle-service-tracker/cron/database-backup-cron.php
```

## Project Structure

```
vehicle-service-tracker/
├── api/                     # JSON API for the iOS app (bearer-token auth)
│   └── index.php            # Front controller — see api/README.md
├── auth/                    # Authentication pages (login, register, password reset)
├── assets/                  # CSS, JavaScript, and image files
├── cron/                    # Scheduled cron job scripts (see "Setting Up Cron Jobs")
├── includes/                # Shared PHP files
│   ├── bootstrap.php        # Session, autoloader, config constants, legacy helper functions
│   ├── header.php           # Common header template (nav, notification bell)
│   └── footer.php           # Common footer template (quick-add FAB, push/service-worker helper)
├── src/                     # PSR-4 classes (App namespace)
│   ├── Api/                 # JSON API controllers, Response and upload helpers
│   ├── Database/            # PDO singleton
│   ├── Helpers/             # Config, Environment (.env), IdCodec (encrypted URL ids)
│   ├── Middleware/          # Session auth and bearer-token auth
│   ├── Models/              # Base Model and Vehicle model (used by the API)
│   └── Services/            # Email, insurance, licence, push, backup, search, etc.
├── tests/                   # PHPUnit tests (vendor/bin/phpunit)
├── uploads/                 # User uploaded files (vehicle photos, documents)
├── vendor/                  # Composer dependencies
├── vendors/                 # Additional vendor libraries
├── index.php                # Dashboard
├── vehicles.php             # Vehicle list management
├── add-vehicle.php          # Add new vehicle form
├── edit-vehicle.php         # Edit vehicle details
├── vehicle-details.php      # View single vehicle details
├── service-history.php      # Service record history
├── add-service.php          # Add service record form
├── maintenance-schedule.php # View and manage maintenance schedules
├── fuel-log.php             # Fuel consumption tracking
├── expenses.php             # Expense tracking and management
├── reports.php              # Generate and view reports
├── settings.php             # User settings and preferences
└── service-items.php        # Manage service item templates
```

## Database Schema

The application uses the following main tables:

- `users` - User accounts and authentication
- `vehicles` - Vehicle information and details
- `service_records` - Maintenance and service history
- `fuel_logs` - Fuel consumption records
- `expenses` - Vehicle expense tracking
- `service_items` - Predefined service item templates
- `email_logs` - Email notification history
- `vehicle_documents` - Uploaded documents & photos per vehicle (insurance, bill of lading, receipts, etc.); created automatically on first request, see `includes/header.php`
- `vehicle_insurance` - Insurance policies per vehicle (provider, policy number, coverage, premium, expiry date, sticker upload); created lazily by `App\Services\InsuranceService`. The record with the furthest-out `expiry_date` is a vehicle's "current" policy — see the Insurance page and `cron/insurance-reminder-cron.php`.
- `driving_licenses` - Driving licence details per user (licence number, national ID, DOB, sex, blood group, county, expiry date, scan upload); created lazily by `App\Services\DrivingLicenseService`. The record with the furthest-out `expiry_date` is a user's "current" licence — see the Driving Licence page and `cron/driving-license-reminder-cron.php`.
- `api_tokens` - Bearer tokens for the JSON API (one row per logged-in device; only a SHA-256 hash is stored); created lazily by `App\Services\ApiTokenService`. See [api/README.md](api/README.md).
- `push_subscriptions` - Browser push subscriptions (one row per device a user enabled notifications on in Settings); created lazily by `App\Services\PushSubscriptionService`. Sent to alongside the reminder emails — see `App\Services\PushService`.
- `feedback` - In-app feedback submitted via the user menu's "Feedback" link; created lazily by `App\Services\FeedbackService`. Admins read, filter and resolve submissions in the "User Feedback" card on the Admin Dashboard.
- `vehicle_documents.expiry_date` - Optional expiry date on an uploaded document (inspection certificate, road tax, etc.), added lazily to the existing table by `App\Services\DocumentExpiryService`. Feeds the same header bell as insurance/licence expiry, and `cron/document-expiry-reminder-cron.php` sends one digest email per vehicle per day while any of its documents is within 14 days of expiry or already expired.
- `mileage_log.photo_path` - Optional odometer photo captured/uploaded on the Update Mileage page, added lazily the same defensive way.

## Browser Push Notifications

Service/insurance/licence/document reminders can also be delivered as browser push notifications, as a second channel alongside the existing emails. This is optional — the app works fine without it, and each user opts in individually from Settings > Push Notifications.

To enable it, generate a VAPID key pair and add it to `.env`:

```bash
php -r "require 'vendor/autoload.php'; print_r((new Minishlink\WebPush\VAPID)::createVapidKeys());"
```

```
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
```

The app also ships a `manifest.json` and `sw.js` so it can be installed to a phone's home screen ("Add to Home Screen"/"Install app") independent of whether push is configured.

## Security Features

- Password hashing with bcrypt
- Session-based authentication
- CSRF protection
- Input validation and sanitization
- Prepared statements for database queries (SQL injection prevention)
- File upload validation

## Email Templates

All emails are built inline by `App\Services\EmailService` (a shared HTML wrapper plus per-message bodies). There are no separate template files.

## License

[Specify your license here]

## Support

For issues and feature requests, please open an issue in the repository.

## Credits

- Built with PHP and MySQL
- Uses PHPMailer for email functionality
- Frontend: Bootstrap 5 via the Falcon admin theme (`assets/css/theme.css`, `assets/js/theme.js`), Font Awesome, ECharts
