<?php
// File: api/hourly_status_update.php
// Deskripsi: Mengirim update per jam ke Telegram mengenai tugas yang masih 'proses' atau 'belum'.
// Dijalankan oleh CRON JOB setiap jam.

require_once __DIR__ . '/../data_manager_direct.php';
require_once __DIR__ . '/telegram_helpers.php';

date_default_timezone_set('Asia/Jakarta');

$BOT_TOKEN_NOTIF = '7239871766:AAHWW70f_tuYhmFDC5LSgoUfGCv36VPnkVs';
$scriptExecutionTime = date('Y-m-d H:i:s');

try {
    $allMasterTasks = DataManagerDirect::getKerjaanList();
    
    if ($allMasterTasks === null) {
        error_log("HourlyStatusUpdate ($scriptExecutionTime): Gagal memuat tugas dari DataManagerDirect. Keluar.");
        exit("Gagal memuat master tugas.");
    }
    if (empty($allMasterTasks)) {
        error_log("HourlyStatusUpdate ($scriptExecutionTime): Tidak ada master tugas ditemukan. Keluar.");
        exit("Tidak ada master tugas.");
    }

    $tasksProses = [];
    $tasksBelum = [];
    $todayStr = date('Y-m-d');

    foreach ($allMasterTasks as $task) {
        if (isset($task['status']) && $task['status'] !== 'selesai') {
            // Hapus logika perulangan jika ada
            
            if ($task['status'] === 'proses') {
                $tasksProses[] = $task;
            } elseif ($task['status'] === 'belum') {
                $tasksBelum[] = $task;
            }
        }
    }

    if (empty($tasksProses) && empty($tasksBelum)) {
        $messageNoTasks = "🕒 *Update Kerjaan Jam " . date('H:00') . "*\n\n";
        $messageNoTasks .= "👍 Tidak ada tugas yang sedang diproses atau belum dikerjakan saat ini.";
        
        $sendIfEmpty = false;
        if ($sendIfEmpty) {
            $subscriberChatIds = getSubscriberChatIds();
            if (!empty($subscriberChatIds)) {
                foreach ($subscriberChatIds as $chatId) {
                    sendTelegramMessage($BOT_TOKEN_NOTIF, $chatId, $messageNoTasks, "Markdown");
                }
            }
        }
        error_log("HourlyStatusUpdate ($scriptExecutionTime): Tidak ada tugas 'proses' atau 'belum' untuk dilaporkan.");
        exit("Tidak ada tugas proses atau belum untuk dilaporkan.");
    }

    $currentTimeFormatted = date('H:00');
    $message = "🕒 *Update Kerjaan Jam " . $currentTimeFormatted . "* 🕒\n\n";
    $hasContent = false;

    if (!empty($tasksProses)) {
        $hasContent = true;
        $message .= "📝 *Tugas Dalam Proses Saat Ini (" . count($tasksProses) . ")*:\n";
        foreach ($tasksProses as $task) {
            $taskName = htmlspecialchars($task['nama'] ?? 'Tanpa Nama');
            $deadlineText = "";
            if (!empty($task['tenggatDisplay'])) {
                $deadlineText = " (Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . ")";
            }
            // Hapus $recurringText
            $message .= "- " . $taskName . $deadlineText . "\n";
        }
        $message .= "\n";
    }

    if (!empty($tasksBelum)) {
        $hasContent = true;
        $message .= "⏳ *Tugas Belum Dikerjakan (" . count($tasksBelum) . ")*:\n";
        foreach ($tasksBelum as $task) {
            $taskName = htmlspecialchars($task['nama'] ?? 'Tanpa Nama');
            $deadlineText = "";
            if (!empty($task['tenggatDisplay'])) {
                $deadlineText = " (Tenggat: " . htmlspecialchars($task['tenggatDisplay']) . ")";
            }
            // Hapus $recurringText
            $message .= "- " . $taskName . $deadlineText . "\n";
        }
        $message .= "\n";
    }
    
    if (!$hasContent) {
        error_log("HourlyStatusUpdate ($scriptExecutionTime): Tidak ada konten tugas untuk dilaporkan (seharusnya sudah di-handle jika sendIfEmpty=false).");
        exit("Tidak ada konten tugas untuk dilaporkan.");
    }

    $message .= "_Update ini dikirim otomatis setiap jam._";

    $subscriberChatIds = getSubscriberChatIds();
    if (empty($subscriberChatIds)) {
        error_log("HourlyStatusUpdate ($scriptExecutionTime): Tidak ada subscriber untuk dikirimi notifikasi.");
        exit("Tidak ada subscriber.");
    }

    foreach ($subscriberChatIds as $chatId) {
        sendTelegramMessage($BOT_TOKEN_NOTIF, $chatId, $message, "Markdown");
    }
    error_log("HourlyStatusUpdate ($scriptExecutionTime): Pesan update dikirim ke " . count($subscriberChatIds) . " subscriber.");

} catch (Exception $e) {
    error_log("HourlyStatusUpdate ($scriptExecutionTime) Error: " . $e->getMessage());
    exit("Error: " . $e->getMessage());
}

echo "Hourly status update script selesai pada $scriptExecutionTime\n";
?>