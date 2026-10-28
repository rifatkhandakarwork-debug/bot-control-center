<?php
declare(strict_types=1);

/** Telegram long-polling bot. Requires PHP 8.0+ and the cURL extension. */

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($token === '') {
    fwrite(STDERR, "Set TELEGRAM_BOT_TOKEN before starting the bot.\n");
    exit(1);
}

$api = 'https://api.telegram.org/bot' . $token . '/';
$offset = 0;
$sessions = [];
$healthFile = getenv('BOTDECK_HEALTH_FILE') ?: '';
$stopFile = getenv('BOTDECK_STOP_FILE') ?: '';

function writeHealth(string $status, string $lastError = ''): void
{
    global $healthFile;
    if ($healthFile === '') return;
    $path = $healthFile . '.tmp';
    $json = json_encode(['status' => $status, 'updatedAt' => time(), 'lastError' => $lastError], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false && @file_put_contents($path, $json, LOCK_EX) !== false) @rename($path, $healthFile);
}

function logEvent(string $level, string $event, string $message = ''): void
{
    $record = ['time' => date(DATE_ATOM), 'level' => $level, 'event' => $event, 'message' => $message];
    fwrite($level === 'error' || $level === 'warning' ? STDERR : STDOUT, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}

function telegram(string $method, array $data = []): array
{
    global $api;
    $ch = curl_init($api . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Telegram request failed: ' . $error);
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode($response, true);
    if ($status >= 400 || !is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('Telegram API returned an error (HTTP ' . $status . ').');
    }
    return $decoded;
}

function sendMessage(int|string $chatId, string $text): void
{
    telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'protect_content' => true,
    ]);
}

function lookupOnConfiguredSite(string $phone, string $dob): ?string
{
    // TODO: Connect an authorized site/API here once its URL and access method are provided.
    // Keep credentials in environment variables; do not log phone numbers or birth dates.
    return null;
}

logEvent('info', 'bot_started', 'Telegram polling process started.');
writeHealth('starting');

while (true) {
    if ($stopFile !== '' && is_file($stopFile)) {
        writeHealth('stopped');
        logEvent('info', 'bot_stopped', 'Graceful stop requested.');
        break;
    }
    try {
        $result = telegram('getUpdates', [
            'offset' => $offset,
            'timeout' => 25,
            'allowed_updates' => json_encode(['message']),
        ]);
        writeHealth('healthy');

        foreach ($result['result'] as $update) {
            $offset = max($offset, (int)$update['update_id'] + 1);
            $message = $update['message'] ?? null;
            $text = trim((string)($message['text'] ?? ''));
            $chatId = $message['chat']['id'] ?? null;
            if ($chatId === null || $text === '') {
                continue;
            }

            if (str_starts_with($text, '/start')) {
                $sessions[$chatId] = ['step' => 'phone'];
                sendMessage($chatId, "নম্বর ও জন্মতারিখ দিয়ে অনুমোদিত সাইটে খোঁজার বট।\n\nযে নম্বরটি খুঁজতে চান সেটি পাঠান (দেশের কোডসহ, যেমন +8801XXXXXXXXX)। বাতিল করতে /cancel লিখুন।");
                continue;
            }
            if ($text === '/cancel') {
                unset($sessions[$chatId]);
                sendMessage($chatId, 'অনুরোধ বাতিল করা হয়েছে। নতুন করে শুরু করতে /start লিখুন।');
                continue;
            }
            if (!isset($sessions[$chatId])) {
                sendMessage($chatId, 'শুরু করতে /start লিখুন।');
                continue;
            }

            if ($sessions[$chatId]['step'] === 'phone') {
                $digits = preg_replace('/\D+/', '', $text) ?? '';
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    sendMessage($chatId, 'নম্বরটি ঠিক মনে হচ্ছে না। দেশের কোডসহ আবার পাঠান, যেমন +8801XXXXXXXXX।');
                    continue;
                }
                $sessions[$chatId] = ['step' => 'dob', 'phone' => $text];
                sendMessage($chatId, 'এবার জন্মতারিখ পাঠান (DD-MM-YYYY, যেমন 05-09-1990)।');
                continue;
            }

            $dob = DateTimeImmutable::createFromFormat('!d-m-Y', $text);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$dob || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $dob->format('d-m-Y') !== $text) {
                sendMessage($chatId, 'তারিখটি DD-MM-YYYY ফরম্যাটে দিন, যেমন 05-09-1990।');
                continue;
            }

            $phone = $sessions[$chatId]['phone'];
            unset($sessions[$chatId]);
            $answer = lookupOnConfiguredSite($phone, $dob->format('Y-m-d'));
            if ($answer === null) {
                sendMessage($chatId, "নম্বর ও জন্মতারিখ পেয়েছি। সাইট/API সংযোগ এখনো সেট করা হয়নি—সাইটের URL বা API তথ্য দিলে পরের ধাপে যুক্ত করব।\n\nগোপনীয়তার জন্য এই বট অনুরোধটি সংরক্ষণ করে না।");
            } else {
                sendMessage($chatId, $answer);
            }
        }
    } catch (Throwable $e) {
        // Avoid logging submitted personal data or the bot token.
        writeHealth('error', $e->getMessage());
        logEvent('warning', 'telegram_poll_failed', $e->getMessage());
        if ($stopFile !== '' && is_file($stopFile)) continue;
        sleep(3);
    }
}
