<?php
// api/api-kerjaan.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Sesuaikan untuk produksi jika perlu
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true'); // Penting untuk mengizinkan cookie sesi

// Tangani preflight request untuk CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start(); // MULAI SESI DI SINI

require_once __DIR__ . '/telegram_helpers.php'; // Digunakan untuk notifikasi Telegram
require_once __DIR__ . '/db_connect.php'; // Dapatkan koneksi database

$BOT_TOKEN_NOTIF = '7239871766:AAHWW70f_tuYhmFDC5LSgoUfGCv36VPnkVs';

$response = ['status' => 'error', 'message' => 'Terjadi kesalahan yang tidak diketahui.'];
$conn = null;

try {
    $conn = getDbConnection();

    // Cek user ID dari sesi
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        http_response_code(401); // Unauthorized
        throw new Exception('Akses ditolak. Anda harus login untuk melakukan aksi ini.');
    }
    
    // Ambil username untuk notifikasi
    $usernameLoggedIn = getUsernameById($userId);

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            // Ambil tugas HANYA untuk user yang sedang login
            $stmt = $conn->prepare("SELECT id, nama, detail, status, tenggatDisplay, tenggatSortable, lampiranPath, lampiranNamaOriginal, createdAt FROM tasks WHERE user_id = ? ORDER BY createdAt DESC");
            if ($stmt === false) { throw new Exception('Gagal mempersiapkan GET statement: ' . $conn->error); }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                // Pastikan ID dikonversi ke string jika diperlukan oleh frontend (opsional, karena ID di DB INT)
                $row['id'] = (string)$row['id'];
                $tasks[] = $row;
            }
            $stmt->close();
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

            // Ambil existing task dari DB
            $existingTask = null;
            $taskId = $taskData['id'] ?? null; // ID bisa string dari JS, pastikan cocok dengan INT di DB
            $previousTaskNameForNotif = "";
            $oldLampiranPath = null;
            $oldLampiranNamaOriginal = null;

            if ($taskId !== null) {
                $stmt = $conn->prepare("SELECT id, nama, lampiranPath, lampiranNamaOriginal, createdAt FROM tasks WHERE id = ? AND user_id = ?");
                if ($stmt === false) { throw new Exception('Gagal mempersiapkan cek tugas existing: ' . $conn->error); }
                $stmt->bind_param("ii", $taskId, $userId); // ID task juga int di DB
                $stmt->execute();
                $result = $stmt->get_result();
                $existingTask = $result->fetch_assoc();
                $stmt->close();

                if ($existingTask) {
                    $previousTaskNameForNotif = $existingTask['nama'] ?? '';
                    $oldLampiranPath = $existingTask['lampiranPath'] ?? null;
                    $oldLampiranNamaOriginal = $existingTask['lampiranNamaOriginal'] ?? null;
                }
            }

            $lampiranPathBaru = null;
            $lampiranNamaOriginalBaru = null;
            if (isset($_FILES['lampiranFile']) && $_FILES['lampiranFile']['error'] == UPLOAD_ERR_OK) {
                if ($existingTask && !empty($oldLampiranPath)) {
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
            } elseif ($existingTask) {
                $taskData['lampiranPath'] = $oldLampiranPath;
                $taskData['lampiranNamaOriginal'] = $oldLampiranNamaOriginal;
            } else {
                $taskData['lampiranPath'] = $taskData['lampiranPath'] ?? null;
                $taskData['lampiranNamaOriginal'] = $taskData['lampiranNamaOriginal'] ?? null;
            }
            
            // Siapkan data untuk DB
            $nama = $taskData['nama'];
            $detail = $taskData['detail'];
            $status = $taskData['status'];
            $tenggatDisplay = $taskData['tenggatDisplay'] ?? null;
            $tenggatSortable = $taskData['tenggatSortable'] ?? null;
            $lampiranPath = $taskData['lampiranPath'] ?? null;
            $lampiranNamaOriginal = $taskData['lampiranNamaOriginal'] ?? null;

            if ($existingTask) {
                // UPDATE tugas yang sudah ada
                $stmt = $conn->prepare("UPDATE tasks SET nama=?, detail=?, status=?, tenggatDisplay=?, tenggatSortable=?, lampiranPath=?, lampiranNamaOriginal=? WHERE id=? AND user_id=?");
                if ($stmt === false) { throw new Exception('Gagal mempersiapkan UPDATE statement: ' . $conn->error); }
                $stmt->bind_param("sssssssii", $nama, $detail, $status, $tenggatDisplay, $tenggatSortable, $lampiranPath, $lampiranNamaOriginal, $taskId, $userId);
                if (!$stmt->execute()) { throw new Exception('Gagal UPDATE tugas ID ' . $taskId . ': ' . $stmt->error); }
                $stmt->close();
                $notificationMessage = "✏️ *UPDATE TUGAS*\nUntuk *" . htmlspecialchars($usernameLoggedIn) . "*:\n";
                $notificationMessage .= "Tugas diperbarui: *" . htmlspecialchars($taskData['nama']) . "*";
                if ($previousTaskNameForNotif !== $taskData['nama']) { $notificationMessage .= "\n_(Nama lama: " . htmlspecialchars($previousTaskNameForNotif) . ")_"; }
            } else {
                // INSERT tugas baru
                $createdAt = $taskData['createdAt'] ?? date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO tasks (user_id, nama, detail, status, tenggatDisplay, tenggatSortable, lampiranPath, lampiranNamaOriginal, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt === false) { throw new Exception('Gagal mempersiapkan INSERT statement: ' . $conn->error); }
                $stmt->bind_param("issssssss", $userId, $nama, $detail, $status, $tenggatDisplay, $tenggatSortable, $lampiranPath, $lampiranNamaOriginal, $createdAt);
                if (!$stmt->execute()) { throw new Exception('Gagal INSERT tugas: ' . $stmt->error); }
                $taskData['id'] = $conn->insert_id; // Dapatkan ID yang baru saja diinsert
                $stmt->close();
                $notificationMessage = "✨ *TUGAS BARU DITAMBAHKAN*\nUntuk *" . htmlspecialchars($usernameLoggedIn) . "*:\n";
                $notificationMessage .= "Tugas baru: *" . htmlspecialchars($taskData['nama']) . "*";
            }
            
            $response = ['status' => 'success', 'message' => 'Tugas berhasil disimpan.', 'task' => $taskData];

            $statusTextNotif = getStatusTextForTelegram($taskData['status']);
            $notificationMessage .= "\nStatus: " . $statusTextNotif;
            if (!empty($taskData['tenggatDisplay'])) { $notificationMessage .= "\nTenggat: " . htmlspecialchars($taskData['tenggatDisplay']) . " ⏰"; }
            
            // Tambahkan bingkai ke notifikasi
            $framedNotification = createFancyBorder($notificationMessage);

            if (!empty($BOT_TOKEN_NOTIF)) {
                $subscriberChatIds = getSubscriberChatIds();
                if (!empty($subscriberChatIds)) {
                    foreach ($subscriberChatIds as $subscriberChatId) {
                        sendTelegramMessage($BOT_TOKEN_NOTIF, $subscriberChatId, $framedNotification);
                    }
                    error_log("Notifikasi perubahan data dikirim ke " . count($subscriberChatIds) . " pelanggan.");
                }
            }
            break;

        case 'DELETE':
            $taskId = $input['id'] ?? null;
            if (!$taskId) { http_response_code(400); throw new Exception('ID Tugas tidak ditemukan.');}

            // Cari tugas yang akan dihapus untuk mengambil path lampiran
            $taskToDelete = null;
            $stmt = $conn->prepare("SELECT id, nama, lampiranPath FROM tasks WHERE id = ? AND user_id = ?");
            if ($stmt === false) { throw new Exception('Gagal mempersiapkan select tugas untuk delete: ' . $conn->error); }
            $stmt->bind_param("ii", $taskId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $taskToDelete = $result->fetch_assoc();
            $stmt->close();

            if (!$taskToDelete) { http_response_code(404); throw new Exception('Tugas dengan ID tersebut tidak ditemukan atau Anda tidak memiliki izin untuk menghapusnya.');}
            
            // Hapus dari database
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
            if ($stmt === false) { throw new Exception('Gagal mempersiapkan DELETE statement: ' . $conn->error); }
            $stmt->bind_param("ii", $taskId, $userId);
            if (!$stmt->execute()) { throw new Exception('Gagal DELETE tugas ID ' . $taskId . ': ' . $stmt->error); }
            $stmt->close();

            if ($taskToDelete && !empty($taskToDelete['lampiranPath'])) {
                $attachmentFilePath = __DIR__ . '/../' . $taskToDelete['lampiranPath'];
                if (file_exists($attachmentFilePath)) { if (!unlink($attachmentFilePath)) { error_log("Gagal menghapus file lampiran: " . $attachmentFilePath);}}
            }
            
            $response = ['status' => 'success', 'message' => 'Tugas berhasil dihapus.'];
            
            if (!empty($BOT_TOKEN_NOTIF)) {
                $subscriberChatIds = getSubscriberChatIds();
                if (!empty($subscriberChatIds)) {
                    $notificationMessage = "🗑️ *TUGAS DIHAPUS*\nOleh *" . htmlspecialchars($usernameLoggedIn) . "*:\n";
                    $notificationMessage .= "Tugas dihapus: *" . htmlspecialchars($taskToDelete['nama']) . "*";
                    // Tambahkan bingkai ke notifikasi
                    $framedNotification = createFancyBorder($notificationMessage);
                    foreach ($subscriberChatIds as $subscriberChatId) {
                        sendTelegramMessage($BOT_TOKEN_NOTIF, $subscriberChatId, $framedNotification);
                    }
                    error_log("Notifikasi penghapusan data dikirim ke " . count($subscriberChatIds) . " pelanggan untuk tugas: " . $taskToDelete['nama']);
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
    if ($conn) {
        $conn->close();
    }
}

echo json_encode($response);
exit;