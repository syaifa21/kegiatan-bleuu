<?php
// api/api-auth.php
// API ini akan menangani registrasi dan login pengguna.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Sesuaikan untuk produksi jika perlu
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true'); // Penting untuk mengizinkan kredensial (cookie/session)

// Tangani preflight request untuk CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Mulai sesi PHP
session_start();

require_once __DIR__ . '/db_connect.php'; // Menggunakan file koneksi database yang sudah ada

$response = ['status' => 'error', 'message' => 'Terjadi kesalahan yang tidak diketahui.'];
$conn = null;

try {
    $conn = getDbConnection(); // Mendapatkan koneksi database

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'POST':
            $action = $input['action'] ?? '';

            if ($action === 'register') {
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                if (empty($username) || empty($password)) {
                    http_response_code(400);
                    throw new Exception('Username dan password tidak boleh kosong.');
                }

                // Validasi username lebih lanjut (opsional)
                if (strlen($username) < 4 || strlen($username) > 50) {
                    http_response_code(400);
                    throw new Exception('Username harus antara 4 sampai 50 karakter.');
                }
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                    http_response_code(400);
                    throw new Exception('Username hanya boleh mengandung huruf, angka, dan underscore.');
                }

                // Hash password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    throw new Exception('Gagal mengenkripsi password.');
                }

                // Periksa apakah username sudah ada
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                if ($stmt === false) {
                    throw new Exception('Gagal mempersiapkan statement cek username: ' . $conn->error);
                }
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    http_response_code(409); // Conflict
                    throw new Exception('Username sudah terdaftar. Silakan pilih username lain.');
                }
                $stmt->close();

                // Simpan pengguna baru ke database
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
                if ($stmt === false) {
                    throw new Exception('Gagal mempersiapkan statement registrasi: ' . $conn->error);
                }
                $stmt->bind_param("ss", $username, $passwordHash);

                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Registrasi berhasil. Silakan login.'];
                    http_response_code(201); // Created
                } else {
                    throw new Exception('Gagal mendaftar pengguna: ' . $stmt->error);
                }
                $stmt->close();

            } else if ($action === 'login') { // Tambahkan blok login di sini
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                if (empty($username) || empty($password)) {
                    http_response_code(400);
                    throw new Exception('Username dan password harus diisi.');
                }

                // Cari pengguna berdasarkan username
                $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
                if ($stmt === false) {
                    throw new Exception('Gagal mempersiapkan statement login: ' . $conn->error);
                }
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
                $stmt->bind_result($userId, $dbUsername, $passwordHash);
                $stmt->fetch();

                if ($stmt->num_rows === 0) {
                    http_response_code(401); // Unauthorized
                    throw new Exception('Username atau password salah.');
                }

                // Verifikasi password
                if (password_verify($password, $passwordHash)) {
                    // Login berhasil
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $dbUsername;

                    $response = ['status' => 'success', 'message' => 'Login berhasil!', 'user_id' => $userId, 'username' => $dbUsername];
                    http_response_code(200);
                } else {
                    http_response_code(401); // Unauthorized
                    throw new Exception('Username atau password salah.');
                }
                $stmt->close();

            } else if ($action === 'logout') { // Tambahkan blok logout di sini
                // Hapus semua data sesi
                session_unset();
                // Hancurkan sesi
                session_destroy();
                // Kirim cookie sesi yang sudah kadaluarsa ke browser untuk memastikan penghapusan
                setcookie(session_name(), '', time() - 3600, '/');

                $response = ['status' => 'success', 'message' => 'Logout berhasil.'];
                http_response_code(200);

            } else if ($action === 'check_session') { // Tambahkan blok cek sesi
                if (isset($_SESSION['user_id'])) {
                    $response = ['status' => 'success', 'message' => 'Sesi aktif.', 'user_id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
                    http_response_code(200);
                } else {
                    $response = ['status' => 'error', 'message' => 'Tidak ada sesi aktif.'];
                    http_response_code(401); // Unauthorized
                }

            } else {
                http_response_code(400);
                throw new Exception('Aksi tidak valid.');
            }
            break;

        default:
            http_response_code(405);
            throw new Exception('Metode request tidak diizinkan.');
    }

} catch (Exception $e) {
    if (http_response_code() < 400) { http_response_code(500); } // Set status default ke 500 jika belum diatur
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    error_log("Error in api/api-auth.php: " . $e->getMessage());
} finally {
    if ($conn) {
        $conn->close();
    }
}

echo json_encode($response);
exit;
?>