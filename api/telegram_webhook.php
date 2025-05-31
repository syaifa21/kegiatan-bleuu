<?php
// api/telegram_webhook.php

// Pastikan session_start() tidak dipanggil di sini jika ini webhook (bukan dari browser)
// Webhook menerima data POST dari Telegram, tidak ada sesi yang perlu dimulai.

require_once 'telegram_helpers.php';

$BOT_TOKEN = '7239871766:AAHWW70f_tuYhmFDC5LSgoUfGCv36VPnkVs';

$update = file_get_contents('php://input');
if (!$update) { error_log("No input received for webhook."); exit; }

$updateArray = json_decode($update, true);
if (!$updateArray || !isset($updateArray['message'])) { error_log("Invalid update or no message object: " . $update); exit; }

$message = $updateArray['message'];
$chatId = $message['chat']['id'];
$text = trim($message['text'] ?? '');
$firstName = $message['from']['first_name'] ?? 'Pengguna';
$telegramUserId = $message['from']['id'] ?? null; // ID user Telegram

if ($chatId) {
    addSubscriberChatId($chatId);
}

// Default response
$responseText = "🤖 Halo, *{$firstName}*! Perintah tidak dikenali. Coba:\n";
$responseText .= "  › /start ✨ _Mulai bot_ \n";
$responseText .= "  › /tugashariini 🗓️ _Tugas dengan tenggat hari ini_\n";
$responseText .= "  › /tugasmingguini 📅 _Tugas dengan tenggat minggu ini_\n";
$responseText .= "  › /semuatugas 📋 _Semua tugas yang ada_\n";
$responseText .= "  › /testnotif 🔔 _Kirim notifikasi tes_\n";
$responseText = createFancyBorder($responseText);


if ($text === '/start') {
    $welcomeMessage = "👋 Selamat datang, *{$firstName}* di _Dashboard Kerjaan Bleuu_!\n";
    $welcomeMessage .= "Saya adalah asisten pribadi Anda untuk mengingatkan tugas-tugas.\n";
    $welcomeMessage .= "Setiap ada perubahan tugas dari aplikasi web, Anda akan mendapat notifikasi instan di sini.\n\n";
    $welcomeMessage .= "✨ *Perintah yang Bisa Digunakan*:\n";
    $welcomeMessage .= "  › /tugashariini 🗓️ _Melihat tugas dengan tenggat hari ini._\n";
    $welcomeMessage .= "  › /tugasmingguini 📅 _Melihat tugas dengan tenggat minggu ini._\n";
    $welcomeMessage .= "  › /semuatugas 📋 _Melihat semua tugas yang tersimpan._\n";
    $welcomeMessage .= "  › /testnotif 🔔 _Mengirim notifikasi tes ke chat ini._\n\n";
    $welcomeMessage .= "_Mari kelola kerjaanmu dengan lebih mudah!_ 💪";
    $responseText = createFancyBorder($welcomeMessage);
} elseif ($text === '/tugashariini') {
    // Ambil semua tugas, karena tidak ada user_id spesifik dari Telegram chat_id
    $tasks = getTasksFromDb(); 
    $today = date('Y-m-d');
    $tasksToday = [];

    foreach ($tasks as $task) {
        if (isset($task['tenggatSortable']) && $task['tenggatSortable'] == $today && $task['status'] !== 'selesai') {
            $userName = getUsernameById($task['user_id'] ?? null); // Ambil nama pengguna tugas ini
            $tasksToday[] = "» *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
        }
    }
    if (!empty($tasksToday)) {
        $header = "🗓️ *TUGAS HARI INI* (" . date('d M Y') . ")\n";
        $responseText = $header . implode("\n\n", $tasksToday);
        $responseText = createFancyBorder($responseText);
    } else {
        $responseText = createFancyBorder("🥳 Tidak ada tugas dengan tenggat hari ini (" . date('d M Y') . ") yang belum selesai.");
    }
} elseif ($text === '/tugasmingguini') {
    $tasks = getTasksFromDb(); // Ambil semua tugas
    $todayDt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $startOfWeek = (clone $todayDt)->modify('monday this week')->format('Y-m-d');
    $endOfWeek = (clone $todayDt)->modify('sunday this week')->format('Y-m-d');
    $startOfWeekFormatted = (clone $todayDt)->modify('monday this week')->format('d M');
    $endOfWeekFormatted = (clone $todayDt)->modify('sunday this week')->format('d M Y');
    $tasksThisWeek = [];

    foreach ($tasks as $task) {
        if (isset($task['tenggatSortable']) &&
            $task['tenggatSortable'] >= $startOfWeek &&
            $task['tenggatSortable'] <= $endOfWeek &&
            $task['status'] !== 'selesai') {
            $userName = getUsernameById($task['user_id'] ?? null); // Ambil nama pengguna tugas ini
            $tasksThisWeek[] = "» *" . htmlspecialchars($task['nama']) . "* (Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . ", Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
        }
    }
    if (!empty($tasksThisWeek)) {
        $header = "📅 *TUGAS MINGGU INI* ({$startOfWeekFormatted} - {$endOfWeekFormatted})\n";
        $responseText = $header . implode("\n\n", $tasksThisWeek);
        $responseText = createFancyBorder($responseText);
    } else {
        $responseText = createFancyBorder("🥳 Tidak ada tugas dengan tenggat minggu ini yang belum selesai.");
    }
} elseif ($text === '/semuatugas') {
    $tasks = getTasksFromDb(); // Ambil semua tugas
    if (empty($tasks)) {
        $responseText = createFancyBorder("🤔 Saat ini tidak ada tugas yang tersimpan.");
    } else {
        $taskLines = [];
        $responseHeader = "📋 *SEMUA TUGAS ANDA (" . count($tasks) . " total)*:\n";
        foreach ($tasks as $task) {
            $deadlineInfo = (isset($task['tenggatDisplay']) && !empty($task['tenggatDisplay'])) ? "Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . " ⏰" : "Tanpa Tenggat 🆓";
            $statusText = getStatusTextForTelegram($task['status']);
            $userName = getUsernameById($task['user_id'] ?? null); // Ambil nama pengguna tugas ini
            $taskLines[] = "» *" . htmlspecialchars($task['nama']) . "*\n  _Status_: {$statusText}\n  _Info_: {$deadlineInfo}\n  _Pemilik_: {$userName}_";
        }
        $fullMessage = $responseHeader . implode("\n\n", $taskLines);
        if (mb_strlen($fullMessage, 'UTF-8') > 4000) {
            $truncatedMessage = $responseHeader;
            $count = 0;
            foreach ($taskLines as $line) {
                if (mb_strlen($truncatedMessage . $line, 'UTF-8') < 3800) {
                    $truncatedMessage .= $line . "\n\n"; $count++;
                } else { break; }
            }
            if ($count < count($taskLines)) { $truncatedMessage .= "\nℹ️ ...dan " . (count($taskLines) - $count) . " tugas lainnya (daftar terlalu panjang untuk ditampilkan semua)."; }
            $responseText = createFancyBorder($truncatedMessage);
        } else { $responseText = createFancyBorder($fullMessage); }
    }
} elseif ($text === '/testnotif') {
    $tasks = getTasksFromDb(); // Ambil semua tugas
    $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $todayDateFormatted = date('d M Y'); $tomorrowDateFormatted = date('d M Y', strtotime('+1 day'));
    $remindersToday = []; $remindersTomorrow = [];

    $testNotificationMessage = "🔔 *NOTIFIKASI TES* 🔔\n";
    $testNotificationMessage .= "_Untuk {$firstName} di chat ini_\n\n";

    if (empty($tasks)) {
        $testNotificationMessage .= "Ops! Tidak ada tugas sama sekali yang tersimpan.\n\n";
    } else {
        foreach ($tasks as $task) {
            if ($task['status'] === 'selesai') continue;
            $userName = getUsernameById($task['user_id'] ?? null); // Ambil nama pengguna tugas ini

            if (isset($task['tenggatSortable'])) {
                if ($task['tenggatSortable'] == $today) $remindersToday[] = "» *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
                elseif ($task['tenggatSortable'] == $tomorrow) $remindersTomorrow[] = "» *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
            }
        }

        if (!empty($remindersToday)) { $testNotificationMessage .= "🗓️ *Tugas Tenggat Hari Ini* ({$todayDateFormatted}):\n" . implode("\n\n", $remindersToday) . "\n\n"; }
        else { $testNotificationMessage .= "Tidak ada tugas tenggat hari ini yang belum selesai.\n\n"; }
        if (!empty($remindersTomorrow)) { $testNotificationMessage .= "✨ *Tugas Tenggat Besok* ({$tomorrowDateFormatted}):\n" . implode("\n\n", $remindersTomorrow) . "\n\n"; }
        else { $testNotificationMessage .= "Tidak ada tugas tenggat besok yang belum selesai.\n\n"; }
    }
    
    $testNotificationMessage .= "_Ini adalah tes notifikasi dari sistem._";
    $responseText = createFancyBorder($testNotificationMessage);
}

sendTelegramMessage($BOT_TOKEN, $chatId, $responseText);

http_response_code(200);
echo "Message Processed";