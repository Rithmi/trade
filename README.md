# Crypto Trading Bot Dashboard

This project pairs a PHP dashboard (XAMPP-friendly) with the existing Python Binance Futures bot so you can monitor bots, review trades/logs, and start/stop the engine from the browser.

## 1. Prerequisites

- **Windows** with **XAMPP** (Apache + MySQL).
- Python 3.10+ installed and available as `python` in PowerShell.
- `pip install -r public/python-bot/requirements.txt` run **inside** the `public/python-bot/` directory.
- `.env` file in `public/` with at least:
  ```ini
  DB_HOST=127.0.0.1
  DB_NAME=trades
  DB_USER=root
  DB_PASS=
  BASE_URL=/public
  BINANCE_API_KEY=your_key
  BINANCE_API_SECRET=your_secret
  ```
- MySQL schema already containing `users`, `bots`, `bot_state`, `trades`, `bot_logs`, `api_keys` tables (matches the current working bot).

## 2. File Structure Summary

Key pieces involved in the dashboard↔bot integration:

```
public/
├─ dashboard.php               # Live dashboard with stats, bot status, trades, logs
├─ api/
│  ├─ stats.php                # Summary metrics
│  ├─ bot_state.php            # Bot+state table data
│  ├─ trades.php               # Latest trades
│  ├─ logs.php                 # Recent log entries
│  └─ control.php              # Start/stop/status endpoints
├─ bot_control.php             # PowerShell-based process controller
├─ assets/
│  ├─ css/app.css              # Bootstrap-inspired styling
│  └─ js/app.js                # Fetch polling + engine control
└─ python-bot/
   ├─ app/ws_main.py           # Existing websocket bot (unchanged logic)
   └─ bot_status.json          # Stores PID/status for UI
```

## 3. Database & Users

1. Start MySQL from XAMPP and ensure the `trades` database exists.
2. Confirm schema tables contain columns already used by the Python bot (no extra migrations were required for this update).
3. Create at least one user in the `users` table (or register through `public/register.php`).

## 4. Installing Python Dependencies

```powershell
cd C:\xampp\htdocs\trades\public\python-bot
python -m venv venv  # optional but recommended
venv\Scripts\activate
pip install -r requirements.txt
```

## 5. Running the Stack

1. Launch Apache + MySQL in XAMPP Control Panel.
2. Browse to `http://localhost/trades/public/login.php` and log in.
3. Navigate to the dashboard (`dashboard.php`).
4. Use the **Start Bot Engine** button to launch `ws_main.py`. The server calls PowerShell, starts the Python bot in the background, and records its PID in `public/python-bot/bot_status.json`.
5. The dashboard auto-refreshes every 5 seconds to show bot states, latest trades, and logs. You can also click the manual refresh buttons.
6. Click **Stop Bot Engine** to terminate the recorded PID via PowerShell and reset the status file.

> **Manual fallback:** you can still run the bot manually via PowerShell (`python public/python-bot/app/ws_main.py`). The dashboard will show "running" only if the process was started through the control endpoint (so it knows the PID).

## 6. Troubleshooting

- If the Start/Stop buttons fail, ensure `shell_exec` is allowed in PHP (`php.ini`) and PowerShell execution policy allows the commands.
- Make sure `bot_status.json` is writable by Apache/PHP.
- Check `storage/logs` (if configured) or PHP error logs for any failures in the API endpoints.
- Verify the Python environment has access to the same `.env` or environment variables as the PHP side so Binance credentials match.

## 7. Next Steps

- Use the existing `bot_edit.php` page to add/enable bots.
- Expand PHP CRUD endpoints if you need to manage bots entirely via AJAX.
- Add take-profit or safety-order logic inside `binance_ws.py` while preserving the working BUY flow.

This repository now gives you a single dashboard to monitor everything and manage the bot lifecycle without touching the terminal each time.

## 8. Step-by-Step Local Setup (XAMPP)

1. **Install prerequisites**
  - XAMPP (Apache + MySQL) on Windows.
  - Python 3.10 or newer added to your global `PATH`.
  - Ensure `shell_exec` is enabled inside `php.ini` (`disable_functions` should not block it) and PowerShell execution policy allows `Bypass`.

2. **Clone the project**
  - Place the repository under `C:\xampp\htdocs\trades` so Apache can serve the `public/` folder at `http://localhost/trades/public`.

3. **Create the `.env` file (root or `public/`)**
  ```ini
  DB_HOST=127.0.0.1
  DB_NAME=trades
  DB_USER=root
  DB_PASS=
  BASE_URL=/trades/public
  BINANCE_API_KEY=your_live_or_testnet_key
  BINANCE_API_SECRET=your_secret
  BINANCE_TESTNET=true            # false for real futures
  ORDER_EXECUTION_MODE=off        # off | test | live
  ENABLE_ORDER_TEST=false         # true runs a single market-test order at boot
  ```
  - Optional overrides:
    - `PYTHON_BOT_EXE=C:\Python311\python.exe` if Apache cannot find `python` on `PATH`.
    - `BINANCE_INFO_CACHE_SECONDS=300` to tweak exchange-info cache TTL.

4. **Prepare MySQL**
  - Start MySQL from XAMPP.
  - Create the `trades` database and tables (`users`, `bots`, `bot_state`, `bot_logs`, `trades`, `api_keys`).
  - Either import your existing schema or run the SQL from the legacy bot project.

5. **Install the Python environment**
  ```powershell
  cd C:\xampp\htdocs\trades\public\python-bot
  python -m venv venv
  .\venv\Scripts\activate
  pip install -r requirements.txt
  ```
  - The `start_bot.bat` script automatically prefers `venv\Scripts\python.exe` when it exists.

6. **Configure Apache + PHP**
  - In `php.ini`, confirm `extension=mysqli` (or PDO MySQL) is enabled.
  - Restart Apache from XAMPP after editing `php.ini`.

7. **Run database + UI smoke test**
  - Browse to `http://localhost/trades/public/login.php` and log in (register first if needed).
  - Go to `dashboard.php` to confirm stats/feeds render without errors (the revamped `stats.php` / `logs.php` endpoints should now respond).

8. **Start the Python engine from the dashboard**
  - Press **Start Bot Engine**. The PHP API now executes the batch script with safe quoting and reads `bot_status.json` / `bot.pid`.
  - Verify `public/python-bot/bot_status.json` updates (PID + timestamps) and that `bot_logs` receives a “Bot registered” row.
  - Stop the engine with the matching button; the improved stop logic clears stale PID files and syncs status if the process already died.

9. **Verify live data flow**
  - Watch the **Bot Status**, **Trades**, and **Logs** widgets refresh (10s polling). New trades should land in `bot_state`, `bot_logs`, and `trades` tables automatically via the Python `Repository` helpers.
  - Use `api/test_flow.php` → “Run End-to-End Test” to run the automated start → price → trade → stop validation.

10. **Switch to real trading (optional)**
   - Set `BINANCE_TESTNET=false` and `ORDER_EXECUTION_MODE=live` in `.env` when you are ready for live orders.
   - Keep `ENABLE_ORDER_TEST=false` to avoid surprise market orders, or leave it `true` for a single sanity-check trade on startup.

11. **Manual diagnostics**
   - From `public/python-bot/app`, run `..\venv\Scripts\python.exe main.py` to execute the Binance + DB validation script. It now uses the same REST helper as the engine (symbol rules, leverage, margin mode, etc.).
   - Tail `storage/logs` (if configured) or the Apache error log when troubleshooting dashboard/API issues.

Following the steps above ensures Apache, MySQL, the PHP dashboard, and the Python WebSocket engine stay in sync on a single Windows/XAMPP machine.
