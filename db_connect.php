<?php
// api/db_connect.php

// Konfigurasi koneksi database
define('DB_HOST', 'localhost'); // Ganti jika database Anda di server lain
define('DB_USER', 'bleauwor_ale');     // Ganti dengan username database Anda
define('DB_PASS', 'ale210103!');         // Ganti dengan password database Anda
define('DB_NAME', 'bleauwor_kegiatan_bleuu'); // Ganti dengan nama database yang Anda buat

function getDbConnection() {
    // Membuat koneksi ke database
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    // Cek koneksi
    if ($conn->connect_error) {
        error_log("Koneksi database gagal: " . $conn->connect_error);
        die("Koneksi database gagal.");
    }
    
    // Set charset ke utf8mb4 untuk dukungan emoji dan karakter khusus
    $conn->set_charset("utf8mb4");

    return $conn;
}
?>