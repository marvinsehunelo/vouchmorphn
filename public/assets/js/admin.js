// VOUCHMORPH Control Dashboard JavaScript

// Auto-refresh for real-time data
let autoRefreshInterval;

function startAutoRefresh(seconds = 30) {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(() => {
        if (!document.hidden) {
            location.reload();
        }
    }, seconds * 1000);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// API Connection Testing
async function testConnection(participantId) {
    const button = event.target;
    const originalText = button.innerText;
    
    button.disabled = true;
    button.innerText = 'Testing...';
    
    try {
        const response = await fetch(`../api/connections.php?action=test_one&participant_id=${participantId}`);
        const data = await response.json();
        
        if (data.success) {
            showNotification('Connection Test', `Success! Response time: ${data.response_time_ms}ms`, 'success');
        } else {
            showNotification('Connection Test', `Failed: ${data.error}`, 'error');
        }
    } catch (error) {
        showNotification('Connection Test', `Error: ${error.message}`, 'error');
    } finally {
        button.disabled = false;
        button.innerText = originalText;
    }
}

// Notifications
function showNotification(title, message, type = 'info') {
    // Check if browser supports notifications
    if (Notification.permission === 'granted') {
        new Notification(title, { body: message });
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(permission => {
            if (permission === 'granted') {
                new Notification(title, { body: message });
            }
        });
    }
    
    // Also show in-page toast
    showToast(message, type);
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.innerHTML = `
        <div class="alert-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</div>
        <div class="alert-content">${message}</div>
    `;
    
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999';
    toast.style.minWidth = '300px';
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 5000);
}

// Chart Initialization
function initCharts() {
    if (typeof Chart === 'undefined') return;
    
    // Transaction volume chart
    const volumeCtx = document.getElementById('volumeChart')?.getContext('2d');
    if (volumeCtx) {
        new Chart(volumeCtx, {
            type: 'line',
            data: {
                labels: getLast7Days(),
                datasets: [{
                    label: 'Transaction Volume',
                    data: getVolumeData(),
                    borderColor: '#0f0',
                    backgroundColor: 'rgba(0,255,0,0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#333' },
                        ticks: { color: '#888' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#888' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#fff' } }
                }
            }
        });
    }
}

function getLast7Days() {
    const days = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        days.push(d.toLocaleDateString('en-US', { weekday: 'short' }));
    }
    return days;
}

function getVolumeData() {
    // This should be populated from PHP
    return window.volumeData || [65, 59, 80, 81, 56, 55, 40];
}

// Form Validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;
    
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required]');
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.style.borderColor = '#f00';
            isValid = false;
        } else {
            input.style.borderColor = '';
        }
    });
    
    return isValid;
}

// Export Functions
function exportToCSV(data, filename) {
    const csv = convertToCSV(data);
    downloadFile(csv, filename, 'text/csv');
}

function exportToJSON(data, filename) {
    const json = JSON.stringify(data, null, 2);
    downloadFile(json, filename, 'application/json');
}

function downloadFile(content, filename, type) {
    const blob = new Blob([content], { type });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
}

function convertToCSV(objArray) {
    const array = typeof objArray !== 'object' ? JSON.parse(objArray) : objArray;
    let str = '';
    
    for (let i = 0; i < array.length; i++) {
        let line = '';
        for (let index in array[i]) {
            if (line !== '') line += ',';
            line += '"' + array[i][index] + '"';
        }
        str += line + '\r\n';
    }
    
    return str;
}

// Search Functionality
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const filter = input.value.toUpperCase();
    const table = document.getElementById(tableId);
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        let found = false;
        
        for (let j = 0; j < cells.length; j++) {
            const cell = cells[j];
            if (cell) {
                const textValue = cell.textContent || cell.innerText;
                if (textValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        
        rows[i].style.display = found ? '' : 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Request notification permission
    if (Notification && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    
    // Initialize charts
    initCharts();
    
    // Start auto-refresh (default 30 seconds)
    if (!document.getElementById('disable-auto-refresh')) {
        startAutoRefresh(30);
    }
    
    // Add search listeners
    document.querySelectorAll('[data-search]').forEach(input => {
        const tableId = input.getAttribute('data-search');
        input.addEventListener('keyup', () => searchTable(input.id, tableId));
    });
});

// Stop auto-refresh when leaving page
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});
