<?php
// api/daily_reminder_cron.php
// Skrip ini dijalankan oleh CRON JOB di server Anda

require_once 'telegram_helpers.php';

error_log("Daily reminder script started at " . date('Y-m-d H:i:s'));

$tasks = getTasksFromDb(); // Mengambil semua tugas dari database

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$remindersToday = [];
$remindersTomorrow = [];

if (empty($tasks)) {
    error_log("No tasks found for daily reminder.");
    exit;
}

foreach ($tasks as $task) {
    if ($task['status'] === 'selesai') {
        continue;
    }
    $userName = getUsernameById($task['user_id'] ?? null); // Ambil nama pengguna tugas ini

    if (isset($task['tenggatSortable'])) {
        if ($task['tenggatSortable'] == $today) {
            $remindersToday[] = "» *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
        } elseif ($task['tenggatSortable'] == $tomorrow) {
            $remindersTomorrow[] = "» *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")\n   _Pemilik: {$userName}_";
        }
    }
}

$messageToSend = "";

if (!empty($remindersToday)) {
    $messageToSend .= "🗓️ *PENGINGAT TUGAS HARI INI* (" . date('d M Y') . "):\n";
    $messageToSend .= implode("\n\n", $remindersToday);
    $messageToSend .= "\n\n";
}

if (!empty($remindersTomorrow)) {
    $messageToSend .= "✨ *TUGAS UNTUK BESOK* (" . date('d M Y', strtotime('+1 day')) . "):\n";
    $messageToSend .= implode("\n\n", $remindersTomorrow);
    $messageToSend .= "\n\n";
}

$subscriberChatIds = getSubscriberChatIds();

if (!empty($messageToSend)) {
    if (!empty($subscriberChatIds)) {
        $messageToSend .= "_Semangat mengerjakan!_ 💪";
        // Tambahkan bingkai ke pesan keseluruhan
        $framedMessage = createFancyBorder($messageToSend, 50); // Sesuaikan lebar jika perlu

        foreach ($subscriberChatIds as $chatId) {
            sendTelegramMessage($BOT_TOKEN, $chatId, $framedMessage);
            error_log("Reminder sent to chat_id: {$chatId}");
        }
    } else {
        error_log("No subscribers found to send daily reminder.");
    }
} else {
    if (!empty($subscriberChatIds)) {
        foreach ($subscriberChatIds as $chatId) {
             error_log("No reminders to send today for chat_id: {$chatId}");
             // Kirim pesan "Tidak ada tugas" jika diinginkan
             // sendTelegramMessage($BOT_TOKEN, $chatId, createFancyBorder("🥳 Tidak ada tugas yang perlu diingatkan untuk hari ini atau besok."));
        }
    } else {
         error_log("No reminders to send today and no subscribers found.");
    }
}

echo "Daily reminder process finished.\n";