<?php
// api/telegram_helpers.php

// db_connect.php tidak lagi direquire di sini, hanya untuk sync_json_to_db.php
// require_once __DIR__ . '/db_connect.php'; 

function sendTelegramMessage($botToken, $chatId, $text, $parseMode = 'Markdown') {
    $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
    ];
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true,
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($apiUrl, false, $context);
    return $result;
}

// --- FUNGSI GETTASKS() KEMBALI MEMBACA DARI JSON ---
function getTasks() {
    $tasksFile = __DIR__ . '/../data/tasks.json'; // Path ke tasks.json
    if (file_exists($tasksFile)) {
        $tasksJson = file_get_contents($tasksFile);
        if ($tasksJson === false) {
            error_log("Gagal membaca file tasks.json dari path: " . $tasksFile);
            return [];
        }
        $tasks = json_decode($tasksJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $tasks ?: [];
        } else {
            error_log("Error decoding tasks.json: " . json_last_error_msg());
            return [];
        }
    }
    error_log("File tasks.json tidak ditemukan di path: " . $tasksFile);
    return [];
}

// --- Fungsi untuk Mengelola Pelanggan Notifikasi (tetap sama) ---
define('SUBSCRIBERS_FILE_PATH', __DIR__ . '/../data/subscribers.json');

function getSubscriberChatIds() { /* ... kode sama ... */
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

function addSubscriberChatId($chatId) { /* ... kode sama ... */
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
?>