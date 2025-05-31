// data_manager.js
// Mengarahkan semua permintaan ke satu file API terpusat: api-kerjaan.php
const API_BASE_URL = '/api/api-kerjaan.php';

const DataManager = {
    getKerjaanList: async function() {
        try {
            const response = await fetch(API_BASE_URL, {
                method: 'GET' // Menggunakan metode GET
            });
            if (!response.ok) {
                console.error("Server error:", response.status, await response.text());
                const errorResult = await response.json();
                throw new Error(errorResult.message || 'Gagal mengambil data tugas dari server.');
            }
            const data = await response.json();
            return data;
        } catch (error) {
            console.error("Error fetching list:", error);
            // alert('Tidak dapat memuat daftar kerjaan: ' + error.message);
            return [];
        }
    },

    getKerjaanById: async function(id) {
        const kerjaanList = await this.getKerjaanList();
        return kerjaanList.find(k => k.id === id);
    },

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
            // alert('Gagal menyimpan kerjaan: ' + error.message);
            return { status: 'error', message: error.message };
        }
    },

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
            // alert('Gagal menghapus kerjaan: ' + error.message);
            return { status: 'error', message: error.message };
        }
    }
};