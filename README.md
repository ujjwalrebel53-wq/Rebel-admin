# Rebel Admin — v7.1 (Secure Edition)

A self-hosted Telegram Bot control panel with multi-bot management, flow builder, and browser automation.

---

## Security Features

| Feature | Details |
|---|---|
| **Bcrypt password hashing** | Admin password stored as bcrypt hash (cost 12), never plain-text |
| **Brute-force protection** | Login rate-limited: 5 failed attempts → 5-minute lockout per IP |
| **CSRF protection** | All admin API calls require a CSRF token (via hidden form field + JS header) |
| **Webhook secret token** | Telegram webhook validated via `X-Telegram-Bot-Api-Secret-Token` |
| **Security headers** | `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy` |
| **SSL verification** | All outbound cURL calls to Telegram API use `CURLOPT_SSL_VERIFYPEER=true` |
| **Secure file permissions** | Bot data directories created with `0755` instead of `0777` |
| **Session hardening** | Session ID regenerated on login; `session_unset()` on logout |

### Default Admin Credentials

- **Username:** `admin`
- **Password:** `rebel@SecureAdmin#2026`  ← **Change immediately!**

### Changing Admin Password

**Option 1 — From Admin Panel:**  
Go to `⚙️ Bot Config & Security` → scroll to **Security** card → enter current + new password.

**Option 2 — Via Environment Variable:**  
```bash
# Plain text (takes priority over hash)
export REBEL_ADMIN_PASS="your-new-password"

# OR bcrypt hash
export REBEL_ADMIN_HASH='$2y$12$...'
```

**Option 3 — Via `.admin_hash` file:**  
The panel writes a `.admin_hash` file when you change password. This persists across restarts.

---

## Browser Automation (Site Automator)

The **Flow Builder** supports a `Browser (Selenium/Playwright)` page type that lets you automate any website directly from Telegram commands. The **Link Automation** section also supports full browser mode with the same step engine.

### Anti-Bot / Stealth Features

- Realistic Chrome user-agent (`Chrome/124`)
- `navigator.webdriver` property hidden via init script
- `AutomationControlled` flag disabled
- Indian locale (`en-IN`) and timezone (`Asia/Kolkata`) set
- Playwright context created with viewport 1920×1080

### Auto-detected Drivers

1. **Playwright** (preferred) — installed via `pip install playwright && playwright install chromium`
2. **Selenium** — installed via `pip install selenium` with Chrome/Chromium

### Available Steps

| Step | Description |
|---|---|
| 🌐 Open URL | Navigate to URL (tries `networkidle` → `domcontentloaded` → `load`) |
| ⌚ Wait Load | Explicit page load state wait (`networkidle` / `domcontentloaded` / `load`) |
| 👆 Click | Click element by CSS/XPath selector or X,Y coordinates |
| 👆👆 Double Click | Double-click element |
| 🖱 Right Click | Context-menu click |
| ⌨️ Fill Input | Clear + type into field |
| ⌨️ Type Slow | Human-like typing with configurable delay per character |
| 🗑 Clear Field | Clear an input field |
| 📸 Screenshot | Capture & optionally send to user |
| 🔐 Ask Captcha | Pause, show captcha screenshot, resume after user reply |
| ⏱ Wait | Sleep N seconds |
| ⌛ Wait Element | Wait for element to appear (CSS or XPath) |
| ⌛ Wait URL | Wait until URL contains a string |
| ↕️ Scroll | Scroll page by pixels |
| 📋 Get Text→Var | Extract element text to variable |
| 🔗 Get Attribute→Var | Extract element attribute (href, src, value…) |
| ⚡ JS Evaluate→Var | Run JavaScript and save result |
| ✅ Assert Text | Fail if element doesn't contain expected text |
| ⌨️ Key Press | Press Enter, Tab, Escape, etc. |
| 📋 Select Option | Select dropdown option |
| 🖱 Hover | Hover over element |
| ↔️ Drag & Drop | Drag element to target |
| 📁 Upload File | Set file input path |
| 🖼 Switch to IFrame | Enter an iframe context (Playwright FrameLocator) |
| 🖼 Switch to Main Frame | Return to main page |
| 🍪 Set Cookie | Set cookie name=value |
| 🍪 Get Cookie→Var | Read cookie value to variable |
| 📦 Set Var | Set a variable to a fixed value |
| 🎲 Random from List | Pick a random item from comma list |
| 🔄 Reload | Reload the current page |
| ⚡ Raw Python | Execute arbitrary Python (P/PAGE = playwright page, B/BROWSER = selenium driver) |

### Quick Templates

Click a template button to pre-fill common flows:
- **🔑 Login Flow** — open URL, wait for field, fill credentials, click submit, wait for redirect
- **📋 Form Fill** — fill name/email/phone, submit, screenshot
- **📊 Data Scrape** — extract title, price, link, item count via JS
- **📝 Sign Up** — register form with password confirm

### UIDAI / MyAadhaar Templates (Link Automation → Browser Mode)

Three ready-made flows available via the **UIDAI Templates** buttons in Link Automation → Browser Mode:

| Template | Description |
|---|---|
| 🆔 Verify Aadhaar | Opens `myaadhaar.uidai.gov.in/verifyAadhaar`, fills Aadhaar number from user input (`{query}`), asks captcha, submits, returns result |
| 📋 Aadhaar Status | Opens `myaadhaar.uidai.gov.in/CheckAadhaarStatus`, fills EID from `{query}`, asks captcha, returns status |
| 🔒 Lock/Unlock UID | Opens `myaadhaar.uidai.gov.in/lock-unlock-uid`, fills Aadhaar, asks captcha, sends OTP |

**Setup:**
1. Go to **Link Automation** → Enable → **+ Add Rule**
2. Set **Trigger Keyword** (e.g. `verify`) and **Trigger Mode** = `startswith` for commands like `verify 123412341234`
3. Enable **Browser Mode**
4. Click the desired **UIDAI Template** button
5. Adjust selectors if needed (UIDAI may update their page)
6. Set **Reply Template** to show `{result}` to the user
7. Save

> **Note:** UIDAI's portal is a JavaScript-heavy Angular app. The automation uses `networkidle` wait and anti-detection flags. If selectors change, use **Raw Python** step or update CSS selectors manually.

### Variable System

- `{var1}` `{var2}` — positional command arguments
- `{mail}` `{pass}` — named vars (set in "Variable Names" field)
- `{tg_name}` `{tg_id}` `{tg_username}` — Telegram user info
- `{random:MYVAR}` — pick random item from comma-separated `{MYVAR}`
- `{result}` `{anyvar}` — values set by Get Text / Get Attr / JS Eval steps
- API key names from the vault: `{MYAPI_KEY}`

### Link Automation — Additional Variables

- `{query}` — full message text sent by user
- `{query_arg}` — text after the trigger keyword (for `startswith` mode, e.g. trigger=`verify`, user sends `verify 123456789012` → `{query_arg}` = `123456789012`)
- `{tg_name}` `{tg_id}` `{tg_username}` — same Telegram user variables

---

## Deployment

1. Point web server at `/workspace` with `index.php` as front controller
2. Ensure HTTPS is configured (required for Telegram webhooks)
3. Change the default admin password immediately
4. Set `REBEL_ADMIN_PASS` or `REBEL_ADMIN_HASH` environment variables for production
