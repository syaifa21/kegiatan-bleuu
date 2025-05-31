<?php
// File: data_manager_direct.php
// Lokasi: Root project Anda (misalnya /var/www/html/dashboard_project/data_manager_direct.php)

// db_connect.php tidak lagi direquire di sini, hanya untuk sync_json_to_db.php
// require_once __DIR__ . '/api/db_connect.php'; 

class DataManagerDirect {
    private static $tasksFile;

    private static function init() {
        // Path ke tasks.json relatif dari LOKASI FILE INI (data_manager_direct.php)
        self::$tasksFile = __DIR__ . '/data/tasks.json';
    }

    // --- FUNGSI GETKERJAANLIST() KEMBALI MEMBACA DARI JSON ---
    public static function getKerjaanList() {
        self::init();
        if (!file_exists(self::$tasksFile)) {
            error_log("DataManagerDirect: File tasks.json tidak ditemukan di " . self::$tasksFile);
            return [];
        }

        $tasksJsonContent = file_get_contents(self::$tasksFile);
        if ($tasksJsonContent === false) {
            error_log("DataManagerDirect: Gagal membaca file tasks.json.");
            return null;
        }

        $tasks = json_decode($tasksJsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE && trim($tasksJsonContent) !== '') {
            error_log("DataManagerDirect: Gagal mem-parse tasks.json: " . json_last_error_msg() . " | Konten: " . substr($tasksJsonContent, 0, 200));
            return null;
        }

        return $tasks ?: [];
    }
}
?>