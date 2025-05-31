<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ... sisa kode Anda ...
header('Content-Type: application/json'); // Tetap output JSON untuk log Cron Job
require_once __DIR__ . '/db_connect.php'; // Untuk koneksi MySQL

$tasksFile = __DIR__ . '/../data/tasks.json';
$syncTriggerFile = __DIR__ . '/../data/sync_trigger.json';
$SYNC_DELAY_SECONDS = 5; // Tunda sinkronisasi setidaknya 5 detik dari modifikasi terakhir

$response = ['status' => 'error', 'message' => 'Terjadi kesalahan saat sinkronisasi.'];
$conn = null;
$fpLock = null; // Inisialisasi untuk finally block

try {
    // Pastikan sync_trigger.json ada
    if (!file_exists($syncTriggerFile)) {
        file_put_contents($syncTriggerFile, json_encode(["last_json_modified" => 0, "last_sync_completed" => 0]));
    }

    // Baca status trigger
    $triggerData = json_decode(file_get_contents($syncTriggerFile), true) ?: ["last_json_modified" => 0, "last_sync_completed" => 0];
    $currentTime = microtime(true);

    // Cek apakah sinkronisasi diperlukan dan apakah penundaan sudah terpenuhi
    if ($triggerData["last_json_modified"] <= $triggerData["last_sync_completed"]) {
        // JSON belum dimodifikasi sejak sinkronisasi terakhir, tidak perlu sinkronisasi
        $response = ['status' => 'skipped', 'message' => 'Tidak ada perubahan JSON baru untuk disinkronkan.'];
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    if (($currentTime - $triggerData["last_json_modified"]) < $SYNC_DELAY_SECONDS) {
        // Belum cukup waktu berlalu sejak modifikasi terakhir
        $response = ['status' => 'delayed', 'message' => 'Sinkronisasi ditunda, menunggu penundaan ' . $SYNC_DELAY_SECONDS . ' detik.'];
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    // Lanjutkan dengan sinkronisasi jika kondisi terpenuhi
    // Gunakan file locking untuk mencegah banyak proses sinkronisasi berjalan bersamaan
    $lockFile = __DIR__ . '/../data/sync_lock.tmp';
    $fpLock = fopen($lockFile, 'c+');
    if (!$fpLock || !flock($fpLock, LOCK_EX | LOCK_NB)) { // Coba dapatkan exclusive lock non-blocking
        if ($fpLock) fclose($fpLock); // Pastikan file ditutup jika gagal lock
        $response = ['status' => 'locked', 'message' => 'Proses sinkronisasi lain sedang berjalan.'];
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    // 1. Baca data dari tasks.json
    if (!file_exists($tasksFile)) {
        throw new Exception('File tasks.json tidak ditemukan.');
    }
    $jsonContent = file_get_contents($tasksFile);
    $jsonTasks = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE && !empty(trim($jsonContent))) {
        throw new Exception('Gagal mem-parse tasks.json: ' . json_last_error_msg());
    }
    $jsonTasks = $jsonTasks ?: [];

    // 2. Baca data dari MySQL
    $conn = getDbConnection();
    $dbTasks = [];
    $sql = "SELECT id, nama, detail, status, tenggatDisplay, tenggatSortable, lampiranPath, lampiranNamaOriginal, createdAt FROM tasks";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dbTasks[$row['id']] = $row;
        }
        $result->free();
    } else {
        throw new Exception('Gagal membaca data dari database: ' . $conn->error);
    }

    $operations = [
        'inserted' => 0,
        'updated' => 0,
        'deleted' => 0
    ];

    // 3. Bandingkan dan Sinkronkan: JSON ke DB (INSERT/UPDATE)
    foreach ($jsonTasks as $jsonTask) {
        $taskId = $jsonTask['id'];

        $nama = $jsonTask['nama'] ?? '';
        $detail = $jsonTask['detail'] ?? '';
        $status = $jsonTask['status'] ?? '';
        $tenggatDisplay = $jsonTask['tenggatDisplay'] ?? null;
        $tenggatSortable = null;
        if (!empty($jsonTask['tenggatSortable'])) {
            try {
                $dateObj = new DateTime($jsonTask['tenggatSortable']);
                $tenggatSortable = $dateObj->format('Y-m-d');
            } catch (Exception $e) { /* biarkan null jika format tidak valid */ }
        }
        $lampiranPath = $jsonTask['lampiranPath'] ?? null;
        $lampiranNamaOriginal = $jsonTask['lampiranNamaOriginal'] ?? null;
        $createdAt = $jsonTask['createdAt'] ?? date('Y-m-d H:i:s');


        if (isset($dbTasks[$taskId])) {
            $dbTask = $dbTasks[$taskId];
            $needsUpdate = false;
            if ($dbTask['nama'] != $nama ||
                $dbTask['detail'] != $detail ||
                $dbTask['status'] != $status ||
                $dbTask['tenggatDisplay'] != $tenggatDisplay ||
                $dbTask['tenggatSortable'] != $tenggatSortable ||
                $dbTask['lampiranPath'] != $lampiranPath ||
                $dbTask['lampiranNamaOriginal'] != $lampiranNamaOriginal ||
                $dbTask['createdAt'] != $createdAt
            ) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $stmt = $conn->prepare("UPDATE tasks SET nama=?, detail=?, status=?, tenggatDisplay=?, tenggatSortable=?, lampiranPath=?, lampiranNamaOriginal=?, createdAt=? WHERE id=?");
                if ($stmt === false) { throw new Exception('Gagal mempersiapkan UPDATE statement: ' . $conn->error); }
                $stmt->bind_param("sssssssss", $nama, $detail, $status, $tenggatDisplay, $tenggatSortable, $lampiranPath, $lampiranNamaOriginal, $createdAt, $taskId);
                if (!$stmt->execute()) { throw new Exception('Gagal UPDATE tugas ID ' . $taskId . ': ' . $stmt->error); }
                $operations['updated']++;
                $stmt->close();
            }
            unset($dbTasks[$taskId]);
        } else {
            $stmt = $conn->prepare("INSERT INTO tasks (id, nama, detail, status, tenggatDisplay, tenggatSortable, lampiranPath, lampiranNamaOriginal, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt === false) { throw new Exception('Gagal mempersiapkan INSERT statement: ' . $conn->error); }
            $stmt->bind_param("sssssssss", $taskId, $nama, $detail, $status, $tenggatDisplay, $tenggatSortable, $lampiranPath, $lampiranNamaOriginal, $createdAt);
            if (!$stmt->execute()) { throw new Exception('Gagal INSERT tugas ID ' . $taskId . ': ' . $stmt->error); }
            $operations['inserted']++;
            $stmt->close();
        }
    }

    // 4. Bandingkan dan Sinkronkan: DB ke JSON (DELETE)
    foreach ($dbTasks as $taskIdToDelete => $dbTaskData) {
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        if ($stmt === false) { throw new Exception('Gagal mempersiapkan DELETE statement: ' . $conn->error); }
        $stmt->bind_param("s", $taskIdToDelete);
        if (!$stmt->execute()) { throw new Exception('Gagal DELETE tugas ID ' . $taskIdToDelete . ': ' . $stmt->error); }
        $operations['deleted']++;
        $stmt->close();
    }

    // Update timestamp sinkronisasi berhasil
    $triggerData["last_sync_completed"] = $currentTime;
    file_put_contents($syncTriggerFile, json_encode($triggerData));

    $response = ['status' => 'success', 'message' => 'Sinkronisasi berhasil.', 'operations' => $operations];
    http_response_code(200);

} catch (Exception $e) {
    if (http_response_code() < 400) { http_response_code(500); }
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    error_log("Error in sync_json_to_db.php: " . $e->getMessage());
} finally {
    if ($conn) {
        $conn->close();
    }
    if ($fpLock) {
        flock($fpLock, LOCK_UN);
        fclose($fpLock);
        // unlink($lockFile); // Opsional: hapus file lock setelah selesai, jika ingin dibersihkan
    }
}

echo json_encode($response);
exit;