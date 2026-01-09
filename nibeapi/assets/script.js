/**
 * JavaScript für Nibe API Dashboard - Version 3.4.00
 * assets/script.js - KOMPLETT mit Device-Support
 */

// ===================================
// Globale Variablen
// ===================================
let tableData = [];
let autoUpdateEnabled = true;
let updateInterval = null;
let hideValues = [];
let currentApiUpdateInterval = 10000;
let availableIntervals = {};
let USE_DB = false; // KORRIGIERT: USE_DB statt USE_DB_ENABLED
let currentDeviceId = 0;

// Sortierung
let currentSortColumn = null;
let currentSortDirection = null;

// Import/Edit
let selectedFile = null;
let currentEditVariableId = null;
let currentEditValueCell = null;

// Menüpunkt
let currentMenuepunktVariableId = null;

// ===================================
// Initialisierung
// ===================================

function initializeApp(data, config) {
    tableData = data;
    hideValues = config.hideValues;
    currentApiUpdateInterval = config.apiUpdateInterval;
    availableIntervals = config.availableIntervals;
    USE_DB = config.useDb; // KORRIGIERT
    currentDeviceId = config.deviceId || 0;
    
    console.log('App initialisiert:', {
        dataPoints: tableData.length,
        useDb: USE_DB,
        deviceId: currentDeviceId
    });
    
    attachEventListeners();
    initRegisterTypeFilter();
    updateCounts();
    filterTable();
    updateSelectAllState();
    loadSortState();
    document.getElementById('lastUpdate').textContent = new Date().toLocaleString('de-DE');
    startAutoUpdate();
}

function attachEventListeners() {
    document.getElementById('filterVariableId').addEventListener('input', filterTable);
    document.getElementById('filterModbusRegisterID').addEventListener('input', filterTable);
    document.getElementById('filterMenuepunkt').addEventListener('input', filterTable);
    document.getElementById('filterTitle').addEventListener('input', filterTable);
    document.getElementById('filterRegisterType').addEventListener('change', filterTable);
    document.getElementById('filterValue').addEventListener('input', filterTable);
    document.getElementById('filterSelectedOnly').addEventListener('change', filterTable);
    document.getElementById('hideValuesActive').addEventListener('change', filterTable);
    document.getElementById('updateInterval').addEventListener('change', changeUpdateInterval);
    
    document.getElementById('historyModal').addEventListener('click', function(e) {
        if (e.target === this) closeHistory();
    });
    
    document.getElementById('importModal').addEventListener('click', function(e) {
        if (e.target === this) closeImportDialog();
    });
    
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditDialog();
    });
    
    document.getElementById('menuepunktModal').addEventListener('click', function(e) {
        if (e.target === this) closeMenuepunktDialog();
    });
    
    document.getElementById('editInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') saveEditValue();
    });
    
    document.getElementById('editInput').addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditDialog();
    });
    
    window.addEventListener('beforeunload', stopAutoUpdate);
}

// ===================================
// Sortierung
// ===================================

function loadSortState() {
    try {
        const savedColumn = localStorage.getItem('sortColumn');
        const savedDirection = localStorage.getItem('sortDirection');
        if (savedColumn !== null && savedDirection !== null) {
            currentSortColumn = parseInt(savedColumn);
            currentSortDirection = savedDirection;
            applySortIndicator();
            sortTable(currentSortColumn, true);
        }
    } catch (e) {
        console.error('Fehler beim Laden der Sortierung:', e);
    }
}

function saveSortState() {
    try {
        if (currentSortColumn !== null && currentSortDirection !== null) {
            localStorage.setItem('sortColumn', currentSortColumn);
            localStorage.setItem('sortDirection', currentSortDirection);
        }
    } catch (e) {
        console.error('Fehler beim Speichern der Sortierung:', e);
    }
}

function applySortIndicator() {
    const headers = document.querySelectorAll('th.sortable');
    headers.forEach((th, idx) => {
        th.classList.remove('sorted-asc', 'sorted-desc');
        if (idx + 1 === currentSortColumn) {
            th.classList.add(currentSortDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');
        }
    });
}

function sortTable(columnIndex, silent = false) {
    const tbody = document.getElementById('tableBody');
    const rows = Array.from(tbody.rows);
    
    if (!silent) {
        if (currentSortColumn === columnIndex) {
            currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortColumn = columnIndex;
            currentSortDirection = 'asc';
        }
        saveSortState();
    }
    
    const direction = currentSortDirection;
    
    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent.trim();
        const bValue = b.cells[columnIndex].textContent.trim();
        const aNum = parseFloat(aValue);
        const bNum = parseFloat(bValue);
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return direction === 'asc' ? aNum - bNum : bNum - aNum;
        }
        return direction === 'asc' ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
    });
    
    rows.forEach(row => tbody.appendChild(row));
    applySortIndicator();
}

// ===================================
// Filter & Suche
// ===================================

function initRegisterTypeFilter() {
    const types = new Set();
    tableData.forEach(point => {
        if (point.modbusregistertype) {
            types.add(point.modbusregistertype);
        }
    });
    const select = document.getElementById('filterRegisterType');
    const currentValue = select.value;
    select.innerHTML = '<option value="">Alle</option>';
    types.forEach(type => {
        const option = document.createElement('option');
        option.value = type;
        if (type === 'MODBUS_INPUT_REGISTER') {
            option.textContent = 'Input (Input Register)';
        } else if (type === 'MODBUS_HOLDING_REGISTER') {
            option.textContent = 'Holding (Holding Register)';
        } else {
            option.textContent = type;
        }
        if (type === currentValue) option.selected = true;
        select.appendChild(option);
    });
}

function filterTable() {
    const filters = {
        variableId: document.getElementById('filterVariableId').value.toLowerCase(),
        modbusRegisterID: document.getElementById('filterModbusRegisterID').value.toLowerCase(),
        menuepunkt: document.getElementById('filterMenuepunkt').value.toLowerCase(),
        title: document.getElementById('filterTitle').value.toLowerCase(),
        registerType: document.getElementById('filterRegisterType').value,
        value: document.getElementById('filterValue').value.toLowerCase(),
        selectedOnly: document.getElementById('filterSelectedOnly').checked,
        hideValuesActive: document.getElementById('hideValuesActive').checked
    };
    
    const tbody = document.getElementById('tableBody');
    let visibleCount = 0;
    
    Array.from(tbody.rows).forEach(row => {
        const cells = row.cells;
        if (cells.length === 0) return;
        
        const checkbox = row.querySelector('.row-checkbox');
        const variableId = row.dataset.variableid;
        const point = tableData.find(p => p.variableid == variableId);
        const menuepunkt = row.dataset.menuepunkt || '';
        
        const matches = {
            variableId: cells[1].textContent.toLowerCase().includes(filters.variableId),
            modbusRegisterID: cells[2].textContent.toLowerCase().includes(filters.modbusRegisterID),
            menuepunkt: menuepunkt.toLowerCase().includes(filters.menuepunkt),
            title: cells[4].textContent.toLowerCase().includes(filters.title),
            registerType: !filters.registerType || (point && point.modbusregistertype === filters.registerType),
            value: cells[6].textContent.toLowerCase().includes(filters.value),
            selected: !filters.selectedOnly || (checkbox && checkbox.checked),
            hideValues: !filters.hideValuesActive || !hideValues.some(v => cells[6].textContent.toLowerCase().includes(v.toLowerCase()))
        };
        
        if (Object.values(matches).every(m => m)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    document.getElementById('visibleCount').textContent = visibleCount;
    updateSelectAllState();
}

function resetFilters() {
    document.getElementById('filterVariableId').value = '';
    document.getElementById('filterModbusRegisterID').value = '';
    document.getElementById('filterMenuepunkt').value = '';
    document.getElementById('filterTitle').value = '';
    document.getElementById('filterRegisterType').value = '';
    document.getElementById('filterValue').value = '';
    document.getElementById('filterSelectedOnly').checked = false;
    document.getElementById('hideValuesActive').checked = true;
    filterTable();
}

// ===================================
// Checkbox & Select All
// ===================================

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') cb.checked = selectAll.checked;
    });
}

function updateSelectAllState() {
    const checkboxes = Array.from(document.querySelectorAll('.row-checkbox')).filter(cb => cb.closest('tr').style.display !== 'none');
    const checkedCount = checkboxes.filter(cb => cb.checked).length;
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
}

// ===================================
// Auto-Update
// ===================================

function startAutoUpdate() {
    if (updateInterval) clearInterval(updateInterval);
    updateInterval = setInterval(fetchData, currentApiUpdateInterval);
    autoUpdateEnabled = true;
    document.getElementById('toggleButton').textContent = 'Pause';
    document.getElementById('toggleButton').classList.remove('paused');
    document.getElementById('liveIndicator').classList.remove('error');
}

function stopAutoUpdate() {
    if (updateInterval) {
        clearInterval(updateInterval);
        updateInterval = null;
    }
    autoUpdateEnabled = false;
    document.getElementById('toggleButton').textContent = 'Start';
    document.getElementById('toggleButton').classList.add('paused');
}

function toggleAutoUpdate() {
    autoUpdateEnabled ? stopAutoUpdate() : startAutoUpdate();
}

function changeUpdateInterval() {
    const select = document.getElementById('updateInterval');
    currentApiUpdateInterval = parseInt(select.value);
    
    if (autoUpdateEnabled) {
        stopAutoUpdate();
        startAutoUpdate();
    }
}

// ===================================
// API Kommunikation
// ===================================

async function fetchData() {
    try {
        const response = await fetch('?ajax=fetch&deviceId=' + currentDeviceId);
        const result = await response.json();
        if (result.success) {
            updateTable(result.data);
            document.getElementById('lastUpdate').textContent = result.timestamp;
            document.getElementById('liveIndicator').classList.remove('error');
            clearError();
        } else {
            showError('Fehler beim Laden: ' + result.error);
            document.getElementById('liveIndicator').classList.add('error');
        }
    } catch (error) {
        showError('Verbindungsfehler: ' + error.message);
        document.getElementById('liveIndicator').classList.add('error');
    }
}

function updateTable(newData) {
    tableData = newData;
    const tbody = document.getElementById('tableBody');
    const oldValues = {};
    const oldChecked = {};
    
    tbody.querySelectorAll('tr').forEach(row => {
        const id = row.dataset.variableid;
        const cb = row.querySelector('.row-checkbox');
        if (id && row.cells[6]) oldValues[id] = row.cells[6].textContent.trim();
        if (id && cb) oldChecked[id] = cb.checked;
    });
    
    tbody.innerHTML = '';
    
    newData.forEach(point => {
        const row = tbody.insertRow();
        Object.entries({
            variableid: point.variableid,
            variabletype: point.variableType,
            variablesize: point.variableSize,
            divisor: point.divisor,
            decimal: point.decimal,
            minvalue: point.minValue,
            maxvalue: point.maxValue,
            description: point.description || '',
            menuepunkt: point.menuepunkt || ''
        }).forEach(([k, v]) => row.dataset[k] = v);
        
        row.onmouseenter = e => showTooltip(e, row);
        row.onmouseleave = hideTooltip;
        
        const cbCell = row.insertCell(0);
        cbCell.className = 'checkbox-cell';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'row-checkbox';
        cb.checked = oldChecked[point.variableid] || false;
        cb.onchange = updateSelectAllState;
        cbCell.appendChild(cb);
        
        row.insertCell(1).textContent = point.variableid;
        row.insertCell(2).textContent = point.modbusregisterid;
        
        // Menüpunkt-Zelle
        const menuepunktCell = row.insertCell(3);
        menuepunktCell.className = 'menuepunkt-cell';
        if (USE_DB) { // KORRIGIERT
            const div = document.createElement('div');
            div.className = 'menuepunkt-display';
            const span = document.createElement('span');
            span.className = 'menuepunkt-value';
            span.textContent = point.menuepunkt || '-';
            const btn = document.createElement('button');
            btn.className = 'btn-menu';
            btn.textContent = '📝';
            btn.title = 'Menüpunkt bearbeiten';
            btn.onclick = () => editMenuepunkt(point.variableid, point.title, point.menuepunkt || '');
            div.appendChild(span);
            div.appendChild(btn);
            menuepunktCell.appendChild(div);
        } else {
            menuepunktCell.textContent = '-';
        }
        
        row.insertCell(4).textContent = point.title;
        row.insertCell(5).textContent = point.modbusregistertype === 'MODBUS_INPUT_REGISTER' ? 'Input' : (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' ? 'Holding' : point.modbusregistertype);
        
        const valueCell = row.insertCell(6);
        valueCell.className = 'value-cell';
        
        if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
            const div = document.createElement('div');
            div.className = 'editable-value';
            const span = document.createElement('span');
            span.className = 'value-display';
            span.textContent = point.value;
            const btn = document.createElement('button');
            btn.className = 'btn-edit';
            btn.textContent = '✏️';
            btn.onclick = () => editValue(point.variableid, point.title);
            div.appendChild(span);
            div.appendChild(btn);
            valueCell.appendChild(div);
            valueCell.dataset.rawvalue = point.rawvalue;
            valueCell.dataset.divisor = point.divisor;
            valueCell.dataset.decimal = point.decimal;
            valueCell.dataset.unit = point.unit;
        } else {
            valueCell.textContent = point.value;
        }
        
        if (USE_DB) { // KORRIGIERT
            const historyCell = row.insertCell();
            historyCell.style.textAlign = 'center';
            
            if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
                const historyBtn = document.createElement('button');
                historyBtn.className = 'btn-edit';
                historyBtn.textContent = '📜';
                historyBtn.onclick = () => showHistory(point.variableid, point.title);
                historyCell.appendChild(historyBtn);
            } else {
                historyCell.textContent = '-';
            }
        }
        
        if (oldValues[point.variableid] && oldValues[point.variableid] !== point.value) {
            valueCell.classList.add('value-changed');
            setTimeout(() => valueCell.classList.remove('value-changed'), 2000);
        }
    });
    
    initRegisterTypeFilter();
    filterTable();
    updateCounts();
    updateSelectAllState();
    
    if (currentSortColumn !== null) {
        sortTable(currentSortColumn, true);
    }
}

function updateCounts() {
    document.getElementById('totalCount').textContent = tableData.length;
}

// ===================================
// Fehlerbehandlung
// ===================================

function showError(message) {
    document.getElementById('errorContainer').innerHTML = `<div class="error"><strong>Fehler:</strong> ${message}</div>`;
}

function clearError() {
    document.getElementById('errorContainer').innerHTML = '';
}

// ===================================
// Tooltip
// ===================================

function showTooltip(event, row) {
    if (!document.getElementById('showTooltips').checked) return;
    const tooltip = document.getElementById('rowTooltip');
    const description = row.dataset.description || '-';
    tooltip.innerHTML = `
        <div class="tooltip-row"><span class="tooltip-label">Description:</span><span class="tooltip-value">${description}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Variable Type:</span><span class="tooltip-value">${row.dataset.variabletype}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Variable Size:</span><span class="tooltip-value">${row.dataset.variablesize}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Divisor:</span><span class="tooltip-value">${row.dataset.divisor}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Decimal:</span><span class="tooltip-value">${row.dataset.decimal}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Min Value:</span><span class="tooltip-value">${row.dataset.minvalue}</span></div>
        <div class="tooltip-row"><span class="tooltip-label">Max Value:</span><span class="tooltip-value">${row.dataset.maxvalue}</span></div>
    `;
    
    const rect = row.getBoundingClientRect();
    tooltip.style.left = rect.left + 'px';
    tooltip.style.top = (rect.bottom + 5) + 'px';
    tooltip.classList.add('show');
    
    const tooltipRect = tooltip.getBoundingClientRect();
    if (tooltipRect.right > window.innerWidth) {
        tooltip.style.left = (window.innerWidth - tooltipRect.width - 10) + 'px';
    }
    
    if (tooltipRect.bottom > window.innerHeight) {
        tooltip.style.top = (rect.top - tooltipRect.height - 5) + 'px';
    }
}

function hideTooltip() {
    document.getElementById('rowTooltip').classList.remove('show');
}

// Fortsetzung in Teil 2...

// ===================================
// History Modal
// ===================================

async function showHistory(variableId, title) {
    if (!USE_DB) { // KORRIGIERT
        alert('Datenbank-Funktionen sind deaktiviert');
        return;
    }
    
    document.getElementById('historyModal').classList.add('show');
    document.getElementById('historyTitle').textContent = title + ' (API ID: ' + variableId + ')';
    document.getElementById('historyTableBody').innerHTML = '<tr><td colspan="4" style="text-align: center;">Lade Daten...</td></tr>';
    
    try {
        const response = await fetch('?ajax=history&apiId=' + variableId);
        const result = await response.json();
        
        if (result.success) {
            displayHistoryData(result.history, variableId);
        } else {
            document.getElementById('historyTableBody').innerHTML = 
                '<tr><td colspan="4" class="no-data error">Fehler: ' + result.error + '</td></tr>';
        }
    } catch (error) {
        document.getElementById('historyTableBody').innerHTML = 
            '<tr><td colspan="4" class="no-data error">Verbindungsfehler: ' + error.message + '</td></tr>';
    }
}

function displayHistoryData(historyData, apiId) {
    const tbody = document.getElementById('historyTableBody');
    
    if (!historyData || historyData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="no-data">Keine History-Daten vorhanden</td></tr>';
        return;
    }
    
    const currentPoint = tableData.find(p => p.variableid == apiId);
    const divisor = currentPoint ? (currentPoint.divisor || 1) : 1;
    const decimal = currentPoint ? (currentPoint.decimal || 0) : 0;
    const unit = currentPoint ? (currentPoint.unit || '') : '';
    
    tbody.innerHTML = '';
    
    historyData.forEach(entry => {
        const row = tbody.insertRow();
        
        row.insertCell(0).textContent = entry.zeitstempel;
        
        let displayValue = entry.wert;
        if (divisor > 1) {
            displayValue = (entry.wert / divisor).toFixed(decimal).replace('.', ',');
        }
        displayValue += (unit ? ' ' + unit : '');
        row.insertCell(1).textContent = displayValue;
        
        const manualCell = row.insertCell(2);
        if (entry.cwna === 'X') {
            manualCell.innerHTML = '<span class="cwna-badge">MANUELL</span>';
        } else if (entry.cwna === 'I') {
            manualCell.innerHTML = '<span class="cwna-badge import">IMPORT</span>';
        } else {
            manualCell.textContent = '-';
        }
        
        const actionCell = row.insertCell(3);
        if (entry.cwna === 'X') {
            const undoBtn = document.createElement('button');
            undoBtn.className = 'btn-undo';
            undoBtn.textContent = '↩️ Wiederherstellen';
            undoBtn.onclick = () => restoreHistoryValue(apiId, entry.wert);
            actionCell.appendChild(undoBtn);
        } else {
            actionCell.textContent = '-';
        }
    });
}

async function restoreHistoryValue(variableId, rawValue) {
    if (!confirm('Möchten Sie diesen Wert wirklich wiederherstellen?')) {
        return;
    }
    
    try {
        const response = await fetch('?ajax=write', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                variableId: variableId, 
                value: rawValue,
                deviceId: currentDeviceId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Wert erfolgreich wiederhergestellt');
            closeHistory();
            setTimeout(fetchData, 500);
        } else {
            alert('❌ Fehler beim Wiederherstellen: ' + result.error);
        }
    } catch (error) {
        alert('❌ Verbindungsfehler: ' + error.message);
    }
}

function closeHistory() {
    document.getElementById('historyModal').classList.remove('show');
}

// ===================================
// Import Modal
// ===================================

function showImportDialog() {
    document.getElementById('importModal').classList.add('show');
    document.getElementById('importUploadArea').style.display = 'block';
    document.getElementById('importResult').style.display = 'none';
    document.getElementById('selectedFileName').textContent = '';
    document.getElementById('btnImportOk').disabled = true;
    selectedFile = null;
}

function closeImportDialog() {
    document.getElementById('importModal').classList.remove('show');
    selectedFile = null;
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (file) {
        selectedFile = file;
        document.getElementById('selectedFileName').textContent = '📄 ' + file.name;
        document.getElementById('btnImportOk').disabled = false;
    }
}

async function processImport() {
    if (!selectedFile) {
        alert('Bitte wählen Sie eine Datei aus');
        return;
    }
    
    document.getElementById('btnImportOk').disabled = true;
    document.getElementById('btnImportOk').textContent = '⏳ Importiere...';
    
    try {
        const fileContent = await selectedFile.text();
        
        const response = await fetch('?ajax=import', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                fileContent: fileContent,
                fileName: selectedFile.name
            })
        });
        
        const result = await response.json();
        displayImportResult(result);
        
    } catch (error) {
        alert('Fehler beim Import: ' + error.message);
        document.getElementById('btnImportOk').disabled = false;
        document.getElementById('btnImportOk').textContent = '✔ OK';
    }
}

function displayImportResult(result) {
    document.getElementById('importUploadArea').style.display = 'none';
    
    const resultDiv = document.getElementById('importResult');
    resultDiv.style.display = 'block';
    
    if (result.success) {
        let html = `
            <div class="import-result success">
                <h3>✔ Import erfolgreich abgeschlossen</h3>
                <p><strong>Datei enthielt:</strong> ${result.totalRecords} Datensätze</p>
                <p><strong>Erfolgreich importiert:</strong> ${result.importedRecords} Datensätze</p>
                <p><strong>Neue Master-Einträge:</strong> ${result.newMasterRecords || 0} Datensätze</p>
        `;
        
        if (result.failedRecords && result.failedRecords.length > 0) {
            html += `
                <p><strong>Nicht importiert:</strong> ${result.failedRecords.length} Datensätze</p>
                <div class="failed-records">
            `;
            result.failedRecords.forEach(failed => {
                html += `<div class="failed-record">Zeile ${failed.line}: ${failed.reason}</div>`;
            });
            html += '</div>';
        }
        
        html += `</div>
            <div class="import-buttons">
                <button class="btn-import-cancel" onclick="closeImportDialog()">Schließen</button>
            </div>
        `;
        
        resultDiv.innerHTML = html;
    } else {
        resultDiv.innerHTML = `
            <div class="import-result error">
                <h3>✗ Import fehlgeschlagen</h3>
                <p>${result.error}</p>
            </div>
            <div class="import-buttons">
                <button class="btn-import-cancel" onclick="closeImportDialog()">Schließen</button>
            </div>
        `;
    }
}

// ===================================
// Edit Modal
// ===================================

function editValue(variableId, title) {
    const point = tableData.find(p => p.variableid == variableId);
    if (!point) {
        alert('Datenpunkt nicht gefunden');
        return;
    }
    
    const row = document.querySelector(`tr[data-variableid="${variableId}"]`);
    if (!row) {
        alert('Zeile nicht gefunden');
        return;
    }
    
    const valueCell = row.cells[6];
    
    currentEditVariableId = variableId;
    currentEditValueCell = valueCell;
    
    const currentValue = valueCell.querySelector('.value-display').textContent;
    const numericValue = currentValue.replace(/[^\d,.-]/g, '').replace(',', '.');
    
    document.getElementById('editTitle').textContent = '📊 ' + title;
    document.getElementById('editApiId').textContent = 'API ID: ' + variableId;
    document.getElementById('editCurrentValue').textContent = currentValue;
    document.getElementById('editInput').value = numericValue;
    
    const minValue = row.dataset.minvalue;
    const maxValue = row.dataset.maxvalue;
    if (minValue && maxValue && minValue != 0 && maxValue != 0) {
        const divisor = parseInt(valueCell.dataset.divisor) || 1;
        const decimal = parseInt(valueCell.dataset.decimal) || 0;
        const displayMin = divisor > 1 ? (minValue / divisor).toFixed(decimal) : minValue;
        const displayMax = divisor > 1 ? (maxValue / divisor).toFixed(decimal) : maxValue;
        document.getElementById('editLimits').textContent = `Erlaubter Bereich: ${displayMin} bis ${displayMax}`;
    } else {
        document.getElementById('editLimits').textContent = '';
    }
    
    document.getElementById('editFormArea').style.display = 'block';
    document.getElementById('editSaving').style.display = 'none';
    document.getElementById('editModal').classList.add('show');
    
    setTimeout(() => {
        const input = document.getElementById('editInput');
        input.focus();
        input.select();
    }, 100);
}

async function saveEditValue() {
    const newValue = document.getElementById('editInput').value;
    
    if (!newValue || newValue.trim() === '') {
        alert('Bitte geben Sie einen Wert ein');
        return;
    }
    
    const valueCell = currentEditValueCell;
    const variableId = currentEditVariableId;
    const divisor = parseInt(valueCell.dataset.divisor) || 1;
    
    document.getElementById('editFormArea').style.display = 'none';
    document.getElementById('editSaving').style.display = 'block';
    
    try {
        const rawValue = Math.round(parseFloat(newValue) * divisor);
        const response = await fetch('?ajax=write', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                variableId: variableId, 
                value: rawValue,
                deviceId: currentDeviceId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeEditDialog();
            
            valueCell.style.background = '#c8e6c9';
            setTimeout(() => valueCell.style.background = '', 1000);
            
            setTimeout(fetchData, 500);
        } else {
            throw new Error(result.error || 'Unbekannter Fehler');
        }
    } catch (error) {
        alert('Fehler beim Speichern: ' + error.message);
        document.getElementById('editFormArea').style.display = 'block';
        document.getElementById('editSaving').style.display = 'none';
    }
}

function closeEditDialog() {
    document.getElementById('editModal').classList.remove('show');
    currentEditVariableId = null;
    currentEditValueCell = null;
}

// ===================================
// Menüpunkt Modal
// ===================================

function editMenuepunkt(variableId, title, currentMenuepunkt) {
    if (!USE_DB) { // KORRIGIERT
        alert('Datenbank-Funktionen sind deaktiviert');
        return;
    }
    
    currentMenuepunktVariableId = variableId;
    
    document.getElementById('menuepunktTitle').textContent = '📊 ' + title;
    document.getElementById('menuepunktApiId').textContent = 'API ID: ' + variableId;
    document.getElementById('menuepunktCurrentValue').textContent = currentMenuepunkt || '-';
    document.getElementById('menuepunktInput').value = currentMenuepunkt || '';
    
    document.getElementById('menuepunktFormArea').style.display = 'block';
    document.getElementById('menuepunktSaving').style.display = 'none';
    document.getElementById('menuepunktModal').classList.add('show');
    
    setTimeout(() => {
        const input = document.getElementById('menuepunktInput');
        input.focus();
        input.select();
    }, 100);
}

async function saveMenuepunkt() {
    const menuepunkt = document.getElementById('menuepunktInput').value.trim();
    const variableId = currentMenuepunktVariableId;
    
    document.getElementById('menuepunktFormArea').style.display = 'none';
    document.getElementById('menuepunktSaving').style.display = 'block';
    
    try {
        const response = await fetch('?ajax=saveMenupunkt', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                apiId: variableId,
                menuepunkt: menuepunkt
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeMenuepunktDialog();
            setTimeout(fetchData, 500);
        } else {
            throw new Error(result.error || 'Unbekannter Fehler');
        }
    } catch (error) {
        alert('Fehler beim Speichern: ' + error.message);
        document.getElementById('menuepunktFormArea').style.display = 'block';
        document.getElementById('menuepunktSaving').style.display = 'none';
    }
}

function closeMenuepunktDialog() {
    document.getElementById('menuepunktModal').classList.remove('show');
    currentMenuepunktVariableId = null;
}
