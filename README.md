# public_html
Blessmall

## Private Hosting Configuration

`includes/config.php` loads `blessmall-private.php` from one directory above this project directory, then reads the following environment variables as fallbacks:

- `BLESSMALL_DB_HOST`
- `BLESSMALL_DB_USER`
- `BLESSMALL_DB_PASS`
- `BLESSMALL_DB_NAME`
- `BLESSMALL_PAYSTACK_SECRET_KEY`
- `BLESSMALL_SMS_API_KEY`
- `BLESSMALL_SMS_SENDER_ID`
- `BLESSMALL_DISPLAY_ERRORS` (set to `1` only for temporary debugging)

On StormerHost, place `blessmall-private.php` outside the web root where possible. Define only the values you have configured, for example:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_rotated_database_password');
define('DB_NAME', 'your_database_name');
define('PAYSTACK_SECRET_KEY', 'your_rotated_paystack_secret_key');
define('SMS_API_KEY', 'your_rotated_sms_api_key');
define('SMS_SENDER_ID', 'BLESSMALLGH');
```

Do not commit real credentials or put this file under the public web root. Rotate any credentials previously present in the source before configuring production.
