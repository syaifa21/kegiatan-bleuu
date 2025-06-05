let currentModalType = '';
const modalOverlay = document.getElementById('form-modal-overlay');
const modalTitle = document.getElementById('modal-title');
const modalDeskripsi = document.getElementById('modal-deskripsi');
const modalJumlah = document.getElementById('modal-jumlah');
const modalSaveButton = document.getElementById('modal-save-button');

const API_ENDPOINT = 'api/api-keuangan.php';

let allFinancialData = {}; // Hanya akan berisi data bulanan (YYYY-MM)
let currentMonthKey; // FormatYYYY-MM
let monthlyChart = null; // Initialize to null for Chart.js destruction logic

const MONTHS = [
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember"
];

function formatRupiahDisplay(angka, withRpPrefix = true) {
    if (isNaN(parseFloat(angka))) return withRpPrefix ? 'Rp 0' : '0';
    let number_string = Math.round(parseFloat(angka)).toString(),
        sisa = number_string.length % 3,
        rupiah = number_string.substr(0, sisa),
        ribuan = number_string.substr(sisa).match(/\d{3}/gi);
    if (ribuan) {
        const separator = sisa ? '.' : '';
        rupiah += separator + ribuan.join('.');
    }
    return (withRpPrefix ? 'Rp ' : '') + (rupiah || '0');
 }
function parseRupiah(rupiahString) {
    if (typeof rupiahString === 'number') return rupiahString;
    if (!rupiahString) return 0;
    // Allow parsing of negative numbers by keeping the minus sign
    return parseFloat(String(rupiahString).replace(/[^0-9-]/g, '')) || 0;
}

// --- Data Handling (Load/Save) ---

function getCurrentMonthData() {
    if (!allFinancialData[currentMonthKey]) {
        return {
            alokasiPersen: [20, 50, 20, 10], // Default percentages
            pendapatanItems: [],
            pengeluaranItems: []
        };
    }
    return allFinancialData[currentMonthKey];
}

async function saveDataToServer(monthKey, monthData) {
    console.log("CLIENT: Mencoba menyimpan data ke server...");
    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', },
            body: JSON.stringify({
                monthKey: monthKey,
                monthData: monthData
            }),
        });
        const responseText = await response.text();
        console.log("CLIENT: Respon mentah dari server (simpan):", responseText);

        if (response.ok) {
            try {
                const result = JSON.parse(responseText);
                console.log('CLIENT: Data berhasil disimpan ke server:', result.message);
            } catch (e) {
                console.warn("CLIENT: Respon simpan OK tapi bukan JSON valid:", responseText);
            }
        } else {
            console.error('CLIENT: Gagal menyimpan data ke server. Status:', response.status, response.statusText, "Respon:", responseText);
            alert(`Gagal menyimpan data ke server: ${response.statusText} - ${responseText}`);
        }
    } catch (error) {
        console.error('CLIENT: Error jaringan saat mengirim data ke server:', error);
        alert(`Error jaringan saat mencoba menyimpan data: ${error.message}`);
    }
}

async function loadAllDataFromServer() {
    console.log(`CLIENT: Mencoba memuat semua data dari server ${API_ENDPOINT}...`);
    try {
        const response = await fetch(API_ENDPOINT);
        const responseText = await response.text();
        console.log("CLIENT: Respon mentah dari server (muat semua):", responseText);

        if (response.ok) {
            let data = null;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error("CLIENT: Gagal parse JSON dari server meskipun response OK. Respon:", responseText, "Error:", e);
                allFinancialData = {};
                return;
            }

            console.log('CLIENT: Semua data JSON berhasil diparse dari server:', data);
            if (data && typeof data === 'object') {
                allFinancialData = data;
            } else {
                console.log("CLIENT: Data dari server kosong, memuat struktur default.");
                allFinancialData = {};
            }
        } else {
            console.error('CLIENT: Gagal memuat data dari server. Status:', response.status, response.statusText, "Respon:", responseText);
            allFinancialData = {};
        }
    } catch (error) {
        console.error('CLIENT: Error jaringan saat mengambil data dari server:', error);
        allFinancialData = {};
    }
    populateMonthYearSelector();
    loadDataForSelectedMonth(); // Will also call hitungSemua and updateChart
}

function populateUIWithData(monthData) {
    console.log("CLIENT: Memulai populateUIWithData dengan data bulanan:", monthData);

    // Gaji utama sekarang adalah derived value, akan diisi oleh hitungSemua()
    document.getElementById('total-pendapatan-alokasi-display').textContent = formatRupiahDisplay(0); // Reset for current month

    const inputPersenAlokasi = document.querySelectorAll('#tabel-alokasi tbody .persen-alokasi');
    inputPersenAlokasi.forEach((input, index) => {
        input.value = (monthData.alokasiPersen && monthData.alokasiPersen[index] !== undefined) ? monthData.alokasiPersen[index] : (['20', '50', '20', '10'][index] || '0');
    });

    const tabelPendapatanBulananBody = document.querySelector('#tabel-pendapatan-bulanan tbody');
    tabelPendapatanBulananBody.innerHTML = '';
    (monthData.pendapatanItems || []).forEach(item => {
        tambahBarisKeTabel('tabel-pendapatan-bulanan', item.deskripsi, item.jumlah, 'amount-income-bulanan', false);
    });

    const tabelPengeluaranBulananBody = document.querySelector('#tabel-pengeluaran-bulanan tbody');
    tabelPengeluaranBulananBody.innerHTML = '';
    (monthData.pengeluaranItems || []).forEach(item => {
        tambahBarisKeTabel('tabel-pengeluaran-bulanan', item.deskripsi, item.jumlah, 'amount-expense-bulanan', false);
    });

    formatInitialAmounts();
    hitungSemua(false); // Hitung ulang tanpa menyimpan ke server karena ini adalah pemuatan
    // updateChart() is called inside hitungSemua
    console.log("CLIENT: Selesai populateUIWithData.");
}


// --- Month Selector Logic ---

function populateMonthYearSelector() {
    const selectEl = document.getElementById('month-year-select');
    selectEl.innerHTML = '';

    const today = new Date();
    let startYear = 2020;
    let endYear = today.getFullYear() + 1;

    const savedMonths = Object.keys(allFinancialData)
                            .sort();

    const allAvailableMonths = new Set();
    savedMonths.forEach(monthKey => allAvailableMonths.add(monthKey));

    for (let i = -12; i <= 12; i++) {
        const d = new Date(today.getFullYear(), today.getMonth() + i, 1);
        allAvailableMonths.add(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    }

    for (let year = startYear; year <= endYear; year++) {
        for (let month = 1; month <= 12; month++) {
            allAvailableMonths.add(`${year}-${String(month).padStart(2, '0')}`);
        }
    }

    const sortedMonths = Array.from(allAvailableMonths).sort();

    let foundCurrentMonth = false;
    const currentMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;

    sortedMonths.forEach(monthKey => {
        const option = document.createElement('option');
        option.value = monthKey;
        const [year, month] = monthKey.split('-');
        option.textContent = `${MONTHS[parseInt(month) - 1]} ${year}`;
        selectEl.appendChild(option);
        if (monthKey === currentMonth) {
            option.selected = true;
            foundCurrentMonth = true;
        }
    });

    if (!foundCurrentMonth && sortedMonths.length > 0) {
         if (selectEl.querySelector(`option[value="${currentMonth}"]`)) {
            selectEl.value = currentMonth;
         } else {
            selectEl.value = sortedMonths[sortedMonths.length - 1];
         }
    }
    currentMonthKey = selectEl.value;

    document.getElementById('prev-month-button').onclick = () => navigateMonth(-1);
    document.getElementById('next-month-button').onclick = () => navigateMonth(1);
}

function navigateMonth(direction) {
    const selectEl = document.getElementById('month-year-select');
    const currentIndex = selectEl.selectedIndex;
    const newIndex = currentIndex + direction;

    if (newIndex >= 0 && newIndex < selectEl.options.length) {
        selectEl.selectedIndex = newIndex;
        loadDataForSelectedMonth();
    } else {
        alert("Tidak ada bulan selanjutnya/sebelumnya yang tersedia.");
    }
}

function loadDataForSelectedMonth() {
    const selectEl = document.getElementById('month-year-select');
    currentMonthKey = selectEl.value;
    console.log(`CLIENT: Memuat data untuk bulan: ${currentMonthKey}`);

    const monthData = getCurrentMonthData();
    populateUIWithData(monthData);
}

// --- Core Calculation & UI Update ---

function hitungAlokasi() {
    const totalPendapatanBulananAktual = parseRupiah(document.getElementById('total-pendapatan-bulanan').textContent);

    // Set the 'Total Pendapatan untuk Alokasi' display span to this calculated value
    const totalPendapatanAlokasiDisplay = document.getElementById('total-pendapatan-alokasi-display');
    totalPendapatanAlokasiDisplay.textContent = formatRupiahDisplay(totalPendapatanBulananAktual);

    let totalPersen = 0;
    let totalRupiahAlokasi = 0;
    const barisAlokasi = document.querySelectorAll('#tabel-alokasi tbody tr');
    const warningPersentaseEl = document.getElementById('warning-persentase');
    warningPersentaseEl.textContent = '';
    warningPersentaseEl.className = '';

    barisAlokasi.forEach(baris => {
        const inputPersen = baris.querySelector('.persen-alokasi');
        const selJumlahAlokasi = baris.querySelector('.jumlah-alokasi');
        if (inputPersen && selJumlahAlokasi) {
            const persen = parseFloat(inputPersen.value) || 0; // Use parseFloat for text input
            totalPersen += persen;
            // Calculate based on the derived total income for allocation
            const jumlahAlokasi = (persen / 100) * totalPendapatanBulananAktual;
            selJumlahAlokasi.textContent = formatRupiahDisplay(jumlahAlokasi);
            totalRupiahAlokasi += jumlahAlokasi;
        }
    });

    document.getElementById('total-persen-alokasi').textContent = totalPersen.toFixed(0) + '%';
    document.getElementById('total-rupiah-alokasi').textContent = formatRupiahDisplay(totalRupiahAlokasi);

    if (totalPersen !== 100 && totalPendapatanBulananAktual > 0) {
        warningPersentaseEl.textContent = `Peringatan: Total persentase alokasi (${totalPersen}%) tidak 100%.`;
        warningPersentaseEl.classList.add('error');
    } else if (Math.abs(totalRupiahAlokasi - totalPendapatanBulananAktual) > 1 && totalPendapatanBulananAktual > 0 && totalPersen === 100) {
        warningPersentaseEl.textContent = `Catatan: Total alokasi (${formatRupiahDisplay(totalRupiahAlokasi)}) sedikit berbeda dari Total Pendapatan Alokasi (${formatRupiahDisplay(totalPendapatanBulananAktual)}) karena pembulatan.`;
        warningPersentaseEl.classList.add('warning');
    }
}

function hitungTotal(tableId, amountClass, totalCellId) {
    let total = 0;
    document.querySelectorAll(`#${tableId} tbody .${amountClass}`).forEach(cell => {
        total += parseRupiah(cell.textContent);
    });
    document.getElementById(totalCellId).textContent = formatRupiahDisplay(total);
    return total;
}

function tambahBarisKeTabel(tableId, col1Value, col2Value, amountClass, panggilHitungSemua = true) {
    const tabelBody = document.getElementById(tableId).getElementsByTagName('tbody')[0];
    const barisBaru = tabelBody.insertRow();

    const sel1 = barisBaru.insertCell(0);
    sel1.textContent = col1Value;
    sel1.classList.add('editable');
    sel1.onclick = () => editCell(sel1, false, tableId);

    const sel2 = barisBaru.insertCell(1);
    sel2.textContent = formatRupiahDisplay(parseRupiah(col2Value), false);
    sel2.classList.add('editable', amountClass);
    sel2.onclick = () => editCell(sel2, true, tableId);

    const sel3 = barisBaru.insertCell(2);
    const tombolHapus = document.createElement('button');
    tombolHapus.textContent = 'Hapus';
    tombolHapus.classList.add('button', 'danger');
    tombolHapus.style.padding = '0.4rem 0.8rem';
    tombolHapus.style.fontSize = '0.8em';
    tombolHapus.onclick = () => hapusBaris(tombolHapus, tableId);
    sel3.appendChild(tombolHapus);

    if (panggilHitungSemua) {
        hitungSemua();
    }
}

function openModal(type) {
    currentModalType = type;
    modalOverlay.classList.add('active');
    modalDeskripsi.value = '';
    modalJumlah.value = '';
    if (type === 'pendapatan-bulanan') {
        modalTitle.textContent = 'Tambah Pendapatan Bulanan Baru';
        modalDeskripsi.placeholder = 'Sumber Pendapatan (Mis: Gaji Tambahan)';
    } else if (type === 'pengeluaran-bulanan') {
        modalTitle.textContent = 'Tambah Pengeluaran Bulanan Baru';
        modalDeskripsi.placeholder = 'Kategori Pengeluaran (Mis: Belanja Bulanan)';
    }
    modalDeskripsi.focus();
}
function closeModal() {
     modalOverlay.classList.remove('active');
}

modalSaveButton.onclick = function() {
    const deskripsi = modalDeskripsi.value.trim();
    const jumlah = modalJumlah.value;

    if (!deskripsi || !jumlah || isNaN(parseRupiah(jumlah)) || parseRupiah(jumlah) <= 0) {
        alert('Harap isi deskripsi dan jumlah yang valid.');
        return;
    }

    if (currentModalType === 'pendapatan-bulanan') {
        tambahBarisKeTabel('tabel-pendapatan-bulanan', deskripsi, jumlah, 'amount-income-bulanan');
    } else if (currentModalType === 'pengeluaran-bulanan') {
        tambahBarisKeTabel('tabel-pengeluaran-bulanan', deskripsi, jumlah, 'amount-expense-bulanan');
    }
    closeModal();
};

modalOverlay.addEventListener('click', function(event) {
     if (event.target === modalOverlay) {
        closeModal();
    }
});
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && modalOverlay.classList.contains('active')) {
        closeModal();
    }
});

function hapusBaris(buttonElement, tableId) {
    const baris = buttonElement.closest('tr');
    if (baris) {
        baris.parentNode.removeChild(baris);
        hitungSemua();
    }
}

function editCell(cell, isNumeric = false, tableId = null) {
     if (cell.querySelector('input')) return;
    const originalValue = cell.textContent;
    const currentValue = isNumeric ? parseRupiah(originalValue) : originalValue;

    const input = document.createElement('input');
    input.type = isNumeric ? 'number' : 'text';
    input.value = currentValue;
    input.style.width = '100%';
    input.style.padding = '0.4rem';
    input.style.border = '1px solid var(--primary-color)';
    input.style.borderRadius = 'var(--border-radius)';
    input.style.fontSize = 'inherit';
    input.style.backgroundColor = 'var(--light-color)'; /* Disini disesuaikan */
    input.style.color = 'var(--text-color)'; /* Disini disesuaikan */

    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    const saveAndRestore = () => {
        let newValue = input.value;
        cell.textContent = isNumeric ? formatRupiahDisplay(parseRupiah(newValue), false) : newValue;
        hitungSemua(); // Recalculate and save after any edit
        cell.onclick = () => editCell(cell, isNumeric, tableId);
    };

    input.onblur = saveAndRestore;
    input.onkeypress = e => { if (e.key === 'Enter') input.blur(); };
    cell.onclick = null;
}

function hitungRingkasanBulanan() {
    // totalPendapatanBulanan dan totalPengeluaranBulanan sudah dihitung di fungsi hitungTotal sebelumnya
    const totalPendapatanBulanan = hitungTotal('tabel-pendapatan-bulanan', 'amount-income-bulanan', 'total-pendapatan-bulanan');
    const totalPengeluaranBulanan = hitungTotal('tabel-pengeluaran-bulanan', 'amount-expense-bulanan', 'total-pengeluaran-bulanan');

    const sisaDanaBulanan = totalPendapatanBulanan - totalPengeluaranBulanan;

    document.getElementById('summary-total-pendapatan-bulanan').textContent = formatRupiahDisplay(totalPendapatanBulanan);
    document.getElementById('summary-total-pengeluaran-bulanan').textContent = formatRupiahDisplay(totalPengeluaranBulanan);
    const sisaDanaBulananEl = document.getElementById('summary-sisa-dana-bulanan');
    sisaDanaBulananEl.textContent = formatRupiahDisplay(sisaDanaBulanan);
    sisaDanaBulananEl.className = sisaDanaBulanan < 0 ? 'negative' : 'positive';
}

function hitungRingkasanGlobal() {
    let totalPendapatanBulananAkumulasi = 0;
    let totalPengeluaranBulananAkumulasi = 0;

    for (const monthKey in allFinancialData) {
        const monthData = allFinancialData[monthKey];
        (monthData.pendapatanItems || []).forEach(item => {
            totalPendapatanBulananAkumulasi += parseRupiah(item.jumlah);
        });
        (monthData.pengeluaranItems || []).forEach(item => {
            totalPengeluaranBulananAkumulasi += parseRupiah(item.jumlah);
        });
    }

    const sisaDanaGlobal = totalPendapatanBulananAkumulasi - totalPengeluaranBulananAkumulasi;

    document.getElementById('summary-total-pendapatan-global').textContent = formatRupiahDisplay(totalPendapatanBulananAkumulasi);
    document.getElementById('summary-total-pengeluaran-global').textContent = formatRupiahDisplay(totalPengeluaranBulananAkumulasi);
    const sisaDanaGlobalEl = document.getElementById('summary-sisa-dana-global');
    sisaDanaGlobalEl.textContent = formatRupiahDisplay(sisaDanaGlobal);
    sisaDanaGlobalEl.className = sisaDanaGlobal < 0 ? 'negative' : 'positive';
}

function formatInitialAmounts() {
    console.log("CLIENT: Memformat angka awal di tabel...");
    document.querySelectorAll(
        '#tabel-pendapatan-bulanan tbody .amount-income-bulanan, ' +
        '#tabel-pengeluaran-bulanan tbody .amount-expense-bulanan'
    ).forEach(cell => {
        if (!cell.textContent.includes('Rp ')) {
            cell.textContent = formatRupiahDisplay(parseRupiah(cell.textContent), false);
        }
    });
}

function hitungSemua(shouldSave = true) {
    console.log(`CLIENT: hitungSemua dipanggil. Simpan ke server: ${shouldSave}`);

    // Hitung total pendapatan bulanan aktual untuk alokasi
    const totalPendapatanBulananSaatIni = hitungTotal('tabel-pendapatan-bulanan', 'amount-income-bulanan', 'total-pendapatan-bulanan');

    document.getElementById('total-pendapatan-alokasi-display').textContent = formatRupiahDisplay(totalPendapatanBulananSaatIni);

    hitungAlokasi(); // Will now use the new total for allocation
    hitungRingkasanBulanan();
    hitungRingkasanGlobal();

    if (shouldSave) {
        const alokasiPersen = Array.from(document.querySelectorAll('#tabel-alokasi tbody .persen-alokasi')).map(input => input.value);

        const pendapatanItems = Array.from(document.querySelectorAll('#tabel-pendapatan-bulanan tbody tr')).map(row => ({
            deskripsi: row.cells[0].textContent,
            jumlah: parseRupiah(row.cells[1].textContent)
        }));
        const pengeluaranItems = Array.from(document.querySelectorAll('#tabel-pengeluaran-bulanan tbody tr')).map(row => ({
            deskripsi: row.cells[0].textContent,
            jumlah: parseRupiah(row.cells[1].textContent)
        }));

        const currentMonthData = {
            alokasiPersen: alokasiPersen,
            pendapatanItems: pendapatanItems,
            pengeluaranItems: pengeluaranItems
        };

        // Update the global allFinancialData object
        allFinancialData[currentMonthKey] = currentMonthData;

        saveDataToServer(currentMonthKey, currentMonthData);
        updateChart();
    }
}

async function deleteCurrentMonthData() {
    if (!currentMonthKey) {
        alert("Tidak ada bulan yang dipilih untuk dihapus.");
        return;
    }

    if (confirm(`Apakah Anda yakin ingin menghapus data untuk bulan ${currentMonthKey}? Tindakan ini tidak dapat diurungkan.`)) {
        console.log(`CLIENT: Mencoba menghapus data untuk bulan ${currentMonthKey} di server...`);
        try {
            const response = await fetch(API_ENDPOINT, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', },
                body: JSON.stringify({ key: currentMonthKey }),
            });
            const responseText = await response.text();
            console.log("CLIENT: Respon mentah dari server (hapus bulan):", responseText);
            if (response.ok) {
                try {
                    const result = JSON.parse(responseText);
                    alert(result.message || `Data untuk bulan ${currentMonthKey} berhasil dihapus dari server.`);
                } catch (e) {
                    alert(`Data untuk bulan ${currentMonthKey} berhasil dihapus dari server (respon bukan JSON).`);
                }
                delete allFinancialData[currentMonthKey];
                populateMonthYearSelector();
                loadDataForSelectedMonth();
                hitungSemua(false);
                updateChart();
            } else {
                alert(`Gagal menghapus data bulan ini di server: ${responseText}`);
            }
        } catch (error) {
            console.error('CLIENT: Error jaringan saat mencoba menghapus data bulan:', error);
            alert(`Error jaringan saat mencoba menghapus data bulan: ${error.message}`);
        }
    }
}


// --- Chart.js Integration ---
function initChart() {
    const ctx = document.getElementById('monthlyFinancialChart').getContext('2d');
    if (monthlyChart) {
        monthlyChart.destroy();
    }
    monthlyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Total Pendapatan',
                    backgroundColor: 'rgba(40, 167, 69, 0.7)', /* Keep green for income */
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1,
                    data: [],
                },
                {
                    label: 'Total Pengeluaran',
                    backgroundColor: 'rgba(220, 53, 69, 0.7)', /* Keep red for expense */
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 1,
                    data: [],
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { display: false, color: 'rgba(71, 85, 105, 0.2)' }, /* Grid lines for dark theme */
                    ticks: {
                        color: 'var(--text-light-color)' /* Axis labels color */
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' }, /* Grid lines for dark theme */
                    ticks: {
                        callback: function(value, index, values) {
                            return formatRupiahDisplay(value);
                        },
                        color: 'var(--text-light-color)' /* Axis labels color */
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += formatRupiahDisplay(context.parsed.y);
                            }
                            return label;
                        }
                    },
                    backgroundColor: 'rgba(0,0,0,0.8)', /* Dark tooltip background */
                    titleColor: 'var(--text-color)', /* Light title color */
                    bodyColor: 'var(--text-color)' /* Light body color */
                },
                legend: {
                    position: 'top',
                    labels: {
                        color: 'var(--text-color)' /* Legend labels color */
                    }
                },
                title: {
                    display: true,
                    text: 'Perbandingan Pendapatan vs Pengeluaran Bulanan',
                    color: 'var(--text-color)' /* Chart title color */
                }
            }
        }
    });
}

function updateChart() {
    if (!monthlyChart) {
        initChart();
    }

    const chartLabels = [];
    const chartIncomeData = [];
    const chartExpenseData = [];

    const monthKeys = Object.keys(allFinancialData)
                          .sort((a, b) => {
                              const dateA = new Date(a);
                              const dateB = new Date(b);
                              return dateA - dateB;
                          });

    monthKeys.forEach(monthKey => {
        const monthData = allFinancialData[monthKey];
        if (monthData) {
            const [year, monthNum] = monthKey.split('-');
            chartLabels.push(`${MONTHS[parseInt(monthNum) - 1].substring(0,3)} ${year.substring(2)}`);

            let totalIncome = 0;
            (monthData.pendapatanItems || []).forEach(item => totalIncome += parseRupiah(item.jumlah));

            let totalExpense = 0;
            (monthData.pengeluaranItems || []).forEach(item => totalExpense += parseRupiah(item.jumlah));

            chartIncomeData.push(totalIncome);
            chartExpenseData.push(totalExpense);
        }
    });

    monthlyChart.data.labels = chartLabels;
    monthlyChart.data.datasets[0].data = chartIncomeData;
    monthlyChart.data.datasets[1].data = chartExpenseData;
    monthlyChart.update();
    console.log("CLIENT: Chart updated.");
}


// Fungsi Ekspor (Perlu diperbarui untuk mencakup data global dan bulanan)
function collectDataForExport() {
    const data = {
        periodeLaporan: `Laporan Keuangan Pribadi per ${new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric'})}`,
        selectedMonthData: {}, // Data untuk bulan yang sedang aktif
        allMonthlyData: {} // Semua data bulanan
    };

    // Current month data
    const totalPendapatanUntukAlokasi = parseRupiah(document.getElementById('total-pendapatan-alokasi-display').textContent) || 0;

    data.selectedMonthData = {
        alokasiPersen: Array.from(document.querySelectorAll('#tabel-alokasi tbody .persen-alokasi')).map(input => parseFloat(input.value) || 0),
        alokasiJumlah: Array.from(document.querySelectorAll('#tabel-alokasi tbody .jumlah-alokasi')).map(td => parseRupiah(td.textContent)),
        pendapatanItems: Array.from(document.querySelectorAll('#tabel-pendapatan-bulanan tbody tr')).map(row => ({
            deskripsi: row.cells[0].textContent,
            jumlah: parseRupiah(row.cells[1].textContent)
        })),
        pengeluaranItems: Array.from(document.querySelectorAll('#tabel-pengeluaran-bulanan tbody tr')).map(row => ({
            deskripsi: row.cells[0].textContent,
            jumlah: parseRupiah(row.cells[1].textContent)
        }))
    };
    // Tambahkan total pendapatan untuk alokasi ke selectedMonthData untuk kebutuhan ekspor
    data.selectedMonthData.totalPendapatanUntukAlokasi = totalPendapatanUntukAlokasi;


    // All monthly data for global summary in export
    data.allMonthlyData = {};
    for (const monthKey in allFinancialData) {
        data.allMonthlyData[monthKey] = allFinancialData[monthKey];
    }

    // Calculate summaries for current month for export (using values from UI for current month)
    data.summaryBulanan = {
        totalPendapatan: parseRupiah(document.getElementById('summary-total-pendapatan-bulanan').textContent),
        totalPengeluaran: parseRupiah(document.getElementById('summary-total-pengeluaran-bulanan').textContent),
        sisaDana: parseRupiah(document.getElementById('summary-sisa-dana-bulanan').textContent)
    };

    // Calculate summaries for global data for export (re-calculate to ensure consistency)
    let exportGlobalTotalPendapatan = 0;
    let exportGlobalTotalPengeluaran = 0;

    for (const monthKey in data.allMonthlyData) {
        const monthData = data.allMonthlyData[monthKey];
        (monthData.pendapatanItems || []).forEach(item => {
            exportGlobalTotalPendapatan += parseRupiah(item.jumlah);
        });
        (monthData.pengeluaranItems || []).forEach(item => {
            exportGlobalTotalPengeluaran += parseRupiah(item.jumlah);
        });
    }
    const exportSisaDanaGlobal = exportGlobalTotalPendapatan - exportGlobalTotalPengeluaran;

    data.summaryGlobal = {
        totalPendapatan: exportGlobalTotalPendapatan,
        totalPengeluaran: exportGlobalTotalPengeluaran,
        sisaDana: exportSisaDanaGlobal
    };

    return data;
}

function triggerDownload(content, filename, contentType) {
    const a = document.createElement('a');
    const blob = new Blob([content], { type: contentType });
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

function exportData(format) {
    const data = collectDataForExport();
    const tgl = new Date().toISOString().slice(0,10).replace(/-/g,'');
    let content = '';
    let filename = `LaporanKeuangan_Pro_${tgl}`;
    let contentType = '';
    const formatAngka = (num) => typeof num === 'number' ? num.toLocaleString('id-ID') : num;
    const formatRpExport = (num) => typeof num === 'number' ? `Rp ${num.toLocaleString('id-ID')}` : num;
    const Garis = "----------------------------------------------------------\n";

    if (format === 'csv' || format === 'xls') {
        contentType = 'text/csv;charset=utf-8;';
        filename += (format === 'xls' ? '.xls' : '.csv');
        content += `"${data.periodeLaporan}"\n\n`;

        content += `"${currentMonthKey} - Data Bulanan"\n`;
        content += `"Total Pendapatan untuk Alokasi:","${formatAngka(data.selectedMonthData.totalPendapatanUntukAlokasi)}"\n\n`;
        content += `"Alokasi Dana"\n`;
        content += `"Kategori Alokasi","Persen (%)","Jumlah (IDR)"\n`;
        const kategoriAlokasi = ['Tabungan/Investasi', 'Kebutuhan Pokok', 'Keinginan', 'Dana Lain'];
        data.selectedMonthData.alokasiPersen.forEach((persen, idx) => {
            content += `"${kategoriAlokasi[idx]}","${persen}","${formatAngka(data.selectedMonthData.alokasiJumlah[idx])}"\n`;
        });
        content += `"Total","${Array.from(document.querySelectorAll('#tabel-alokasi tfoot .total-row td'))[1].textContent}","${formatAngka(parseRupiah(Array.from(document.querySelectorAll('#tabel-alokasi tfoot .total-row td'))[2].textContent))}"\n\n`;

        content += `"Pendapatan Bulanan"\n`;
        content += `"Sumber","Jumlah (IDR)"\n`;
        data.selectedMonthData.pendapatanItems.forEach(item => content += `"${item.deskripsi}","${formatAngka(item.jumlah)}"\n`);
        content += `"Total","${formatAngka(parseRupiah(document.getElementById('total-pendapatan-bulanan').textContent))}"\n\n`;

        content += `"Pengeluaran Bulanan"\n`;
        content += `"Kategori","Jumlah (IDR)"\n`;
        data.selectedMonthData.pengeluaranItems.forEach(item => content += `"${item.deskripsi}","${formatAngka(item.jumlah)}"\n`);
        content += `"Total","${formatAngka(parseRupiah(document.getElementById('total-pengeluaran-bulanan').textContent))}"\n\n`;

        content += `"Ringkasan Keuangan Bulanan (${currentMonthKey})"\n`;
        content += `"Total Pendapatan Bulanan:","${formatAngka(data.summaryBulanan.totalPendapatan)}"\n`;
        content += `"Total Pengeluaran Bulanan:","${formatAngka(data.summaryBulanan.totalPengeluaran)}"\n`;
        content += `"Sisa Dana Bulanan:","${formatAngka(data.summaryBulanan.sisaDana)}"\n\n`;

        content += `"Ringkasan Keuangan Global (Akumulasi Semua Bulan)"\n`;
        content += `"Total Pendapatan Global:","${formatAngka(data.summaryGlobal.totalPendapatan)}"\n`;
        content += `"Total Pengeluaran Global:","${formatAngka(data.summaryGlobal.totalPengeluaran)}"\n`;
        content += `"Sisa Dana Global:","${formatAngka(data.summaryGlobal.sisaDana)}"\n`;

    } else if (format === 'txt') {
        contentType = 'text/plain;charset=utf-8;';
        filename += '.txt';
        content += `${data.periodeLaporan}\n${Garis}`;

        content += `\n${currentMonthKey.toUpperCase()} - DATA BULANAN\n` + Garis;
        content += `Total Pendapatan untuk Alokasi: ${formatRpExport(data.selectedMonthData.totalPendapatanUntukAlokasi)}\n\n`;
        content += "ALOKASI DANA BULANAN\n" + Garis;
        const kategoriAlokasi = ['Tabungan/Investasi', 'Kebutuhan Pokok', 'Keinginan', 'Dana Lain'];
        data.selectedMonthData.alokasiPersen.forEach((persen, idx) => {
            content += `${String(kategoriAlokasi[idx]).padEnd(25)} | ${String(persen).padEnd(10)} | ${formatRpExport(data.selectedMonthData.alokasiJumlah[idx])}\n`;
        });
        content += `${String("Total").padEnd(25)} | ${String(Array.from(document.querySelectorAll('#tabel-alokasi tfoot .total-row td'))[1].textContent).padEnd(10)} | ${formatRpExport(parseRupiah(Array.from(document.querySelectorAll('#tabel-alokasi tfoot .total-row td'))[2].textContent))}\n`;
        content += Garis + '\n';

        content += "PENDAPATAN BULANAN\n" + Garis;
        data.selectedMonthData.pendapatanItems.forEach(item => content += `${String(item.deskripsi).padEnd(25)} | ${formatRpExport(item.jumlah)}\n`);
        content += `${String("Total").padEnd(25)} | ${formatRpExport(parseRupiah(document.getElementById('total-pendapatan-bulanan').textContent))}\n`;
        content += Garis + '\n';

        content += "PENGELUARAN BULANAN\n" + Garis;
        data.selectedMonthData.pengeluaranItems.forEach(item => content += `${String(item.deskripsi).padEnd(25)} | ${formatRpExport(item.jumlah)}\n`);
        content += `${String("Total").padEnd(25)} | ${formatRpExport(parseRupiah(document.getElementById('total-pengeluaran-bulanan').textContent))}\n`;
        content += Garis + '\n';

        content += "RINGKASAN KEUANGAN BULANAN\n" + Garis;
        content += `Total Pendapatan Bulanan : ${formatRpExport(data.summaryBulanan.totalPendapatan)}\n`;
        content += `Total Pengeluaran Bulanan: ${formatRpExport(data.summaryBulanan.totalPengeluaran)}\n`;
        content += `Sisa Dana Bulanan        : ${formatRpExport(data.summaryBulanan.sisaDana)}\n` + Garis;

        content += "\nRINGKASAN KEUANGAN GLOBAL\n" + Garis;
        content += `Total Pendapatan Global : ${formatRpExport(data.summaryGlobal.totalPendapatan)}\n`;
        content += `Total Pengeluaran Global: ${formatRpExport(data.summaryGlobal.totalPengeluaran)}\n`;
        content += `Sisa Dana Global        : ${formatRpExport(data.summaryGlobal.sisaDana)}\n` + Garis;

    } else if (format === 'html') {
        contentType = 'text/html;charset=utf-8;';
        filename += '.html';
        content = `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Laporan Keuangan ${tgl}</title><style>
            body {font-family: Arial, sans-serif; margin: 20px; font-size: 14px;}
            table {border-collapse: collapse; width: 90%; max-width: 800px; margin: 20px auto;}
            th, td {border: 1px solid #ccc; padding: 10px; text-align: left;}
            th {background-color: #e9ecef; color: #007bff; font-weight: bold;}
            h2, h3 {text-align: center; margin-top: 25px; color: #333;} h3 {font-size: 1.2em;}
            .total td {font-weight: bold; background-color: #f8f9fa;}
            .summary-p {text-align: center; margin: 8px auto; max-width: 400px; display: flex; justify-content: space-between; padding: 5px; border-bottom: 1px solid #eee;}
            .summary-p strong {margin-left: 10px;}
            </style></head><body>`;
        content += `<h2>Laporan Keuangan Pribadi</h2><h3>Periode: ${data.periodeLaporan}</h3>`;

        const createTableHTML = (title, headers, rows, totalRow = null) => {
            let html = `<h3>${title}</h3><table><thead><tr>`;
            headers.forEach(header => html += `<th>${header}</th>`);
            if (headers.length === 1 && rows.length > 0 && typeof rows[0][1] !== 'undefined') {
                html += `<th>Jumlah</th>`;
            }
            html += "</tr></thead><tbody>";
            rows.forEach(row => {
                html += `<tr>`;
                row.forEach((cell, idx) => {
                     html += `<td>${(idx === 1 || headers.length === 3 && idx === 2) && typeof cell === 'number' ? formatRpExport(cell) : cell}</td>`;
                });
                html += `</tr>`;
            });
            if (totalRow) {
                html += `<tr class="total">`;
                totalRow.forEach((cell, idx) => {
                     html += `<td>${(idx === 1 || headers.length === 3 && idx === 2) && typeof cell === 'number' ? formatRpExport(cell) : cell}</td>`;
                });
                html += `</tr>`;
            }
            html += "</tbody></table>";
            return html;
        };

        content += `<h2>Data Bulanan (${currentMonthKey})</h2>`;
        content += `<h3>Total Pendapatan untuk Alokasi: ${formatRpExport(data.selectedMonthData.totalPendapatanUntukAlokasi)}</h3>`;

        const alokasiHeaders = ['Kategori Alokasi', 'Persen (%)', 'Jumlah (IDR)'];
        const alokasiRows = [];
        const kategoriAlokasi = ['Tabungan/Investasi', 'Kebutuhan Pokok', 'Keinginan', 'Dana Lain'];
        data.selectedMonthData.alokasiPersen.forEach((persen, idx) => {
            alokasiRows.push([kategoriAlokasi[idx], `${persen}%`, data.selectedMonthData.alokasiJumlah[idx]]);
        });
        content += createTableHTML(
            "Alokasi Dana Bulanan",
            alokasiHeaders,
            alokasiRows,
            ["Total", document.getElementById('total-persen-alokasi').textContent, parseRupiah(document.getElementById('total-rupiah-alokasi').textContent)]
        );

        content += createTableHTML(
            "Pendapatan Bulanan",
            ["Sumber", "Jumlah (IDR)"],
            data.selectedMonthData.pendapatanItems.map(item => [item.deskripsi, item.jumlah]),
            ["Total", parseRupiah(document.getElementById('total-pendapatan-bulanan').textContent)]
        );
        content += createTableHTML(
            "Pengeluaran Bulanan",
            ["Kategori", "Jumlah (IDR)"],
            data.selectedMonthData.pengeluaranItems.map(item => [item.deskripsi, item.jumlah]),
            ["Total", parseRupiah(document.getElementById('total-pengeluaran-bulanan').textContent)]
        );

        content += "<h3>Ringkasan Keuangan Bulanan</h3>";
        content += `<div style="max-width: 400px; margin: 10px auto; padding: 10px; border: 1px solid #eee; border-radius: 5px;">`;
        content += `<p class="summary-p"><span>Total Pendapatan Bulanan:</span> <strong>${formatRpExport(data.summaryBulanan.totalPendapatan)}</strong></p>`;
        content += `<p class="summary-p"><span>Total Pengeluaran Bulanan:</span> <strong>${formatRpExport(data.summaryBulanan.totalPengeluaran)}</strong></p>`;
        content += `<p class="summary-p"><span>Sisa Dana Bulanan:</span> <strong>${formatRpExport(data.summaryBulanan.sisaDana)}</strong></p></div>`;

        content += "<h3>Ringkasan Keuangan Global (Akumulasi Semua Bulan)</h3>";
        content += `<div style="max-width: 400px; margin: 10px auto; padding: 10px; border: 1px solid #eee; border-radius: 5px;">`;
        content += `<p class="summary-p"><span>Total Pendapatan Global:</span> <strong>${formatRpExport(data.summaryGlobal.totalPendapatan)}</strong></p>`;
        content += `<p class="summary-p"><span>Total Pengeluaran Global:</span> <strong>${formatRpExport(data.summaryGlobal.totalPengeluaran)}</strong></p>`;
        content += `<p class="summary-p"><span>Sisa Dana Global:</span> <strong>${formatRpExport(data.summaryGlobal.sisaDana)}</strong></p></div>`;

        content += "</body></html>";
    }
    triggerDownload(content, filename, contentType);
}


document.getElementById('tahun-sekarang').textContent = new Date().getFullYear();
window.onload = async () => {
    console.log("CLIENT: window.onload dijalankan.");
    initChart();
    await loadAllDataFromServer();
    document.getElementById('tahun-sekarang').textContent = new Date().getFullYear();
};