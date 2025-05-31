<?php
// api/telegram_helpers.php

// db_connect.php tidak lagi direquire di sini untuk webhook/cron,
// tapi akan direquire di getUsernameById() dan getTasksFromDb() karena perlu akses DB.

function sendTelegramMessage($botToken, $chatId, $text, $parseMode = 'Markdown', $replyToMessageId = null) {
    $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true, // Biasanya bagus untuk notifikasi agar link tidak terlalu panjang
    ];
    if ($replyToMessageId) {
        $data['reply_to_message_id'] = $replyToMessageId;
    }
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true,
        ],
    ];
    $context  = stream_context_create($options);
    $result = @file_get_contents($apiUrl, false, $context); // Gunakan @ untuk menyembunyikan warning jika ada
    if ($result === FALSE) {
        $error = error_get_last();
        error_log("Telegram API Error: " . ($error ? $error['message'] : 'Unknown error') . " for chat_id: {$chatId} with message: {$text}");
    }
    return $result;
}

// Fungsi untuk mendapatkan username berdasarkan user_id
function getUsernameById($userId) {
    // Membutuhkan koneksi DB, jadi kita akan load db_connect.php di sini
    require_once __DIR__ . '/db_connect.php';

    $conn = null;
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        if ($stmt === false) {
            error_log("Failed to prepare username query: " . $conn->error);
            return "Pengguna Tidak Dikenal";
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['username'] ?? "Pengguna Tidak Dikenal";
    } catch (Exception $e) {
        error_log("Error getting username by ID: " . $e->getMessage());
        return "Pengguna Tidak Dikenal";
    } finally {
        if ($conn) {
            $conn->close();
        }
    }
}

// Fungsi untuk mendapatkan tugas dari database
function getTasksFromDb($userId = null) {
    require_once __DIR__ . '/db_connect.php';
    $conn = null;
    $tasks = [];
    try {
        $conn = getDbConnection();
        $sql = "SELECT id, nama, detail, status, tenggatDisplay, tenggatSortable, lampiranPath, lampiranNamaOriginal, createdAt, user_id FROM tasks";
        if ($userId !== null) {
            $sql .= " WHERE user_id = ?";
        }
        $sql .= " ORDER BY tenggatSortable ASC, createdAt DESC"; // Urutkan biar rapi

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Gagal mempersiapkan query tugas: " . $conn->error);
        }
        if ($userId !== null) {
            $stmt->bind_param("i", $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching tasks from DB in telegram_helpers: " . $e->getMessage());
        return [];
    } finally {
        if ($conn) {
            $conn->close();
        }
    }
    return $tasks;
}


// Fungsi untuk mendapatkan status teks yang rapi dan beremoji
function getStatusTextForTelegram($statusKey) {
    switch(strtolower($statusKey ?? '')) {
        case 'belum': return 'Belum Dikerjakan ⏳';
        case 'proses': return 'Proses ⚙️';
        case 'selesai': return 'Selesai ✅';
        default: return 'N/A ❓';
    }
}

// Fungsi untuk membuat bingkai sederhana (ASCII-like)
function createFancyBorder($text, $width = 40) {
    $lines = explode("\n", $text);
    $maxLength = 0;
    foreach ($lines as $line) {
        // Menggunakan mb_strlen untuk menghitung panjang karakter multibyte (emoji)
        // dan mengganti emoji dengan spasi agar tidak mengganggu perhitungan lebar
        $cleanLine = preg_replace('/[\x{1F600}-\x{1F64F}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', ' ', $line);
        $maxLength = max($maxLength, mb_strlen($cleanLine, 'UTF-8'));
    }
    // Sesuaikan lebar bingkai, minimal selebar teks + padding
    $borderWidth = max($width, $maxLength + 4); 

    $topBorder = "┏" . str_repeat("━", $borderWidth - 2) . "┓";
    $bottomBorder = "┗" . str_repeat("━", $borderWidth - 2) . "┛";

    $framedText = $topBorder . "\n";
    foreach ($lines as $line) {
        $cleanLine = preg_replace('/[\x{1F600}-\x{1F64F}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', ' ', $line);
        $padding = $borderWidth - 4 - mb_strlen($cleanLine, 'UTF-8');
        $framedText .= "┃ " . $line . str_repeat(" ", max(0, $padding)) . " ┃\n";
    }
    $framedText .= $bottomBorder;
    return "`" . $framedText . "`"; // Gunakan backticks untuk font monospace
}

// Fungsi untuk mengelola chat IDs subscriber
define('SUBSCRIBERS_FILE_PATH', __DIR__ . '/../data/subscribers.json');

function getSubscriberChatIds() {
    if (!file_exists(dirname(SUBSCRIBERS_FILE_PATH))) {
        if (!mkdir(dirname(SUBSCRIBERS_FILE_PATH), 0755, true) && !is_dir(dirname(SUBSCRIBERS_FILE_PATH))) {
            error_log('Failed to create directory: ' . dirname(SUBSCRIBERS_FILE_PATH));
            return [];
        }
    }
    if (file_exists(SUBSCRIBERS_FILE_PATH)) {
        $subscribersJson = file_get_contents(SUBSCRIBERS_FILE_PATH);
        if ($subscribersJson === false) {
            error_log("Gagal membaca subscribers.json");
            return [];
        }
        $chatIds = json_decode($subscribersJson, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($chatIds)) ? array_unique($chatIds) : [];
    }
    return [];
}

function addSubscriberChatId($chatId) {
    if (empty($chatId) || !is_numeric($chatId)) {
        error_log("Attempted to add invalid chat_id: " . $chatId);
        return false;
    }
    
    $chatIds = getSubscriberChatIds();
    if (!in_array($chatId, $chatIds)) {
        $chatIds[] = $chatId;
        $fp = fopen(SUBSCRIBERS_FILE_PATH, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(array_values(array_unique($chatIds)), JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            error_log("Added new subscriber: " . $chatId);
            return true;
        } else {
            if ($fp) fclose($fp);
            error_log("Could not acquire lock or open subscribers.json for writing.");
            return false;
        }
    }
    return true;
}