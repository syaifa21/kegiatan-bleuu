<?php
// api/telegram_webhook.php

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

if ($chatId) {
    addSubscriberChatId($chatId);
}

function getStatusTextForTelegram($statusKey) {
    switch(strtolower($statusKey ?? '')) {
        case 'belum': return 'Belum Dikerjakan';
        case 'proses': return 'Proses';
        case 'selesai': return 'Selesai';
        default: return 'N/A';
    }
}

$responseText = "Halo {$firstName}! Perintah tidak dikenali. Coba:\n/tugashariini\n/tugasmingguini\n/semuatugas\n/testnotif";

if ($text === '/start') {
    $responseText = "Selamat datang, {$firstName}! Saya adalah bot pengingat tugas Anda.\n";
    $responseText .= "Setiap ada perubahan data tugas, Anda akan mendapat notifikasi.\n";
    $responseText .= "Gunakan perintah berikut:\n";
    $responseText .= "/tugashariini - Melihat tugas dengan tenggat hari ini.\n";
    $responseText .= "/tugasmingguini - Melihat tugas dengan tenggat minggu ini.\n";
    $responseText .= "/semuatugas - Melihat semua tugas yang tersimpan.\n";
    $responseText .= "/testnotif - Mengirim notifikasi tes ke chat ini.\n";
} elseif ($text === '/tugashariini') {
    $tasks = getTasks();
    $today = date('Y-m-d');
    $tasksToday = [];
    foreach ($tasks as $task) {
        if (isset($task['tenggatSortable']) && $task['tenggatSortable'] == $today && $task['status'] !== 'selesai') {
            $tasksToday[] = "- *" . htmlspecialchars($task['nama']) . "* (Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . ", Status: " . getStatusTextForTelegram($task['status']) . ")";
        }
    }
    if (!empty($tasksToday)) {
        $responseText = "Tugas dengan tenggat hari ini (" . date('d M Y') . "):\n" . implode("\n", $tasksToday);
    } else {
        $responseText = "Tidak ada tugas dengan tenggat hari ini (" . date('d M Y') . ") yang belum selesai.";
    }
} elseif ($text === '/tugasmingguini') {
    $tasks = getTasks();
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
            $tasksThisWeek[] = "- *" . htmlspecialchars($task['nama']) . "* (Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . ", Status: " . getStatusTextForTelegram($task['status']) . ")";
        }
    }
    if (!empty($tasksThisWeek)) {
        $responseText = "Tugas dengan tenggat minggu ini ({$startOfWeekFormatted} - {$endOfWeekFormatted}):\n" . implode("\n", $tasksThisWeek);
    } else {
        $responseText = "Tidak ada tugas dengan tenggat minggu ini yang belum selesai.";
    }
} elseif ($text === '/semuatugas') {
    $tasks = getTasks();
    if (empty($tasks)) {
        $responseText = "Saat ini tidak ada tugas yang tersimpan.";
    } else {
        $taskLines = [];
        $responseHeader = "📋 *SEMUA TUGAS ANDA (" . count($tasks) . " total)*:\n";
        foreach ($tasks as $task) {
            $deadlineInfo = (isset($task['tenggatDisplay']) && !empty($task['tenggatDisplay'])) ? "Tenggat: " . htmlspecialchars($task['tenggatDisplay']) : "Tanpa Tenggat";
            $statusText = getStatusTextForTelegram($task['status']);
            $taskLines[] = "- *" . htmlspecialchars($task['nama']) . "*\n  (" . $deadlineInfo . ", Status: " . $statusText . ")";
        }
        $fullMessage = $responseHeader . implode("\n\n", $taskLines);
        if (strlen($fullMessage) > 4000) {
            $responseText = $responseHeader;
            $count = 0;
            foreach ($taskLines as $line) {
                if (strlen($responseText . $line) < 3800) {
                    $responseText .= $line . "\n\n"; $count++;
                } else { break; }
            }
            if ($count < count($taskLines)) { $responseText .= "\nℹ️ ...dan " . (count($taskLines) - $count) . " tugas lainnya (daftar terlalu panjang)."; }
        } else { $responseText = $fullMessage; }
    }
} elseif ($text === '/testnotif') {
    $tasks = getTasks();
    $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $todayDateFormatted = date('d M Y'); $tomorrowDateFormatted = date('d M Y', strtotime('+1 day'));
    $remindersToday = []; $remindersTomorrow = [];
    if (empty($tasks)) { $responseText = "🤖 *NOTIFIKASI TES*\n\nTidak ada tugas untuk notifikasi tes."; }
    else {
        foreach ($tasks as $task) {
            if ($task['status'] === 'selesai') continue;
            if (isset($task['tenggatSortable'])) {
                if ($task['tenggatSortable'] == $today) $remindersToday[] = "- *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")";
                elseif ($task['tenggatSortable'] == $tomorrow) $remindersTomorrow[] = "- *" . htmlspecialchars($task['nama']) . "* (Status: " . getStatusTextForTelegram($task['status']) . ")";
            }
        }
        $testNotificationMessage = "🤖 *NOTIFIKASI TES DIPICU MANUAL*\n_(Untuk " . htmlspecialchars($firstName) . " di chat ini)_\n\n";
        if (!empty($remindersToday)) { $testNotificationMessage .= "🔔 *Tugas Hari Ini* ({$todayDateFormatted}):\n" . implode("\n", $remindersToday) . "\n\n"; }
        else { $testNotificationMessage .= "Tidak ada tugas tenggat hari ini yang belum selesai.\n\n"; }
        if (!empty($remindersTomorrow)) { $testNotificationMessage .= "🗓️ *Tugas Besok* ({$tomorrowDateFormatted}):\n" . implode("\n", $remindersTomorrow) . "\n\n"; }
        else { $testNotificationMessage .= "Tidak ada tugas tenggat besok yang belum selesai.\n\n"; }
        if (empty($remindersToday) && empty($remindersTomorrow)) $testNotificationMessage = "🤖 *NOTIFIKASI TES*\n\nTidak ada tugas perlu diingatkan untuk hari ini/besok untukmu, " . htmlspecialchars($firstName) . ".";
        else $testNotificationMessage .= "Ini adalah tes notifikasi.";
        $responseText = $testNotificationMessage;
    }
}

sendTelegramMessage($BOT_TOKEN, $chatId, $responseText);

http_response_code(200);
echo "Message Processed";
?>