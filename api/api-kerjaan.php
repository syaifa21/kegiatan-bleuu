<?php
// api/api-kerjaan.php
// API ini melakukan operasi CRUD langsung pada file tasks.json.
// Sinkronisasi ke DB akan dipicu secara asynchronous DAN oleh Cron Job terpisah.

header('Content-Type: application/json');
require_once __DIR__ . '/telegram_helpers.php'; // Digunakan untuk notifikasi Telegram

$BOT_TOKEN_NOTIF = '7239871766:AAHWW70f_tuYhmFDC5LSgoUfGCv36VPnkVs';
$tasksFile = __DIR__ . '/../data/tasks.json';
$syncTriggerFile = __DIR__ . '/../data/sync_trigger.json'; // File trigger baru

// Pastikan direktori data ada
if (!file_exists(dirname($tasksFile))) {
    mkdir(dirname($tasksFile), 0755, true);
}
// Pastikan file sync_trigger ada
if (!file_exists($syncTriggerFile)) {
    file_put_contents($syncTriggerFile, json_encode(["last_json_modified" => 0, "last_sync_completed" => 0]));
}


$response = ['status' => 'error', 'message' => 'Terjadi kesalahan yang tidak diketahui.'];

// Fungsi bantu untuk membaca/menulis JSON (sama seperti sebelumnya)
function readTasksJson($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $jsonContent = file_get_contents($filePath);
    if ($jsonContent === false) {
        throw new Exception('Gagal membaca file data tugas.');
    }
    $tasks = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE && trim($jsonContent) !== '') {
        throw new Exception('Gagal mem-parse format data tugas: ' . json_last_error_msg());
    }
    return $tasks ?: [];
}

function writeTasksJson($filePath, $tasksArray) {
    $jsonDataToSave = json_encode(array_values($tasksArray), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($jsonDataToSave === false) {
        throw new Exception('Gagal meng-encode data tugas ke JSON: ' . json_last_error_msg());
    }
    if (file_put_contents($filePath, $jsonDataToSave) === false) {
        throw new Exception('Gagal menulis data ke file tasks.json.');
    }
    return true;
}

// Fungsi untuk memicu sinkronisasi secara asynchronous
function triggerSyncAsync($syncScriptUrl, $syncTriggerFile) {
    // Update timestamp di sync_trigger.json untuk menandai modifikasi JSON
    // Menggunakan file locking sederhana untuk update trigger file
    $fpTrigger = fopen($syncTriggerFile, 'c+');
    if ($fpTrigger && flock($fpTrigger, LOCK_EX)) {
        $triggerData = json_decode(stream_get_contents($fpTrigger), true) ?: ["last_json_modified" => 0, "last_sync_completed" => 0];
        $triggerData["last_json_modified"] = microtime(true); // Waktu saat ini dalam detik dan mikrodetik
        ftruncate($fpTrigger, 0);
        rewind($fpTrigger);
        fwrite($fpTrigger, json_encode($triggerData));
        fflush($fpTrigger);
        flock($fpTrigger, LOCK_UN);
        fclose($fpTrigger);
    } else {
        error_log("Could not acquire lock or open sync_trigger.json for writing.");
    }


    // Memanggil skrip sinkronisasi tanpa menunggu respons (fire-and-forget)
    $parts = parse_url($syncScriptUrl);
    $host = $parts['host'];
    $path = $parts['path'];
    $port = isset($parts['port']) ? $parts['port'] : 80; // Default HTTP port for HTTP
    if (isset($parts['scheme']) && $parts['scheme'] == 'https') {
        $host = 'ssl://' . $host; // For HTTPS
        $port = 443;
    }


    $fp = fsockopen($host, $port, $errno, $errstr, 1); // Timeout 1 detik
    if (!$fp) {
        error_log("Error triggering sync: $errstr ($errno) to $syncScriptUrl");
        return false;
    }

    $out = "GET $path HTTP/1.1\r\n";
    $out .= "Host: " . $parts['host'] . "\r\n"; // Use original host for HTTP header
    $out .= "Connection: Close\r\n\r\n"; // Penting: Connection: Close agar tidak menunggu respons
    fwrite($fp, $out);
    fclose($fp); // Tutup koneksi segera
    error_log("Sync trigger sent for URL: $syncScriptUrl");
    return true;
}


try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            $tasks = readTasksJson($tasksFile);
            $response = $tasks;
            break;

        case 'POST':
            $taskDataJson = $_POST['taskData'] ?? null;
            if (!$taskDataJson) {
                http_response_code(400); throw new Exception('Data tugas (taskData) tidak ditemukan.');
            }
            $taskData = json_decode($taskDataJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400); throw new Exception('Format data tugas (taskData) tidak valid JSON: ' . json_last_error_msg());
            }
            if (!isset($taskData['nama']) || !isset($taskData['detail']) || !isset($taskData['status'])) {
                http_response_code(400); throw new Exception('Field nama, detail, dan status tugas wajib diisi.');
            }

            $uploadsDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadsDir)) { if (!mkdir($uploadsDir, 0755, true)) { throw new Exception('Gagal membuat direktori uploads.'); }}
            if (!is_writable($uploadsDir)) { throw new Exception('Direktori uploads tidak dapat ditulis.'); }

            $tasks = readTasksJson($tasksFile);
            $isUpdate = false;
            $taskId = $taskData['id'] ?? null;
            $existingTaskIndex = -1;
            $previousTaskNameForNotif = "";
            $oldLampiranPath = null;
            $oldLampiranNamaOriginal = null;

            if ($taskId !== null) {
                foreach ($tasks as $index => $task) {
                    if (isset($task['id']) && $task['id'] == $taskId) {
                        $existingTaskIndex = $index;
                        $previousTaskNameForNotif = $task['nama'] ?? '';
                        $oldLampiranPath = $task['lampiranPath'] ?? null;
                        $oldLampiranNamaOriginal = $task['lampiranNamaOriginal'] ?? null;
                        break;
                    }
                }
            }

            $lampiranPathBaru = null;
            $lampiranNamaOriginalBaru = null;
            if (isset($_FILES['lampiranFile']) && $_FILES['lampiranFile']['error'] == UPLOAD_ERR_OK) {
                if ($existingTaskIndex > -1 && !empty($oldLampiranPath)) {
                    $fullOldPath = __DIR__ . '/../' . $oldLampiranPath;
                    if (file_exists($fullOldPath)) { if (!unlink($fullOldPath)) { error_log("Gagal menghapus file lampiran: " . $fullOldPath);}}
                }
                $tmpName = $_FILES['lampiranFile']['tmp_name']; $originalName = basename($_FILES['lampiranFile']['name']);
                $safeOriginalName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);
                $fileExtension = strtolower(pathinfo($safeOriginalName, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt'];
                if (!in_array($fileExtension, $allowedExtensions)) { http_response_code(400); throw new Exception('Tipe file lampiran tidak diizinkan.'); }
                if ($_FILES['lampiranFile']['size'] > 50 * 1024 * 1024) { http_response_code(400); throw new Exception('Ukuran file lampiran melebihi batas 50 MB.');}
                $uniqueFilename = uniqid('lampiran_', true) . '.' . $fileExtension; $destination = $uploadsDir . $uniqueFilename;
                if (move_uploaded_file($tmpName, $destination)) { $lampiranPathBaru = 'uploads/' . $uniqueFilename; $lampiranNamaOriginalBaru = $safeOriginalName; }
                else { http_response_code(500); throw new Exception('Gagal mengunggah file lampiran.'); }
            }
            if ($lampiranPathBaru) {
                $taskData['lampiranPath'] = $lampiranPathBaru;
                $taskData['lampiranNamaOriginal'] = $lampiranNamaOriginalBaru;
            } elseif ($existingTaskIndex > -1) {
                $taskData['lampiranPath'] = $oldLampiranPath;
                $taskData['lampiranNamaOriginal'] = $oldLampiranNamaOriginal;
            } else {
                $taskData['lampiranPath'] = $taskData['lampiranPath'] ?? null;
                $taskData['lampiranNamaOriginal'] = $taskData['lampiranNamaOriginal'] ?? null;
            }

            if ($existingTaskIndex > -1) {
                $taskData['createdAt'] = $tasks[$existingTaskIndex]['createdAt'];
                $tasks[$existingTaskIndex] = $taskData;
                $isUpdate = true;
                $notificationMessage = "📝 Tugas diperbarui:\n*" . htmlspecialchars($taskData['nama']) . "*";
                if ($previousTaskNameForNotif !== $taskData['nama']) { $notificationMessage .= "\n_(Nama lama: " . htmlspecialchars($previousTaskNameForNotif) . ")_"; }
            } else {
                $taskData['id'] = $taskData['id'] ?: uniqid('task_', true);
                $taskData['createdAt'] = $taskData['createdAt'] ?: date('c');
                $tasks[] = $taskData;
                $notificationMessage = "✨ Tugas baru ditambahkan:\n*" . htmlspecialchars($taskData['nama']) . "*";
            }
            
            writeTasksJson($tasksFile, $tasks); // Tulis perubahan ke JSON

            // Panggil sinkronisasi setelah operasi POST berhasil
            // Ganti URL ini dengan URL AKSES PUBLIK KE sync_json_to_db.php Anda
            // Contoh: "http://yourdomain.com/api/sync_json_to_db.php" atau "https://bleauworks.my.id/api/sync_json_to_db.php"
            $syncScriptUrl = "https://bleauworks.my.id/api/sync_json_to_db.php"; // <--- SESUAIKAN INI
            triggerSyncAsync($syncScriptUrl, $syncTriggerFile);

            $response = ['status' => 'success', 'message' => 'Tugas berhasil disimpan.', 'task' => $taskData];

            $statusTextNotif = "N/A";
            switch($taskData['status']) {
                case 'belum': $statusTextNotif = 'Belum Dikerjakan'; break;
                case 'proses': $statusTextNotif = 'Proses'; break;
                case 'selesai': $statusTextNotif = 'Selesai'; break;
            }
            $notificationMessage .= "\nStatus: " . htmlspecialchars($statusTextNotif);
            if (!empty($taskData['tenggatDisplay'])) { $notificationMessage .= "\nTenggat: " . htmlspecialchars($taskData['tenggatDisplay']); }

            if (!empty($BOT_TOKEN_NOTIF)) {
                $subscriberChatIds = getSubscriberChatIds();
                if (!empty($subscriberChatIds)) {
                    foreach ($subscriberChatIds as $subscriberChatId) {
                        sendTelegramMessage($BOT_TOKEN_NOTIF, $subscriberChatId, $notificationMessage);
                    }
                    error_log("Notifikasi perubahan data dikirim ke " . count($subscriberChatIds) . " pelanggan.");
                }
            }
            break;

        case 'DELETE':
            $taskId = $input['id'] ?? null;
            if (!$taskId) { http_response_code(400); throw new Exception('ID Tugas tidak ditemukan.');}

            $tasks = readTasksJson($tasksFile);
            $taskToDelete = null;
            $taskDeletedName = "Sebuah tugas";
            $initialTaskCount = count($tasks);

            $tasksUpdated = array_filter($tasks, function($task) use ($taskId, &$taskToDelete, &$taskDeletedName) {
                if (isset($task['id']) && $task['id'] == $taskId) {
                    $taskToDelete = $task; $taskDeletedName = $task['nama'] ?? $taskDeletedName; return false;
                }
                return true;
            });

            if ($initialTaskCount === count($tasksUpdated)) { http_response_code(404); throw new Exception('Tugas dengan ID tersebut tidak ditemukan untuk dihapus.');}

            if ($taskToDelete && !empty($taskToDelete['lampiranPath'])) {
                $attachmentFilePath = __DIR__ . '/../' . $taskToDelete['lampiranPath'];
                if (file_exists($attachmentFilePath)) { if (!unlink($attachmentFilePath)) { error_log("Gagal menghapus file lampiran: " . $attachmentFilePath);}}
            }
            
            writeTasksJson($tasksFile, $tasksUpdated); // Tulis perubahan ke JSON

            // Panggil sinkronisasi setelah operasi DELETE berhasil
            // Ganti URL ini dengan URL AKSES PUBLIK KE sync_json_to_db.php Anda
            // Contoh: "http://yourdomain.com/api/sync_json_to_db.php" atau "https://bleauworks.my.id/api/sync_json_to_db.php"
            $syncScriptUrl = "https://bleauworks.my.id/api/sync_json_to_db.php"; // <--- SESUAIKAN INI
            triggerSyncAsync($syncScriptUrl, $syncTriggerFile);


            $response = ['status' => 'success', 'message' => 'Tugas berhasil dihapus.'];
            
            if (!empty($BOT_TOKEN_NOTIF)) {
                $subscriberChatIds = getSubscriberChatIds();
                if (!empty($subscriberChatIds)) {
                    $notificationMessage = "🗑️ Tugas dihapus:\n*" . htmlspecialchars($taskDeletedName) . "*";
                    foreach ($subscriberChatIds as $subscriberChatId) {
                        sendTelegramMessage($BOT_TOKEN_NOTIF, $subscriberChatId, $notificationMessage);
                    }
                    error_log("Notifikasi penghapusan data dikirim ke " . count($subscriberChatIds) . " pelanggan untuk tugas: " . $taskDeletedName);
                } else {
                    error_log("Tidak ada pelanggan untuk notifikasi data.");
                }
            }
            break;

        default:
            http_response_code(405);
            throw new Exception('Metode request tidak diizinkan.');
    }

} catch (Exception $e) {
    if (http_response_code() < 400) { http_response_code(500); }
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    error_log("Error in api-kerjaan.php: " . $e->getMessage());
} finally {
    // Tidak ada koneksi DB yang perlu ditutup di sini lagi
}

echo json_encode($response);
exit;