/**
 * JavaScript für Nibe API Dashboard - Version 3.4.01
 * assets/script.js - MEMORY OPTIMIERT
 * 
 * WICHTIG: Diese Datei gehört nach assets/script.js (NICHT in config.php!)
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
let USE_DB = false;
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

// Memory Management
let eventListeners = new WeakMap(); // Speichert Event Listener Referenzen
let rowCache = new Map(); // Cache für existierende Rows

// ===================================
// Initialisierung
// ===================================

function initializeApp(data, config) {
    tableData = data;
    hideValues = config.hideValues;
    currentApiUpdateInterval = config.apiUpdateInterval;
    availableIntervals = config.availableIntervals;
    USE_DB = config.useDb;
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
    // Event Delegation für bessere Performance
    const tbody = document.getElementById('tableBody');
    
    // WICHTIG: Event Delegation statt individuelle Listener pro Row
    // Entfernt alte Listener falls vorhanden
    const oldHandler = eventListeners.get(tbody);
    if (oldHandler) {
        tbody.removeEventListener('click', oldHandler.click);
        tbody.removeEventListener('mouseenter', oldHandler.mouseenter, true);
        tbody.removeEventListener('mouseleave', oldHandler.mouseleave, true);
        tbody.removeEventListener('change', oldHandler.change);
    }
    
    // Neue Handler Funktionen
    const handlers = {
        click: (e) => {
            const target = e.target;
            
            // Edit Button
            if (target.classList.contains('btn-edit')) {
                const row = target.closest('tr');
                if (row) {
                    const variableId = row.dataset.variableid;
                    const titleCell = row.cells[4];
                    if (target.textContent.includes('📜')) {
                        showHistory(variableId, titleCell ? titleCell.textContent : '');
                    } else {
                        editValue(variableId, titleCell ? titleCell.textContent : '');
                    }
                }
            }
            
            // Menu Button
            if (target.classList.contains('btn-menu')) {
                const row = target.closest('tr');
                if (row) {
                    const variableId = row.dataset.variableid;
                    const titleCell = row.cells[4];
                    const menuepunkt = row.dataset.menuepunkt || '';
                    editMenuepunkt(variableId, titleCell ? titleCell.textContent : '', menuepunkt);
                }
            }
        },
        
        mouseenter: (e) => {
            const row = e.target.closest('tr');
            if (row && row.dataset.variableid) {
                showTooltip(e, row);
            }
        },
        
        mouseleave: (e) => {
            const row = e.target.closest('tr');
            if (row && row.dataset.variableid) {
                hideTooltip();
            }
        },
        
        change: (e) => {
            if (e.target.classList.contains('row-checkbox')) {
                updateSelectAllState();
            }
        }
    };
    
    // Event Delegation attachieren
    tbody.addEventListener('click', handlers.click);
    tbody.addEventListener('mouseenter', handlers.mouseenter, true);
    tbody.addEventListener('mouseleave', handlers.mouseleave, true);
    tbody.addEventListener('change', handlers.change);
    
    // Handlers speichern für späteres Cleanup
    eventListeners.set(tbody, handlers);
    
    // Filter Events
    document.getElementById('filterVariableId').addEventListener('input', filterTable);
    document.getElementById('filterModbusRegisterID').addEventListener('input', filterTable);
    document.getElementById('filterMenuepunkt').addEventListener('input', filterTable);
    document.getElementById('filterTitle').addEventListener('input', filterTable);
    document.getElementById('filterRegisterType').addEventListener('change', filterTable);
    document.getElementById('filterValue').addEventListener('input', filterTable);
    document.getElementById('filterSelectedOnly').addEventListener('change', filterTable);
    document.getElementById('hideValuesActive').addEventListener('change', filterTable);
    document.getElementById('updateInterval').addEventListener('change', changeUpdateInterval);
    
    // Modal Events
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
    
    // Cleanup bei Page Unload
    window.addEventListener('beforeunload', cleanup);
}

// ===================================
// Memory Management & Cleanup
// ===================================

function cleanup() {
    console.log('Cleanup: Räume Speicher auf...');
    
    // Auto-Update stoppen
    stopAutoUpdate();
    
    // Event Listeners entfernen
    const tbody = document.getElementById('tableBody');
    const handlers = eventListeners.get(tbody);
    if (handlers) {
        tbody.removeEventListener('click', handlers.click);
        tbody.removeEventListener('mouseenter', handlers.mouseenter, true);
        tbody.removeEventListener('mouseleave', handlers.mouseleave, true);
        tbody.removeEventListener('change', handlers.change);
    }
    
    // Caches leeren
    rowCache.clear();
    eventListeners = new WeakMap();
    
    // Daten Arrays leeren
    tableData = [];
    
    console.log('Cleanup abgeschlossen');
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
        
        // SPEZIALBEHANDLUNG FÜR MENÜPUNKT-SPALTE (Index 3)
        if (columnIndex === 3) {
            const cmp = compareMenuepunkt(aValue, bValue);
            return direction === 'asc' ? cmp : -cmp;
        }
        
        // Normale Sortierung für andere Spalten
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
        menuepunkt: document.getElementById('filterMenuepunkt').value,
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
            menuepunkt: matchesMenuepunktFilter(menuepunkt, filters.menuepunkt),
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
    clearRowSelection();
    filterTable();
    if (typeof resetSelectionBar === 'function') resetSelectionBar();
}

// Alle Auswahl-Häkchen der Tabelle entfernen
function clearRowSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = false; });
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
}

function compareMenuepunkt(a, b) {
    if (!a && !b) return 0;
    if (!a) return 1;
    if (!b) return -1;
    
    const partsA = a.split('.').map(part => {
        const num = parseInt(part);
        return isNaN(num) ? part : num;
    });
    
    const partsB = b.split('.').map(part => {
        const num = parseInt(part);
        return isNaN(num) ? part : num;
    });
    
    const maxLength = Math.max(partsA.length, partsB.length);
    
    for (let i = 0; i < maxLength; i++) {
        const partA = partsA[i];
        const partB = partsB[i];
        
        if (partA === undefined) return -1;
        if (partB === undefined) return 1;
        
        if (typeof partA === 'number' && typeof partB === 'number') {
            if (partA !== partB) return partA - partB;
        } else if (typeof partA === 'number') {
            return -1;
        } else if (typeof partB === 'number') {
            return 1;
        } else {
            const cmp = partA.localeCompare(partB);
            if (cmp !== 0) return cmp;
        }
    }
    
    return 0;
}

function matchesMenuepunktFilter(menuepunkt, filterValue) {
    if (!filterValue) return true;
    if (!menuepunkt) return false;
    
    const filter = filterValue.trim().toLowerCase();
    const menu = menuepunkt.toLowerCase();
    
    if (filter.startsWith('^') || filter.endsWith('$')) {
        try {
            const regex = new RegExp(filter);
            return regex.test(menu);
        } catch (e) {
            return menu.includes(filter);
        }
    }
    
    if (filter.endsWith('.')) {
        return menu.startsWith(filter);
    }
    
    return menu.includes(filter);
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
    
    const oldState = new Map();
    
    Array.from(tbody.rows).forEach(row => {
        const id = row.dataset.variableid;
        if (id) {
            oldState.set(id, {
                value: row.cells[6] ? row.cells[6].textContent.trim() : '',
                checked: row.querySelector('.row-checkbox')?.checked || false
            });
        }
    });
    
    const newRowMap = new Map();
    newData.forEach(point => {
        newRowMap.set(String(point.variableid), point);
    });
    
    const existingRows = Array.from(tbody.rows);
    
    existingRows.forEach(row => {
        const id = row.dataset.variableid;
        if (id && newRowMap.has(id)) {
            const point = newRowMap.get(id);
            updateRow(row, point, oldState.get(id));
            newRowMap.delete(id);
        } else {
            row.remove();
        }
    });
    
    newRowMap.forEach((point) => {
        const row = createRow(point, oldState.get(String(point.variableid)));
        tbody.appendChild(row);
    });
    
    oldState.clear();
    
    initRegisterTypeFilter();
    filterTable();
    updateCounts();
    updateSelectAllState();
    
    if (currentSortColumn !== null) {
        sortTable(currentSortColumn, true);
    }
}

function updateRow(row, point, oldState) {
    row.dataset.variableid = point.variableid;
    row.dataset.variabletype = point.variableType;
    row.dataset.variablesize = point.variableSize;
    row.dataset.divisor = point.divisor;
    row.dataset.decimal = point.decimal;
    row.dataset.minvalue = point.minValue;
    row.dataset.maxvalue = point.maxValue;
    row.dataset.description = point.description || '';
    row.dataset.menuepunkt = point.menuepunkt || '';
    
    const cb = row.querySelector('.row-checkbox');
    if (cb && oldState) {
        cb.checked = oldState.checked;
    }
    
    row.cells[1].textContent = point.variableid;
    row.cells[2].textContent = point.modbusregisterid;
    
    if (USE_DB) {
        const menuepunktSpan = row.cells[3].querySelector('.menuepunkt-value');
        if (menuepunktSpan) {
            menuepunktSpan.textContent = point.menuepunkt || '-';
        }
    }
    
    row.cells[4].textContent = point.title;
    row.cells[5].textContent = point.modbusregistertype === 'MODBUS_INPUT_REGISTER' ? 'Input' : 
                               (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' ? 'Holding' : point.modbusregistertype);
    
    const valueCell = row.cells[6];
    const valueDisplay = valueCell.querySelector('.value-display');
    
    if (valueDisplay) {
        valueDisplay.textContent = point.value;
        valueCell.dataset.rawvalue = point.rawvalue;
        valueCell.dataset.divisor = point.divisor;
        valueCell.dataset.decimal = point.decimal;
        valueCell.dataset.unit = point.unit;
    } else {
        valueCell.textContent = point.value;
    }
    
    if (oldState && oldState.value !== point.value) {
        valueCell.classList.add('value-changed');
        setTimeout(() => valueCell.classList.remove('value-changed'), 2000);
    }
}

function createRow(point, oldState) {
    const row = document.createElement('tr');
    
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
    
    const cbCell = row.insertCell(0);
    cbCell.className = 'checkbox-cell';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'row-checkbox';
    cb.checked = oldState ? oldState.checked : false;
    cbCell.appendChild(cb);
    
    row.insertCell(1).textContent = point.variableid;
    row.insertCell(2).textContent = point.modbusregisterid;
    
    const menuepunktCell = row.insertCell(3);
    menuepunktCell.className = 'menuepunkt-cell';
    if (USE_DB) {
        const div = document.createElement('div');
        div.className = 'menuepunkt-display';
        const span = document.createElement('span');
        span.className = 'menuepunkt-value';
        span.textContent = point.menuepunkt || '-';
        const btn = document.createElement('button');
        btn.className = 'btn-menu';
        btn.textContent = '📝';
        btn.title = 'Menüpunkt bearbeiten';
        div.appendChild(span);
        div.appendChild(btn);
        menuepunktCell.appendChild(div);
    } else {
        menuepunktCell.textContent = '-';
    }
    
    row.insertCell(4).textContent = point.title;
    row.insertCell(5).textContent = point.modbusregistertype === 'MODBUS_INPUT_REGISTER' ? 'Input' : 
                                    (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' ? 'Holding' : point.modbusregistertype);
    
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
    
    if (USE_DB) {
        const historyCell = row.insertCell();
        historyCell.style.textAlign = 'center';
        
        if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
            const historyBtn = document.createElement('button');
            historyBtn.className = 'btn-edit';
            historyBtn.textContent = '📜';
            historyCell.appendChild(historyBtn);
        } else {
            historyCell.textContent = '-';
        }
    }
    
    return row;
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

// ===================================
// History Modal
// ===================================

async function showHistory(variableId, title) {
    if (!USE_DB) {
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
        } else if (entry.cwna === 'R') {
            manualCell.innerHTML = '<span class="cwna-badge rule">REGEL</span>';
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
        document.getElementById('btnImportOk').textContent = '✓ OK';
    }
}

function displayImportResult(result) {
    document.getElementById('importUploadArea').style.display = 'none';
    
    const resultDiv = document.getElementById('importResult');
    resultDiv.style.display = 'block';
    
    if (result.success) {
        let html = `
            <div class="import-result success">
                <h3>✓ Import erfolgreich abgeschlossen</h3>
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
    if (!USE_DB) {
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


// ===================================
// Gespeicherte Auswahlen (v3.5.0)
// ===================================
// Eine Auswahl ist eine benannte Liste von API-IDs (die Häkchen in der Tabelle).
// Sie gehört zu einem Device und wird in der Datenbank gespeichert.

let selectionList = [];
let loadedSelection = null; // { id, name, apiIds: Set<number> }

function selectionStorageKey() {
    return 'nibeSelection_' + currentDeviceId;
}

async function selectionRequest(action, body) {
    const options = body ? {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    } : {};
    const response = await fetch('?ajax=' + action, options);
    const result = await response.json();
    if (!result.success) {
        throw new Error(result.error || 'Unbekannter Fehler');
    }
    return result;
}

function getCheckedApiIds() {
    return Array.from(document.querySelectorAll('#tableBody tr[data-variableid]'))
        .filter(tr => tr.querySelector('.row-checkbox')?.checked)
        .map(tr => parseInt(tr.dataset.variableid, 10));
}

function getTableApiIds() {
    return new Set(Array.from(document.querySelectorAll('#tableBody tr[data-variableid]'))
        .map(tr => parseInt(tr.dataset.variableid, 10)));
}

async function initSelections() {
    if (!USE_DB || !document.getElementById('selectionSelect')) return;

    document.getElementById('tableBody').addEventListener('change', updateSelectionState);
    document.getElementById('selectAll').addEventListener('change', updateSelectionState);

    try {
        await reloadSelections();
    } catch (e) {
        document.getElementById('selectionInfo').textContent = 'Auswahlen nicht verfügbar: ' + e.message;
        return;
    }

    // Es wird bewusst keine Auswahl automatisch geladen: die Seite startet immer ohne Auswahl.
    // Ein früher gemerkter Eintrag aus Version 3.5.0 wird entfernt.
    localStorage.removeItem(selectionStorageKey());
    updateSelectionState();
}

async function reloadSelections(selectId) {
    const result = await selectionRequest('getSelections&deviceId=' + currentDeviceId);
    selectionList = result.selections;

    const select = document.getElementById('selectionSelect');
    select.innerHTML = '';
    const none = document.createElement('option');
    none.value = '';
    none.textContent = 'ohne Auswahl';
    select.appendChild(none);
    selectionList.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name + ' (' + s.anzahl + ')';
        select.appendChild(opt);
    });
    if (selectId) select.value = String(selectId);
}

async function onSelectionChange() {
    const id = document.getElementById('selectionSelect').value;

    if (!id) {
        // "ohne Auswahl": keine Auswahl geladen, Häkchen und "Nur ausgewählte" zurücksetzen
        loadedSelection = null;
        localStorage.removeItem(selectionStorageKey());
        clearRowSelection();
        document.getElementById('filterSelectedOnly').checked = false;
        filterTable();
        updateSelectionState();
        return;
    }

    try {
        const result = await selectionRequest('getSelection&id=' + id);
        const sel = result.selection;
        loadedSelection = { id: sel.id, name: sel.name, apiIds: new Set(sel.apiIds.map(Number)) };

        document.querySelectorAll('#tableBody tr[data-variableid]').forEach(tr => {
            const cb = tr.querySelector('.row-checkbox');
            if (cb) cb.checked = loadedSelection.apiIds.has(parseInt(tr.dataset.variableid, 10));
        });
        document.getElementById('filterSelectedOnly').checked = true;
        filterTable();
        updateSelectionState();
    } catch (e) {
        alert('Auswahl konnte nicht geladen werden: ' + e.message);
    }
}

// Aufgerufen von "Zurücksetzen": Dropdown auf "ohne Auswahl", gemerkte Auswahl verwerfen
function resetSelectionBar() {
    const select = document.getElementById('selectionSelect');
    if (!select) return;
    loadedSelection = null;
    select.value = '';
    updateSelectionState();
}

function selectionIsDirty() {
    if (!loadedSelection) return false;
    const tableIds = getTableApiIds();
    // Punkte, die es auf diesem Gerät nicht gibt, zählen nicht als Änderung
    const saved = new Set([...loadedSelection.apiIds].filter(id => tableIds.has(id)));
    const current = new Set(getCheckedApiIds());
    if (saved.size !== current.size) return true;
    for (const id of current) if (!saved.has(id)) return true;
    return false;
}

function updateSelectionState() {
    const info = document.getElementById('selectionInfo');
    if (!info) return;

    const checked = getCheckedApiIds().length;
    const dirty = selectionIsDirty();

    document.getElementById('btnSelSave').disabled = !(loadedSelection && dirty);
    document.getElementById('btnSelSaveAs').disabled = checked === 0;
    document.getElementById('btnSelRename').disabled = !loadedSelection;
    document.getElementById('btnSelDelete').disabled = !loadedSelection;

    info.classList.toggle('dirty', dirty);
    if (loadedSelection) {
        const missing = [...loadedSelection.apiIds].filter(id => !getTableApiIds().has(id)).length;
        info.textContent = dirty
            ? '● Ungespeicherte Änderungen (' + checked + ' ausgewählt)'
            : '✓ ' + checked + ' Datenpunkte' + (missing ? ' (' + missing + ' nicht auf diesem Gerät)' : '');
    } else {
        info.textContent = checked + ' ausgewählt';
    }
}

// Auswahl überschreiben (Änderungen an einer geladenen Auswahl speichern)
async function saveCurrentSelection() {
    if (!loadedSelection) return;

    // Punkte, die es auf diesem Gerät nicht gibt, bleiben erhalten
    const tableIds = getTableApiIds();
    const keep = [...loadedSelection.apiIds].filter(id => !tableIds.has(id));
    const apiIds = [...new Set([...getCheckedApiIds(), ...keep])];

    try {
        await selectionRequest('saveSelection', {
            id: loadedSelection.id, name: loadedSelection.name, deviceId: currentDeviceId, apiIds: apiIds
        });
        await reloadSelections(loadedSelection.id);
        await onSelectionChange();
    } catch (e) {
        alert('Fehler beim Speichern: ' + e.message);
    }
}

// Aktuelle Häkchen unter neuem Namen speichern
async function saveSelectionAs() {
    const apiIds = getCheckedApiIds();
    if (apiIds.length === 0) {
        alert('Bitte zuerst Datenpunkte mit den Häkchen auswählen.');
        return;
    }

    const name = prompt('Name der Auswahl (' + apiIds.length + ' Datenpunkte):', '');
    if (name === null) return;
    if (name.trim() === '') {
        alert('Bitte einen Namen eingeben.');
        return;
    }

    try {
        const result = await selectionRequest('saveSelection', {
            id: null, name: name.trim(), deviceId: currentDeviceId, apiIds: apiIds
        });
        await reloadSelections(result.id);
        await onSelectionChange();
    } catch (e) {
        alert('Fehler beim Speichern: ' + e.message);
    }
}

async function renameSelection() {
    if (!loadedSelection) return;
    const name = prompt('Neuer Name der Auswahl:', loadedSelection.name);
    if (name === null || name.trim() === '' || name.trim() === loadedSelection.name) return;

    try {
        // ohne apiIds: nur umbenennen, die Datenpunkte bleiben unverändert
        await selectionRequest('saveSelection', { id: loadedSelection.id, name: name.trim(), deviceId: currentDeviceId });
        loadedSelection.name = name.trim();
        await reloadSelections(loadedSelection.id);
        updateSelectionState();
    } catch (e) {
        alert('Fehler beim Umbenennen: ' + e.message);
    }
}

async function deleteCurrentSelection() {
    if (!loadedSelection) return;
    if (!confirm('Auswahl "' + loadedSelection.name + '" wirklich löschen?\n\n(Die Häkchen in der Tabelle bleiben bestehen.)')) return;

    try {
        await selectionRequest('deleteSelection', { id: loadedSelection.id });
        loadedSelection = null;
        localStorage.removeItem(selectionStorageKey());
        await reloadSelections();
        updateSelectionState();
    } catch (e) {
        alert('Fehler beim Löschen: ' + e.message);
    }
}
