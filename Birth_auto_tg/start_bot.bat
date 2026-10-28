@echo off
setlocal
title Telegram Birth Lookup Bot

set "PHP_EXE=D:\Xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" (
  echo Could not find php.exe in D:\Xampp\php or C:\xampp\php.
  echo Install or locate XAMPP, then update PHP_EXE in this file if needed.
  pause
  exit /b 1
)

echo Paste your Telegram bot token below. It will only be kept in this window while the bot runs.
set /p "TELEGRAM_BOT_TOKEN=Bot token: "
if not defined TELEGRAM_BOT_TOKEN (
  echo No token entered.
  pause
  exit /b 1
)

cd /d "%~dp0"
"%PHP_EXE%" bot.php
echo.
echo Bot stopped.
pause
