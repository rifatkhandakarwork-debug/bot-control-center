@echo off
cd /d "%~dp0"
where node >nul 2>nul || (echo Node.js is not installed or not in PATH. Install Node.js LTS, then reopen this window.& pause & exit /b 1)
if not exist node_modules\playwright (call npm install || (pause & exit /b 1))
node playwright-server.js
pause

