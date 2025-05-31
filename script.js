document.addEventListener('DOMContentLoaded', () => {
    let statusPieChartInstance = null;
    let masterTasks = [];
    // allTasksWithInstances tidak lagi diperlukan karena tidak ada proyeksi perulangan
    // let allTasksWithInstances = [];

    const greetingMessageEl = document.getElementById('greetingMessage');
    const liveClockEl = document.getElementById('liveClock');

    const navButtons = document.querySelectorAll('.navigation-tabs .btn');
    const appSections = document.querySelectorAll('.app-section');

    const inputModal = document.getElementById('inputKerjaanModal');
    const openInputModalBtn = document.getElementById('openInputModalBtn');
    const closeInputModalBtn = document.getElementById('closeInputModalBtn');
    const inputModalTitle = document.getElementById('inputModalTitle');

    const detailModal = document.getElementById('detailModal');
    const closeDetailModalBtn = document.getElementById('closeDetailModalBtn');
    const detailModalTitle = document.getElementById('detailModalTitle');

    const confirmDeleteModal = document.getElementById('confirmDeleteModal');
    const closeConfirmDeleteModalBtn = document.getElementById('closeConfirmDeleteModalBtn');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const taskNameToDeleteSpan = document.getElementById('taskNameToDelete');
    let taskIdToDelete = null;

    const formKerjaan = document.getElementById('formKerjaan');
    const editTaskIdInput = document.getElementById('editTaskId');
    const namaPekerjaanInput = document.getElementById('namaPekerjaan');
    const detailPekerjaanInput = document.getElementById('detailPekerjaan');
    const tenggatPicker = document.getElementById('tenggatPicker');
    const statusKerjaanSelect = document.getElementById('statusKerjaan');
    const lampiranFileInput = document.getElementById('lampiranFile');
    const fileWarning = document.getElementById('fileWarning');

    const totalTasksDisplay = document.querySelector('#totalTasks strong');
    // totalTaskInstancesDisplay tidak lagi relevan
    // const totalTaskInstancesDisplay = document.querySelector('#totalTaskInstances strong');
    const nearestDeadlineTasksList = document.getElementById('nearestDeadlineTasks');

    const filterStatusKerjaanSelect = document.getElementById('filterStatusKerjaan');
    const sortKerjaanSelect = document.getElementById('sortKerjaan');
    const filterBulanKerjaanPageSelect = document.getElementById('filterBulanKerjaanPage');
    const listKerjaanFiltered = document.getElementById('listKerjaanFiltered');

    const exportPeriodSelect = document.getElementById('exportPeriod');
    const exportFormatSelect = document.getElementById('exportFormat');
    const exportBtn = document.getElementById('exportBtn');

    const calendarView = document.getElementById('calendarView');
    const currentMonthYearDisplay = document.getElementById('currentMonthYear');
    const prevMonthBtn = document.getElementById('prevMonthBtn');
    const nextMonthBtn = document.getElementById('nextMonthBtn');
    let calendarCurrentDate = new Date();
    const namaHari = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
    const namaBulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

    let currentKerjaanStatusFilter = 'all';
    let currentKerjaanSortCriteria = 'createdAt_desc';
    let currentKerjaanMonthFilter = 'all';

    // --- Navigasi Bagian (Tabs) ---
    function showSection(sectionId) {
        appSections.forEach(section => {
            const isActive = section.id === sectionId;
            section.style.display = isActive ? 'block' : 'none';
            section.classList.toggle('active', isActive);
        });
        navButtons.forEach(button => {
            const isActive = button.dataset.section === sectionId;
            button.classList.toggle('active', isActive);
        });
    }

    navButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            showSection(e.currentTarget.dataset.section);
        });
    });

    function setGreeting() {
        if (!greetingMessageEl) return;
        const now = new Date(); const hours = now.getHours(); let greeting;
        if (hours < 5) greeting = "Dini Hari!"; else if (hours < 11) greeting = "Selamat Pagi!";
        else if (hours < 15) greeting = "Selamat Siang!"; else if (hours < 19) greeting = "Selamat Sore!";
        else greeting = "Selamat Malam!";
        greetingMessageEl.textContent = greeting;
    }
    function updateLiveClock() {
        if (!liveClockEl) return;
        liveClockEl.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setGreeting(); updateLiveClock(); setInterval(updateLiveClock, 1000);

    // Fungsi projectRecurringTasks dihapus total
    // function projectRecurringTasks(...) { ... }

    async function initializePage() {
        masterTasks = await DataManager.getKerjaanList();
        // Karena tidak ada perulangan, allTasksWithInstances sama dengan masterTasks
        // allTasksWithInstances = masterTasks; // Ini tidak lagi diperlukan

        masterTasks.sort((a, b) => { // Langsung sorting masterTasks
            const dateA = a.tenggatSortable || a.createdAt;
            const dateB = b.tenggatSortable || b.createdAt;
            if (!dateA && !dateB) return 0;
            if (!dateA) return 1;
            if (!dateB) return -1;
            return (dateA || '').localeCompare(dateB || '');
        });

        if (filterBulanKerjaanPageSelect) { populateMonthFilter(masterTasks, filterBulanKerjaanPageSelect, 'tenggatSortable'); } // Filter berdasarkan masterTasks

        await renderUtamaSection();
        await renderKerjaanSection();
        if (calendarView) { await renderCalendar(calendarCurrentDate.getMonth(), calendarCurrentDate.getFullYear()); }

        showSection('utamaSection');
    }

    async function renderUtamaSection() {
        totalTasksDisplay.textContent = masterTasks.length;
        // totalTaskInstancesDisplay tidak lagi relevan
        // if(totalTaskInstancesDisplay) totalTaskInstancesDisplay.textContent = masterTasks.length;

        const today = new Date(); today.setHours(0,0,0,0); const todayStr = today.toISOString().split('T')[0];
        const sevenDaysFromNow = new Date(today); sevenDaysFromNow.setDate(today.getDate() + 7);
        const sevenDaysFromNowStr = sevenDaysFromNow.toISOString().split('T')[0];

        const tasksDueSoon = masterTasks // Filter langsung dari masterTasks
            .filter(k => (k.status === 'belum' || k.status === 'proses') && k.tenggatSortable &&
                         k.tenggatSortable.split(' ')[0] >= todayStr && k.tenggatSortable.split(' ')[0] < sevenDaysFromNowStr)
            .sort((a, b) => (a.tenggatSortable || '9999').localeCompare(b.tenggatSortable || '9999'));

        nearestDeadlineTasksList.innerHTML = '';
        if (tasksDueSoon.length > 0) {
            tasksDueSoon.slice(0, 7).forEach(kerjaan => { nearestDeadlineTasksList.appendChild(createTaskListItem(kerjaan, false)); });
        } else { nearestDeadlineTasksList.innerHTML = '<li class="no-tasks">Tidak ada tenggat < 7 hari.</li>'; }
        renderPieChart(masterTasks);
    }

    async function renderKerjaanSection() {
        if (!listKerjaanFiltered) return;
        let tasksToDisplay = [...masterTasks]; // Hanya tampilkan masterTasks
        if (currentKerjaanStatusFilter !== 'all') tasksToDisplay = tasksToDisplay.filter(task => task.status === currentKerjaanStatusFilter);
        if (currentKerjaanMonthFilter !== 'all') {
            tasksToDisplay = tasksToDisplay.filter(task => {
                const dateToCheck = task.tenggatSortable ? task.tenggatSortable.split(' ')[0] : (task.createdAt ? task.createdAt.split('T')[0] : null);
                return dateToCheck && dateToCheck.startsWith(currentKerjaanMonthFilter);
            });
        }
        switch (currentKerjaanSortCriteria) {
            case 'createdAt_desc': tasksToDisplay.sort((a,b) => (b.createdAt || '').localeCompare(a.createdAt || '')); break;
            case 'createdAt_asc': tasksToDisplay.sort((a,b) => (a.createdAt || '').localeCompare(b.createdAt || '')); break;
            case 'tenggat_asc': tasksToDisplay.sort((a,b) => (a.tenggatSortable || 'z').localeCompare(b.tenggatSortable || 'z')); break;
            case 'tenggat_desc': tasksToDisplay.sort((a,b) => (b.tenggatSortable || '').localeCompare(a.tenggatSortable || '')); break;
            case 'nama_asc': tasksToDisplay.sort((a,b) => (a.nama || '').localeCompare(b.nama || '')); break;
            case 'nama_desc': tasksToDisplay.sort((a,b) => (b.nama || '').localeCompare(a.nama || '')); break;
            case 'status_asc': tasksToDisplay.sort((a,b) => (a.status || '').localeCompare(b.status || '')); break;
        }
        listKerjaanFiltered.innerHTML = '';
        if (tasksToDisplay.length === 0) listKerjaanFiltered.innerHTML = `<li class="no-tasks">Tidak ada kerjaan cocok.</li>`;
        else tasksToDisplay.forEach(kerjaan => { listKerjaanFiltered.appendChild(createTaskListItem(kerjaan, true)); });
    }

    if(filterStatusKerjaanSelect) filterStatusKerjaanSelect.addEventListener('change', (e) => { currentKerjaanStatusFilter = e.target.value; renderKerjaanSection(); });
    if(sortKerjaanSelect) sortKerjaanSelect.addEventListener('change', (e) => { currentKerjaanSortCriteria = e.target.value; renderKerjaanSection(); });
    if(filterBulanKerjaanPageSelect) filterBulanKerjaanPageSelect.addEventListener('change', (e) => { currentKerjaanMonthFilter = e.target.value; renderKerjaanSection(); });

    function populateMonthFilter(tasks, selectElement, dateField) {
        if (!selectElement) return;
        const currentFilterValue = selectElement.value;
        selectElement.innerHTML = '<option value="all">Semua Bulan</option>';
        const months = new Set();
        tasks.forEach(task => {
            const dateSource = task[dateField] ? task[dateField].split(' ')[0] : (task.createdAt ? task.createdAt.split('T')[0] : null);
            if (dateSource) months.add(dateSource.substring(0, 7));
        });
        const sortedMonths = Array.from(months).sort((a,b) => b.localeCompare(a));
        sortedMonths.forEach(monthYear => {
            const option = document.createElement('option'); option.value = monthYear;
            const [year, month] = monthYear.split('-');
            option.textContent = `${namaBulan[parseInt(month) - 1]} ${year}`;
            selectElement.appendChild(option);
        });
        if (Array.from(selectElement.options).some(opt => opt.value === currentFilterValue)) {
            selectElement.value = currentFilterValue;
        }
    }

    openInputModalBtn.onclick = () => {
        formKerjaan.reset(); editTaskIdInput.value = ''; inputModalTitle.textContent = 'Tambah Kerjaan Baru';
        tenggatPicker.value = '';
        statusKerjaanSelect.value = 'belum';
        lampiranFileInput.value = ''; fileWarning.style.display = 'none';
        inputModal.style.display = "flex"; namaPekerjaanInput.focus();
    };
    if(closeInputModalBtn) closeInputModalBtn.onclick = () => { inputModal.style.display = "none"; };
    if(closeDetailModalBtn) closeDetailModalBtn.onclick = () => { detailModal.style.display = "none"; };
    if(closeConfirmDeleteModalBtn) closeConfirmDeleteModalBtn.onclick = () => { confirmDeleteModal.style.display = "none"; };
    if(cancelDeleteBtn) cancelDeleteBtn.onclick = () => { confirmDeleteModal.style.display = "none"; };

    if(confirmDeleteBtn) confirmDeleteBtn.onclick = async () => {
        if (taskIdToDelete !== null) {
            const result = await DataManager.deleteKerjaanById(taskIdToDelete);
            if (result && result.status === 'success') { await initializePage(); }
            else { alert(result.message || 'Gagal menghapus tugas.'); }
        }
        confirmDeleteModal.style.display = "none"; taskIdToDelete = null;
    };
     window.onclick = (event) => {
        if (event.target == inputModal) inputModal.style.display = "none";
        if (event.target == detailModal) detailModal.style.display = "none";
        if (event.target == confirmDeleteModal) confirmDeleteModal.style.display = "none";
    };

    lampiranFileInput.addEventListener('change', function(event) {
        const file = event.target.files[0]; fileWarning.style.display = 'none';
        if (file) {
            if (file.size > 50 * 1024 * 1024) {
                fileWarning.textContent = 'Maks 50MB.'; fileWarning.style.display = 'block'; this.value = ''; return;
            }
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain'];
            if (!allowedTypes.includes(file.type)) { fileWarning.textContent = 'Tipe: JPG, PNG, GIF, PDF, TXT.'; fileWarning.style.display = 'block'; this.value = ''; return;}
            fileWarning.textContent = `File: ${file.name}`; fileWarning.style.color = 'var(--secondary-color)'; fileWarning.style.display = 'block';
        }
    });

    formKerjaan.addEventListener('submit', async function(event) {
        event.preventDefault();
        const taskId = editTaskIdInput.value ? editTaskIdInput.value : null; // Ambil ID sebagai string

        let tenggatSortableValue = "";
        let tenggatDisplayValue = "";
        const tanggalTenggat = tenggatPicker.value;

        if (tanggalTenggat) {
            tenggatSortableValue = `${tanggalTenggat} 00:00`;
            const [year, month, day] = tanggalTenggat.split('-');
            tenggatDisplayValue = `${day}-${month}-${year}`;
        }

        const taskObjectToSave = {
            id: taskId,
            nama: namaPekerjaanInput.value.trim(),
            detail: detailPekerjaanInput.value.trim(),
            tenggatDisplay: tenggatDisplayValue,
            tenggatSortable: tenggatSortableValue,
            status: statusKerjaanSelect.value,

        };
        
        if (!taskObjectToSave.nama || !taskObjectToSave.detail) { alert('Nama & Detail wajib!'); return; }

        const lampiranFileObject = lampiranFileInput.files[0] || null;
        if (taskId) {
            const existingTask = masterTasks.find(k => k.id === taskId);
            if (existingTask) {
                taskObjectToSave.createdAt = existingTask.createdAt;
                if (!lampiranFileObject) {
                    taskObjectToSave.lampiranPath = existingTask.lampiranPath;
                    taskObjectToSave.lampiranNamaOriginal = existingTask.lampiranNamaOriginal;
                }
            }
        } else { taskObjectToSave.createdAt = new Date().toISOString(); }

        const result = await DataManager.saveOrUpdateKerjaan(taskObjectToSave, lampiranFileObject);
        if (result && result.status === 'success') { inputModal.style.display = "none"; await initializePage(); }
        else { alert(result.message || 'Error menyimpan.'); }
    });

    function getStatusText(statusKey) { const map = {'belum': 'Belum Dikerjakan', 'proses': 'Proses', 'selesai': 'Selesai'}; return map[statusKey] || 'N/A'; }
    function escapeHtml(unsafe) { if (unsafe === null || unsafe === undefined) return ''; return unsafe.toString().replace(/[&<"']/g, m => ({'&': '&amp;', '<': '&lt;', '"': '&quot;', "'": '&#039;'})[m]); }
    // Fungsi getStatusTextPerulangan dihapus total
    // function getStatusTextPerulangan(tipe) { ... }

    function createTaskListItem(kerjaan, includeActions = false) {
        const listItem = document.createElement('li'); listItem.classList.add('task-item');
        const isOverdue = kerjaan.tenggatSortable && kerjaan.tenggatSortable.split(' ')[0] < new Date().toISOString().split('T')[0] && kerjaan.status !== 'selesai';
        if (isOverdue) listItem.classList.add('task-overdue');
        const displayId = kerjaan.id; // Karena tidak ada lagi instance, langsung pakai ID task master
        listItem.setAttribute('data-id', displayId);
        listItem.addEventListener('click', (e) => { if (!e.target.closest('.btn-action')) showDetailModal(displayId); });
        let statusBadge = `<span class="task-status status-${kerjaan.status}">${getStatusText(kerjaan.status)}</span>`;
        let deadlineText = kerjaan.tenggatDisplay ? `Tenggat: ${escapeHtml(kerjaan.tenggatDisplay)}` : 'Tanpa Tenggat';
        
        // recurrenceText dan instanceIndicator dihapus
        // let recurrenceText = "";
        // let instanceIndicator = '';

        let actionsHTML = '';
        if (includeActions) {
            const targetIdForActions = kerjaan.id; // Selalu pakai ID task master
            const masterTaskName = kerjaan.nama; // Nama tugas master
            actionsHTML = `<div class="task-actions mt-1 d-flex" style="gap:8px;"><button class="btn btn-primary btn-sm btn-action" data-action="edit" data-task-id="${targetIdForActions}">Edit</button><button class="btn btn-danger btn-sm btn-action" data-action="delete" data-task-id="${targetIdForActions}" data-task-name="${escapeHtml(masterTaskName)}">Hapus</button></div>`;
        }
        // HTML yang dirender di sini juga diubah untuk menghilangkan recurrenceText dan instanceIndicator
        listItem.innerHTML = `<strong>${escapeHtml(kerjaan.nama)}</strong><div class="task-meta"><span class="deadline">${deadlineText}</span>${statusBadge}</div><p class="detail-preview">${escapeHtml(kerjaan.detail)}</p>${actionsHTML}`;
        listItem.querySelectorAll('.btn-action').forEach(button => {
            button.addEventListener('click', (e) => {
                e.stopPropagation(); const action = e.target.dataset.action;
                const currentTaskId = e.target.dataset.taskId;
                if (action === 'delete') { taskIdToDelete = currentTaskId; taskNameToDeleteSpan.innerHTML = e.target.dataset.taskName; confirmDeleteModal.style.display = "flex"; }
                else if (action === 'edit') loadTaskForEdit(currentTaskId);
            });
        });
        return listItem;
    }

    async function loadTaskForEdit(taskId) {
        const task = masterTasks.find(k => k.id === taskId);
        if (!task) { alert("Tugas tidak ditemukan."); return; }
        editTaskIdInput.value = task.id; inputModalTitle.textContent = 'Edit Kerjaan';
        namaPekerjaanInput.value = task.nama; detailPekerjaanInput.value = task.detail;
        if (task.tenggatSortable) {
            const [datePart, timePart] = task.tenggatSortable.split(' ');
            tenggatPicker.value = datePart || '';
        } else { tenggatPicker.value = ''; }
        statusKerjaanSelect.value = task.status;
        lampiranFileInput.value = ''; fileWarning.style.display = 'none';
        if (task.lampiranNamaOriginal) { fileWarning.textContent = `Lampiran: ${escapeHtml(task.lampiranNamaOriginal)}. Pilih baru utk ganti.`; fileWarning.style.color = 'var(--secondary-color)'; fileWarning.style.display = 'block';}
        inputModal.style.display = 'flex';
    }

    async function showDetailModal(taskId) {
        const kerjaan = masterTasks.find(k => k.id === taskId); // Cari di masterTasks
        if (!kerjaan) { alert("Detail tugas tidak ditemukan."); return; }

        if(detailModalTitle) detailModalTitle.textContent = `Detail Kerjaan`;
        document.getElementById('detailNama').textContent = kerjaan.nama;
        document.getElementById('detailDeskripsi').textContent = kerjaan.detail;
        document.getElementById('detailTenggat').textContent = kerjaan.tenggatDisplay || 'Tidak ditentukan';
        const detailStatusEl = document.getElementById('detailStatus');
        detailStatusEl.textContent = getStatusText(kerjaan.status); detailStatusEl.className = `detail-value status-${kerjaan.status}`;
        
        const detailPerulanganEl = document.getElementById('detailPerulangan');
        detailPerulanganEl.textContent = 'Sekali Saja'; // Selalu tampilkan ini karena tidak ada perulangan

        document.getElementById('detailNamaLampiran').textContent = kerjaan.lampiranNamaOriginal || 'Tidak ada';
        const previewArea = document.getElementById('attachmentPreviewArea'); previewArea.innerHTML = '';
        if (kerjaan.lampiranPath && kerjaan.lampiranNamaOriginal) {
            const fileUrl = kerjaan.lampiranPath; const fileTypeHint = kerjaan.lampiranPath.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].includes(fileTypeHint)) { const img = document.createElement('img'); img.src = fileUrl; img.alt = kerjaan.lampiranNamaOriginal; previewArea.appendChild(img); }
            else if (fileTypeHint === 'pdf') { previewArea.innerHTML = `<p><a href="${fileUrl}" target="_blank" class="btn btn-primary btn-sm">Lihat PDF: ${escapeHtml(kerjaan.lampiranNamaOriginal)}</a></p>`; }
            else if (fileTypeHint === 'txt') {
                try { const response = await fetch(fileUrl); if (response.ok) { const textContent = await response.text(); const pre = document.createElement('pre'); pre.textContent = textContent; previewArea.appendChild(pre); } else { previewArea.innerHTML = `<p class="text-center"><a href="${fileUrl}" target="_blank">Lihat ${escapeHtml(kerjaan.lampiranNamaOriginal)}</a> (Gagal preview)</p>`; }}
                catch (e) { previewArea.innerHTML = `<p class="text-center"><a href="${fileUrl}" target="_blank">Lihat ${escapeHtml(kerjaan.lampiranNamaOriginal)}</a> (Error preview)</p>`;}
            } else { previewArea.innerHTML = `<p class="text-center"><a href="${fileUrl}" target="_blank">Unduh: ${escapeHtml(kerjaan.lampiranNamaOriginal)}</a> (Preview tidak didukung)</p>`;}
        } else previewArea.innerHTML = '<p class="text-center">Tidak ada lampiran.</p>';
        detailModal.style.display = 'flex';
    }

    function renderPieChart(sourceTasks) {
        const ctx = document.getElementById('statusPieChart').getContext('2d');
        const counts = sourceTasks.reduce((acc, k) => { acc[k.status] = (acc[k.status] || 0) + 1; return acc; }, {});
        const data = { labels: ['Belum Dikerjakan', 'Proses', 'Selesai'],
            datasets: [{ data: [counts.belum || 0, counts.proses || 0, counts.selesai || 0],
                backgroundColor: [getComputedStyle(document.documentElement).getPropertyValue('--status-belum').trim(), getComputedStyle(document.documentElement).getPropertyValue('--status-proses').trim(), getComputedStyle(document.documentElement).getPropertyValue('--status-selesai').trim()],
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg-color').trim(),
                borderWidth: 3, hoverOffset: 10, hoverBorderColor: getComputedStyle(document.documentElement).getPropertyValue('--dark-color').trim(), hoverBorderWidth: 1
            }]};
        if (statusPieChartInstance) { statusPieChartInstance.data = data; statusPieChartInstance.update(); }
        else { statusPieChartInstance = new Chart(ctx, { type: 'doughnut', data: data, options: { responsive: true, maintainAspectRatio: false, animation: { animateScale: true, animateRotate: true }, plugins: { legend: { position: 'bottom', labels: { font: { family: getComputedStyle(document.documentElement).getPropertyValue('--font-family-sans-serif').trim(), size: 13 }, padding: 20, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 18, boxHeight: 12 }}, title: { display: false }, tooltip: { bodyFont: { family: getComputedStyle(document.documentElement).getPropertyValue('--font-family-sans-serif').trim() }, backgroundColor: 'rgba(0,0,0,0.8)', titleFont: { weight: 'bold', size: 14 }, bodySpacing: 4, padding: 12, cornerRadius: 6, displayColors: true, callbacks: { label: (ctx) => `${ctx.label || ''}: ${ctx.parsed || 0}` }}}, cutout: '70%'}}); }
    }

    async function renderCalendar(month, year) {
        if (!calendarView || !currentMonthYearDisplay) return;
        const tasksToDisplayInCalendar = masterTasks; // Hanya tampilkan masterTasks
        calendarView.innerHTML = '';
        currentMonthYearDisplay.textContent = `${namaBulan[month]} ${year}`;
        const firstDayOfMonth = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const table = document.createElement('table'); const thead = document.createElement('thead'); const tbody = document.createElement('tbody');
        const headerRow = document.createElement('tr');
        namaHari.forEach(dayName => { const th = document.createElement('th'); th.textContent = dayName; headerRow.appendChild(th); });
        thead.appendChild(headerRow); table.appendChild(thead);
        let date = 1;
        for (let i = 0; i < 6; i++) {
            const weekRow = document.createElement('tr');
            for (let j = 0; j < 7; j++) {
                const cell = document.createElement('td');
                if (i === 0 && j < firstDayOfMonth) { cell.classList.add('other-month'); }
                else if (date > daysInMonth) { cell.classList.add('other-month'); }
                else {
                    const dateNumberSpan = document.createElement('span'); dateNumberSpan.classList.add('date-number'); dateNumberSpan.textContent = date; cell.appendChild(dateNumberSpan);
                    const today = new Date();
                    if (date === today.getDate() && year === today.getFullYear() && month === today.getMonth()) cell.classList.add('today');
                    const currentDateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(date).padStart(2, '0')}`;
                    const tasksOnThisDate = tasksToDisplayInCalendar.filter(task => task.tenggatSortable && task.tenggatSortable.startsWith(currentDateStr));
                    if (tasksOnThisDate.length > 0) {
                        const taskListDiv = document.createElement('div'); taskListDiv.classList.add('calendar-tasks-wrapper');
                        tasksOnThisDate.forEach(task => {
                            const taskDiv = document.createElement('div'); taskDiv.classList.add('calendar-task', `status-${task.status}`);
                            taskDiv.textContent = task.nama; taskDiv.title = `${task.nama} (Status: ${getStatusText(task.status)})`;
                            const isTaskOverdue = task.tenggatSortable && task.tenggatSortable.split(' ')[0] < new Date().toISOString().split('T')[0] && task.status !== 'selesai';
                            if (isTaskOverdue) taskDiv.classList.add('task-overdue');
                            taskDiv.addEventListener('click', () => { showDetailModal(task.id); }); // Gunakan task.id
                            taskListDiv.appendChild(taskDiv);
                        });
                        cell.appendChild(taskListDiv);
                    }
                    date++;
                }
                weekRow.appendChild(cell);
            }
            tbody.appendChild(weekRow);
            if (date > daysInMonth && i >= Math.ceil((firstDayOfMonth + daysInMonth) / 7) -1) break;
        }
        table.appendChild(tbody); calendarView.appendChild(table);
    }
    if (prevMonthBtn && nextMonthBtn && calendarView) {
        prevMonthBtn.addEventListener('click', async () => { calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() - 1); await renderCalendar(calendarCurrentDate.getMonth(), calendarCurrentDate.getFullYear()); });
        nextMonthBtn.addEventListener('click', async () => { calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + 1); await renderCalendar(calendarCurrentDate.getMonth(), calendarCurrentDate.getFullYear()); });
    }

    function getWeekRange(date) {
        const d = new Date(date); d.setHours(0,0,0,0); const day = d.getDay(); const diff = d.getDate() - day + (day === 0 ? -6 : 1);
        const start = new Date(d.setDate(diff)); const end = new Date(start); end.setDate(start.getDate() + 6); end.setHours(23,59,59,999);
        return {start: start.toISOString().split('T')[0], end: end.toISOString().split('T')[0]};
    }
    function getMonthRange(date) {
        const d = new Date(date); const start = new Date(d.getFullYear(), d.getMonth(), 1); const end = new Date(d.getFullYear(), d.getMonth() + 1, 0); end.setHours(23,59,59,999);
        return {start: start.toISOString().split('T')[0], end: end.toISOString().split('T')[0]};
    }

    function filterTasksByPeriodForExport(tasksToFilter, period) {
        const today = new Date(); if (period === "all") return tasksToFilter; let range;
        if (period === "this_week") range = getWeekRange(today); else if (period === "this_month") range = getMonthRange(today); else return [];
        return tasksToFilter.filter(task => {
            const taskDateStr = task.tenggatSortable ? task.tenggatSortable.split(' ')[0] : (task.createdAt ? task.createdAt.split('T')[0] : null);
            return taskDateStr && taskDateStr >= range.start && taskDateStr <= range.end;
        });
    }
    function convertToCSV(tasks) {
        if (!tasks || tasks.length === 0) return "";
        // Hapus header "Perulangan"
        const headers = ["ID", "Nama Kerjaan", "Detail", "Status", "Tenggat Waktu", "Lampiran", "Dibuat Pada"];
        const csvRows = [headers.join(",")];
        tasks.forEach(task => {
            // Hilangkan logika perulangan
            const row = [
                task.id, // Hanya ID master
                `"${(task.nama || "").replace(/"/g, '""')}"`,
                `"${(task.detail || "").replace(/"/g, '""')}"`,
                getStatusText(task.status),
                task.tenggatDisplay || "",
                task.lampiranNamaOriginal || "",
                new Date(task.createdAt).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })
            ];
            csvRows.push(row.join(","));
        });
        return csvRows.join("\n");
    }
    function convertToHTMLReport(tasks, periodTitle) {
         let taskRows = tasks && tasks.length > 0 ? tasks.map(task => {
            // Hilangkan logika perulangan dan instance
            return `<tr><td>${task.id}</td><td>${escapeHtml(task.nama)}</td><td style="max-width:300px;word-wrap:break-word;">${escapeHtml(task.detail)}</td><td>${getStatusText(task.status)}</td><td>${escapeHtml(task.tenggatDisplay) || '-'}</td><td>${escapeHtml(task.lampiranNamaOriginal) || '-'}</td><td>${new Date(task.createdAt).toLocaleDateString('id-ID')}</td></tr>`
         }).join('') : '<tr><td colspan="7" style="text-align:center;">Tidak ada data.</td></tr>'; // Ubah colspan
         // Hapus kolom perulangan dari thead
         return `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Laporan Kerjaan - ${periodTitle}</title><style>body{font-family:Arial,sans-serif;margin:20px}h1{text-align:center;color:#333}table{width:100%;border-collapse:collapse;margin-top:20px;font-size:.9em}th,td{border:1px solid #ddd;padding:10px;text-align:left}th{background-color:#f2f2f2;color:#333}tr:nth-child(even){background-color:#f9f9f9}</style></head><body><h1>Laporan Kerjaan - ${periodTitle}</h1><table><thead><tr><th>ID</th><th>Nama Kerjaan</th><th>Detail</th><th>Status</th><th>Tenggat/Mulai</th><th>Lampiran</th><th>Dibuat</th></tr></thead><tbody>${taskRows}</tbody></table><p style="text-align:center;margin-top:20px;font-size:.8em">Laporan dibuat pada: ${new Date().toLocaleString('id-ID')}</p></body></html>`;
    }
    function triggerDownload(filename, content, mimeType) {
        const blob = new Blob([content], { type: mimeType }); const link = document.createElement("a");
        link.href = URL.createObjectURL(blob); link.download = filename;
        document.body.appendChild(link); link.click(); document.body.removeChild(link); URL.revokeObjectURL(link.href);
    }

    if(exportBtn) exportBtn.addEventListener('click', async () => {
        const period = exportPeriodSelect.value; const format = exportFormatSelect.value;
        const tasksToFilterForExport = masterTasks; // Hanya masterTasks
        const tasksToExport = filterTasksByPeriodForExport(tasksToFilterForExport, period);
        if (tasksToExport.length === 0) { alert("Tidak ada data untuk diekspor."); return; }
        let content, filename, mimeType;
        const periodTextMap = { "all": "Semua", "this_week": "Minggu Ini", "this_month": "Bulan Ini" };
        const periodTitle = periodTextMap[period] || "Data"; const dateSuffix = new Date().toISOString().split('T')[0];
        if (format === "json") { content = JSON.stringify(tasksToExport, null, 2); filename = `kerjaan_${period.replace('_','-')}_${dateSuffix}.json`; mimeType = "application/json"; }
        else if (format === "csv") { content = convertToCSV(tasksToExport); filename = `kerjaan_${period.replace('_','-')}_${dateSuffix}.csv`; mimeType = "text/csv;charset=utf-8;"; }
        else if (format === "html") { content = convertToHTMLReport(tasksToExport, periodTitle); filename = `laporan_kerjaan_${period.replace('_','-')}_${dateSuffix}.html`; mimeType = "text/html;charset=utf-8;"; }
        if (content) triggerDownload(filename, content, mimeType);
    });

    initializePage();
});