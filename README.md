# iVehicle - Vehicle Service Tracker

A comprehensive web-based vehicle management system for tracking maintenance, services, fuel logs, expenses, and service reminders.

## Features

- **Dashboard**: Overview of vehicle statistics, upcoming services, and recent activity
- **Vehicle Management**: Add, edit, and track multiple vehicles with detailed information
- **Service Records**: Log maintenance and service history with costs and mileage
- **Fuel Log**: Track fuel consumption and mileage between fill-ups
- **Expense Tracking**: Record and categorize vehicle-related expenses
- **Service Reminders**: Automated email reminders for upcoming maintenance
- **Monthly Reports**: Generate reports on spending and maintenance history
- **Email Notifications**: Automated monthly check emails and service reminders
- **Multi-user Support**: User authentication with registration and password recovery

## Requirements

- PHP 7.4 or higher
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

Edit `includes/config.php` to match your environment:

```php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'vehicle_service_tracker');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Email Configuration (PHPMailer SMTP)
define('SMTP_HOST', 'your_smtp_host');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', 'your_email@example.com');
define('SMTP_PASS', 'your_email_password');
define('ADMIN_EMAIL', 'admin@example.com');
define('FROM_EMAIL', 'noreply@example.com');
define('FROM_NAME', 'iVehicle');

// Application Settings
define('APP_NAME', 'iVehicle');
define('APP_URL', 'http://your-domain.com');
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
```

## Project Structure

```
vehicle-service-tracker/
├── auth/                    # Authentication pages (login, register, password reset)
├── assets/                  # CSS, JavaScript, and image files
├── cron/                    # Scheduled cron job scripts
│   ├── monthly-reminder.php
│   └── service-reminder-cron.php
├── includes/                # Shared PHP files and configurations
│   ├── config.php           # Application configuration
│   ├── EmailHelper.php      # Email sending utilities
│   ├── header.php           # Common header template
│   └── footer.php           # Common footer template
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

## Security Features

- Password hashing with bcrypt
- Session-based authentication
- CSRF protection
- Input validation and sanitization
- Prepared statements for database queries (SQL injection prevention)
- File upload validation

## Email Templates

HTML email templates are located in the `auth/` directory:
- `confirm-mail.html` - Email verification
- `lock-screen.html` - Account lock notification
- `reset-password.html` - Password reset instructions

## License

[Specify your license here]

## Support

For issues and feature requests, please open an issue in the repository.

## Credits

- Built with PHP and MySQL
- Uses PHPMailer for email functionality
- Frontend framework: [Specify if known]
