# AGENTS.md

## Project overview

Rebel Admin v7.1 is a single-file PHP Telegram Bot control panel (`index.php`, ~9300 lines). It provides multi-bot management, a visual flow/page builder, browser automation, and group moderation tools. Data is stored as JSON files on disk (no database required).

## Cursor Cloud specific instructions

### Running the development server

```bash
php -S 0.0.0.0:8080 -t /workspace
```

This starts the PHP built-in development server on port 8080. The app is accessible at `http://localhost:8080`.

### Default credentials

- Username: `admin`
- Password: `admin` (this is the actual default when no `.admin_pass` file or `REBEL_ADMIN_PASS` env var exists)
- Note: The README states `rebel@SecureAdmin#2026` but that is the *recommended* password to set; the code default is just `admin`.

### Lint check

```bash
php -l /workspace/index.php
```

### Testing

There is no automated test suite. Validation is done via:
1. `php -l index.php` — syntax check
2. Manual testing of the admin panel via browser at `http://localhost:8080`
3. The app validates Telegram bot tokens by calling the Telegram API, so adding a real bot requires a valid token from @BotFather.

### Architecture notes

- Single-file monolith: all PHP backend, HTML, CSS, and JS are in `index.php`
- No package manager, no build step, no dependencies to install beyond PHP itself
- Required PHP extensions: `curl`, `json`, `mbstring`, `xml`, `session`, `openssl` (all come with standard PHP 8.3 install)
- Data directories (`bots/`, JSON files) are created automatically at runtime
- Browser automation features (optional) require Python 3 + Playwright or Selenium

### Gotchas

- The login form uses CSRF tokens; API testing via curl requires fetching the login page first to get a session cookie and CSRF token.
- The app returns 302 redirects: unauthenticated requests to `?page=panel` redirect to `?page=login`; successful login redirects to `?page=panel`.
- Rate limiting: 5 failed login attempts lock out the IP for 5 minutes. If locked out during testing, delete `/workspace/.rate_limits.json`.
