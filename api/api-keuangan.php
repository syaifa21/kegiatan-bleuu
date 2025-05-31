<?php
// Set header untuk output JSON dan CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Sesuaikan untuk produksi jika perlu
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Tambahkan header lain jika perlu

// Tangani preflight request untuk CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Konfigurasi Database
define('DB_HOST', 'localhost'); // Biasanya localhost jika database di server yang sama
define('DB_USER', 'bleauwor_ale'); // User database Anda
define('DB_PASS', 'ale210103!'); // Password database Anda
define('DB_NAME', 'bleauwor_kegiatan_bleuu'); // Nama database Anda

// Buat koneksi database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Periksa koneksi
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SERVER: Koneksi database gagal: ' . $conn->connect_error]);
    error_log("SERVER: Koneksi database gagal: " . $conn->connect_error);
    exit();
}

// Ambil metode request HTTP
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Fungsi untuk membaca semua data keuangan dari database
function getAllFinancialData($conn) {
    $allData = [
        'global' => [
            'pendapatanTetap' => [],
            'pengeluaranTetap' => []
        ]
    ];

    // 1. Ambil Data Global (keuangan_item_global)
    $stmt = $conn->prepare("SELECT deskripsi, jumlah, tipe FROM keuangan_item_global");
    if ($stmt === false) {
        error_log("SERVER: Gagal prepare query global: " . $conn->error);
        return $allData; // Return data kosong atau error
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['tipe'] === 'pendapatan') {
            $allData['global']['pendapatanTetap'][] = ['deskripsi' => $row['deskripsi'], 'jumlah' => (float)$row['jumlah']];
        } else {
            $allData['global']['pengeluaranTetap'][] = ['deskripsi' => $row['deskripsi'], 'jumlah' => (float)$row['jumlah']];
        }
    }
    $stmt->close();

    // 2. Ambil Data Bulanan (keuangan_bulan dan keuangan_item_bulanan)
    $stmt = $conn->prepare("SELECT id, tahun_bulan, gaji_utama, alokasi_tabungan, alokasi_pokok, alokasi_keinginan, alokasi_lain FROM keuangan_bulan ORDER BY tahun_bulan ASC");
    if ($stmt === false) {
        error_log("SERVER: Gagal prepare query bulan: " . $conn->error);
        return $allData; // Return data yang mungkin hanya global
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($monthRow = $result->fetch_assoc()) {
        $monthKey = $monthRow['tahun_bulan'];
        $allData[$monthKey] = [
            'gajiUtama' => (float)$monthRow['gaji_utama'],
            'alokasiPersen' => [
                (float)$monthRow['alokasi_tabungan'],
                (float)$monthRow['alokasi_pokok'],
                (float)$monthRow['alokasi_keinginan'],
                (float)$monthRow['alokasi_lain']
            ],
            'pendapatanItems' => [],
            'pengeluaranItems' => []
        ];

        // Ambil item bulanan untuk bulan ini
        $itemStmt = $conn->prepare("SELECT deskripsi, jumlah, tipe FROM keuangan_item_bulanan WHERE tahun_bulan_id = ?");
        if ($itemStmt === false) {
            error_log("SERVER: Gagal prepare query item bulanan: " . $conn->error);
            continue; // Lanjut ke bulan berikutnya
        }
        $itemStmt->bind_param("i", $monthRow['id']);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($itemRow = $itemResult->fetch_assoc()) {
            if ($itemRow['tipe'] === 'pendapatan') {
                $allData[$monthKey]['pendapatanItems'][] = ['deskripsi' => $itemRow['deskripsi'], 'jumlah' => (float)$itemRow['jumlah']];
            } else {
                $allData[$monthKey]['pengeluaranItems'][] = ['deskripsi' => $itemRow['deskripsi'], 'jumlah' => (float)$itemRow['jumlah']];
            }
        }
        $itemStmt->close();
    }
    $stmt->close();

    return $allData;
}

// Logika berdasarkan metode request
switch ($requestMethod) {
    case 'GET': // Untuk memuat semua data
        error_log("SERVER: Menerima request GET.");
        $allData = getAllFinancialData($conn);
        http_response_code(200);
        echo json_encode($allData);
        error_log("SERVER: Berhasil mengirim semua data dari database.");
        break;

    case 'POST': // Untuk menyimpan atau memperbarui data
        error_log("SERVER: Menerima request POST.");
        $inputJSON = file_get_contents('php://input');
        if ($inputJSON === false || empty($inputJSON)) {
            http_response_code(400); // Bad Request
            echo json_encode(['status' => 'error', 'message' => 'SERVER: Tidak ada data input yang diterima.']);
            error_log("SERVER: Tidak ada data input pada request POST.");
            exit();
        }

        $requestData = json_decode($inputJSON, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(['status' => 'error', 'message' => 'SERVER: Data JSON yang dikirim tidak valid. Error: ' . json_last_error_msg()]);
            error_log("SERVER: Data JSON tidak valid pada request POST. Error: " . json_last_error_msg() . " Data: " . $inputJSON);
            exit();
        }

        // Mulai transaksi untuk memastikan konsistensi data
        $conn->begin_transaction();
        try {
            // --- Proses Data Global (keuangan_item_global) ---
            // Hapus semua data global yang lama dan masukkan yang baru
            $conn->query("DELETE FROM keuangan_item_global"); // Hapus semua
            foreach ($requestData['globalData']['pendapatanTetap'] as $item) {
                $stmt = $conn->prepare("INSERT INTO keuangan_item_global (tipe, deskripsi, jumlah) VALUES (?, ?, ?)");
                if ($stmt === false) throw new Exception("Gagal prepare insert pendapatan global: " . $conn->error);
                $tipe = 'pendapatan';
                $stmt->bind_param("ssd", $tipe, $item['deskripsi'], $item['jumlah']);
                $stmt->execute();
                $stmt->close();
            }
            foreach ($requestData['globalData']['pengeluaranTetap'] as $item) {
                $stmt = $conn->prepare("INSERT INTO keuangan_item_global (tipe, deskripsi, jumlah) VALUES (?, ?, ?)");
                if ($stmt === false) throw new Exception("Gagal prepare insert pengeluaran global: " . $conn->error);
                $tipe = 'pengeluaran';
                $stmt->bind_param("ssd", $tipe, $item['deskripsi'], $item['jumlah']);
                $stmt->execute();
                $stmt->close();
            }

            // --- Proses Data Bulanan (keuangan_bulan dan keuangan_item_bulanan) ---
            $monthKey = $requestData['monthKey'];
            $monthData = $requestData['monthData'];

            // Cek apakah bulan sudah ada
            $stmt = $conn->prepare("SELECT id FROM keuangan_bulan WHERE tahun_bulan = ?");
            if ($stmt === false) throw new Exception("Gagal prepare select bulan: " . $conn->error);
            $stmt->bind_param("s", $monthKey);
            $stmt->execute();
            $result = $stmt->get_result();
            $monthId = null;
            if ($row = $result->fetch_assoc()) {
                $monthId = $row['id'];
            }
            $stmt->close();

            $gajiUtama = $monthData['gajiUtama'];
            $alokasiPersen = $monthData['alokasiPersen'];

            if ($monthId) {
                // Update data bulan yang sudah ada
                $stmt = $conn->prepare("UPDATE keuangan_bulan SET gaji_utama = ?, alokasi_tabungan = ?, alokasi_pokok = ?, alokasi_keinginan = ?, alokasi_lain = ? WHERE id = ?");
                if ($stmt === false) throw new Exception("Gagal prepare update bulan: " . $conn->error);
                $stmt->bind_param("dddddi",
                    $gajiUtama,
                    $alokasiPersen[0],
                    $alokasiPersen[1],
                    $alokasiPersen[2],
                    $alokasiPersen[3],
                    $monthId
                );
                $stmt->execute();
                $stmt->close();

                // Hapus item bulanan lama untuk bulan ini
                $conn->query("DELETE FROM keuangan_item_bulanan WHERE tahun_bulan_id = " . $monthId);
            } else {
                // Insert bulan baru
                $stmt = $conn->prepare("INSERT INTO keuangan_bulan (tahun_bulan, gaji_utama, alokasi_tabungan, alokasi_pokok, alokasi_keinginan, alokasi_lain) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt === false) throw new Exception("Gagal prepare insert bulan: " . $conn->error);
                $stmt->bind_param("sddddd",
                    $monthKey,
                    $gajiUtama,
                    $alokasiPersen[0],
                    $alokasiPersen[1],
                    $alokasiPersen[2],
                    $alokasiPersen[3]
                );
                $stmt->execute();
                $monthId = $conn->insert_id; // Ambil ID bulan yang baru saja diinsert
                $stmt->close();
            }

            // Masukkan item pendapatan bulanan baru
            foreach ($monthData['pendapatanItems'] as $item) {
                $stmt = $conn->prepare("INSERT INTO keuangan_item_bulanan (tahun_bulan_id, tipe, deskripsi, jumlah) VALUES (?, ?, ?, ?)");
                if ($stmt === false) throw new Exception("Gagal prepare insert pendapatan bulanan: " . $conn->error);
                $tipe = 'pendapatan';
                $stmt->bind_param("issd", $monthId, $tipe, $item['deskripsi'], $item['jumlah']);
                $stmt->execute();
                $stmt->close();
            }

            // Masukkan item pengeluaran bulanan baru
            foreach ($monthData['pengeluaranItems'] as $item) {
                $stmt = $conn->prepare("INSERT INTO keuangan_item_bulanan (tahun_bulan_id, tipe, deskripsi, jumlah) VALUES (?, ?, ?, ?)");
                if ($stmt === false) throw new Exception("Gagal prepare insert pengeluaran bulanan: " . $conn->error);
                $tipe = 'pengeluaran';
                $stmt->bind_param("issd", $monthId, $tipe, $item['deskripsi'], $item['jumlah']);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit(); // Commit transaksi
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'SERVER: Data berhasil disimpan ke database.']);
            error_log("SERVER: Data berhasil disimpan ke database.");

        } catch (Exception $e) {
            $conn->rollback(); // Rollback jika ada error
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'SERVER: Gagal menyimpan data ke database: ' . $e->getMessage()]);
            error_log("SERVER: Gagal menyimpan data ke database: " . $e->getMessage());
        }
        break;

    case 'DELETE': // Untuk menghapus data (bulan tertentu atau semua)
        error_log("SERVER: Menerima request DELETE.");
        $input = json_decode(file_get_contents('php://input'), true);
        $keyToDelete = isset($input['key']) ? $input['key'] : null; // key bisa "YYYY-MM" atau null untuk semua

        $conn->begin_transaction();
        try {
            if ($keyToDelete) {
                // Hapus data bulan tertentu
                $stmt = $conn->prepare("SELECT id FROM keuangan_bulan WHERE tahun_bulan = ?");
                if ($stmt === false) throw new Exception("Gagal prepare select bulan untuk delete: " . $conn->error);
                $stmt->bind_param("s", $keyToDelete);
                $stmt->execute();
                $result = $stmt->get_result();
                $monthId = null;
                if ($row = $result->fetch_assoc()) {
                    $monthId = $row['id'];
                }
                $stmt->close();

                if ($monthId) {
                    // Hapus bulan (ini akan secara otomatis menghapus item bulanan karena ON DELETE CASCADE)
                    $stmt = $conn->prepare("DELETE FROM keuangan_bulan WHERE id = ?");
                    if ($stmt === false) throw new Exception("Gagal prepare delete bulan: " . $conn->error);
                    $stmt->bind_param("i", $monthId);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    http_response_code(200);
                    echo json_encode(['status' => 'success', 'message' => "SERVER: Data untuk '$keyToDelete' berhasil dihapus dari database."]);
                    error_log("SERVER: Data untuk '$keyToDelete' berhasil dihapus dari database.");
                } else {
                    $conn->commit(); // Tidak ada yang dihapus, tetap commit
                    http_response_code(200);
                    echo json_encode(['status' => 'info', 'message' => "SERVER: Data untuk '$keyToDelete' tidak ditemukan untuk dihapus."]);
                    error_log("SERVER: Data untuk '$keyToDelete' tidak ditemukan untuk dihapus.");
                }
            } else {
                // Hapus semua data (global dan semua bulan)
                $conn->query("DELETE FROM keuangan_item_global");
                $conn->query("DELETE FROM keuangan_item_bulanan"); // Hapus dulu item bulanan
                $conn->query("DELETE FROM keuangan_bulan"); // Baru hapus bulan

                $conn->commit();
                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'SERVER: Semua data berhasil dihapus dari database.']);
                error_log("SERVER: Semua data berhasil dihapus dari database.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'SERVER: Gagal menghapus data dari database: ' . $e->getMessage()]);
            error_log("SERVER: Gagal menghapus data dari database: " . $e->getMessage());
        }
        break;

    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['status' => 'error', 'message' => 'SERVER: Metode request tidak didukung.']);
        error_log("SERVER: Menerima metode request yang tidak didukung: " . $requestMethod);
        break;
}

$conn->close(); // Tutup koneksi database
?>