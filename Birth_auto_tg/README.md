# Birth Auto Telegram

This PHP 8+ Telegram polling bot asks for a phone number and date of birth in a private chat. The lookup function is a placeholder until an authorized site/API and access method are provided. Submitted details stay in the process memory during the conversation and are not written to disk.

## Run from Bot Manager

Open `http://localhost/all_bot/`, choose **Start**, and enter the BotFather token. The token is passed to the PHP process environment and is not written to a file or included in the PowerShell command line. The dashboard reports the PHP process separately from polling health. A successful `getUpdates` response updates the heartbeat; API errors appear in Logs and in the health status.

Stopping first creates a local stop flag. The bot exits after its current Telegram long poll completes (up to 25 seconds), with a force-stop fallback after the controller's grace period.

## Run manually

Double-click `start_bot.bat`, paste the token, and press Ctrl+C in that window to stop. Requires PHP 8+ with cURL enabled. The launcher checks `D:\Xampp\php\php.exe` then `C:\xampp\php\php.exe`.

The bot accepts phone numbers with 7–15 digits and dates in `DD-MM-YYYY` format. It reports that site lookup is pending until the authorized integration details are configured in `lookupOnConfiguredSite()` in `bot.php`.
