// data_manager.js
// Mengarahkan semua permintaan CRUD tugas ke API terpusat yang sudah terhubung ke DB.

const API_BASE_URL = '/api/api-kerjaan.php'; // API untuk tugas
const API_AUTH_URL = '/api/api-auth.php';   // API untuk autentikasi

const DataManager = {
    // Fungsi autentikasi
    authenticate: async function(action, username, password) {
        try {
            const response = await fetch(API_AUTH_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action, username, password })
            });
            return await response.json();
        } catch (error) {
            console.error(`Error during ${action} request:`, error);
            return { status: 'error', message: 'Terjadi kesalahan saat berkomunikasi dengan server.' };
        }
    },

    // Fungsi cek sesi
    checkSession: async function() {
        try {
            const response = await fetch(API_AUTH_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'check_session' })
            });
            return await response.json();
        } catch (error) {
            console.error('Error checking session:', error);
            return { status: 'error', message: 'Terjadi kesalahan saat berkomunikasi dengan server.' };
        }
    },

    // Fungsi logout
    logout: async function() {
        try {
            const response = await fetch(API_AUTH_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'logout' })
            });
            return await response.json();
        } catch (error) {
            console.error('Error during logout:', error);
            return { status: 'error', message: 'Terjadi kesalahan saat logout.' };
        }
    },

    // Fungsi untuk mendapatkan daftar tugas dari API
    getKerjaanList: async function() {
        try {
            const response = await fetch(API_BASE_URL, {
                method: 'GET'
            });
            if (!response.ok) {
                const errorText = await response.text();
                console.error("Server error (getKerjaanList):", response.status, errorText);
                try {
                    const errorResult = JSON.parse(errorText);
                    throw new Error(errorResult.message || 'Gagal mengambil data tugas dari server.');
                } catch (parseError) {
                    throw new Error(`Gagal mengambil data tugas. Server merespon dengan status ${response.status} dan teks: ${errorText}`);
                }
            }
            const data = await response.json();
            return data;
        } catch (error) {
            console.error("Error fetching list:", error);
            return [];
        }
    },

    // Fungsi untuk mendapatkan tugas berdasarkan ID (jarang dipakai langsung, tapi bisa)
    getKerjaanById: async function(id) {
        const kerjaanList = await this.getKerjaanList();
        // Karena ID sekarang dari DB (INT), pastikan perbandingannya string atau number
        return kerjaanList.find(k => k.id == id); // Gunakan == untuk loose comparison
    },

    // Fungsi untuk menyimpan atau memperbarui tugas
    saveOrUpdateKerjaan: async function(taskData, lampiranFileObject) {
        try {
            const formData = new FormData();
            formData.append('taskData', JSON.stringify(taskData));
            
            if (lampiranFileObject) {
                formData.append('lampiranFile', lampiranFileObject, lampiranFileObject.name);
            }

            const response = await fetch(API_BASE_URL, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Gagal menyimpan data ke server.');
            }
            return result;
        } catch (error) {
            console.error("Error saving task:", error);
            return { status: 'error', message: error.message };
        }
    },

    // Fungsi untuk menghapus tugas
    deleteKerjaanById: async function(id) {
        try {
            const response = await fetch(API_BASE_URL, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Gagal menghapus data di server.');
            }
            return result;
        } catch (error) {
            console.error("Error deleting task:", error);
            return { status: 'error', message: error.message };
        }
    }
};