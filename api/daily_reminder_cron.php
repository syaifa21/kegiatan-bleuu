<?php
// api/daily_reminder_cron.php
// Skrip ini dijalankan oleh CRON JOB di server Anda

require_once 'telegram_helpers.php';

error_log("Daily reminder script started at " . date('Y-m-d H:i:s'));

$tasks = getTasks();
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

    if (isset($task['tenggatSortable'])) {
        if ($task['tenggatSortable'] == $today) {
            $remindersToday[] = "- *" . htmlspecialchars($task['nama']) . "* (Status: " . htmlspecialchars(ucfirst($task['status'])) . ")";
        } elseif ($task['tenggatSortable'] == $tomorrow) {
            $remindersTomorrow[] = "- *" . htmlspecialchars($task['nama']) . "* (Status: " . htmlspecialchars(ucfirst($task['status'])) . ")";
        }
    }
}

$messageToSend = "";

if (!empty($remindersToday)) {
    $messageToSend .= "🔔 *PENGINGAT TUGAS HARI INI* (" . date('d M Y') . "):\n";
    $messageToSend .= implode("\n", $remindersToday);
    $messageToSend .= "\n\n";
}

if (!empty($remindersTomorrow)) {
    $messageToSend .= "🗓️ *TUGAS UNTUK BESOK* (" . date('d M Y', strtotime('+1 day')) . "):\n";
    $messageToSend .= implode("\n", $remindersTomorrow);
    $messageToSend .= "\n\n";
}

// Periksa apakah $TARGET_CHAT_ID telah didefinisikan atau perlu diambil dari suatu tempat
// Jika belum, Anda harus mendefinisikannya di sini atau mengambilnya dari `getSubscriberChatIds()`
// Untuk cron job, umumnya ada satu TARGET_CHAT_ID atau diambil dari daftar subscriber.
// Menggunakan getSubscriberChatIds() untuk mengirim ke semua pelanggan.
$subscriberChatIds = getSubscriberChatIds();

if (!empty($messageToSend)) {
    if (!empty($subscriberChatIds)) {
        $messageToSend .= "Semangat mengerjakan! 💪";
        foreach ($subscriberChatIds as $chatId) {
            sendTelegramMessage($BOT_TOKEN, $chatId, $messageToSend);
            error_log("Reminder sent to chat_id: {$chatId}");
        }
    } else {
        error_log("No subscribers found to send daily reminder.");
    }
} else {
    if (!empty($subscriberChatIds)) {
        foreach ($subscriberChatIds as $chatId) {
             error_log("No reminders to send today for chat_id: {$chatId}");
        }
    } else {
         error_log("No reminders to send today and no subscribers found.");
    }
}

echo "Daily reminder process finished.\n";
?>