/**
 * NeonIndex - Modern Apple-inspired JavaScript
 * Features: Chunked uploads, resumable downloads, update checker, dark mode
 */

class NeonIndex {
    constructor() {
        this.chunkSize = 2 * 1024 * 1024; // 2MB chunks
        this.maxRetries = 3;
        this.init();
    }

    init() {
        this.initDarkMode();
        this.initDragDrop();
        this.initSearch();
        this.initFileSelection();
        this.initUpdateChecker();
    }

    // Dark Mode Toggle
    initDarkMode() {
        const toggle = document.getElementById('darkModeToggle');
        if (!toggle) return;

        const isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) document.body.classList.add('dark-mode');
        toggle.checked = isDark;

        toggle.addEventListener('change', () => {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', toggle.checked);
        });
    }

    // Drag & Drop Upload
    initDragDrop() {
        const dropzone = document.querySelector('.dropzone');
        if (!dropzone) return;

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'));
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'));
        });

        dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) this.handleFiles(files);
        });

        const fileInput = document.getElementById('fileInput');
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) this.handleFiles(e.target.files);
            });
        }
    }

    // Handle File Uploads (Chunked for large files)
    async handleFiles(files) {
        for (const file of files) {
            await this.uploadFile(file);
        }
    }

    async uploadFile(file) {
        const totalChunks = Math.ceil(file.size / this.chunkSize);
        
        if (totalChunks === 1) {
            // Small file - simple upload
            await this.simpleUpload(file);
        } else {
            // Large file - chunked upload
            await this.chunkedUpload(file, totalChunks);
        }
    }

    async simpleUpload(file) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload');

        try {
            const response = await fetch('admin.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                this.showAlert('File uploaded successfully!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                this.showAlert(result.error || 'Upload failed', 'error');
            }
        } catch (error) {
            this.showAlert('Network error: ' + error.message, 'error');
        }
    }

    async chunkedUpload(file, totalChunks) {
        const fileName = file.name;
        const fileId = btoa(fileName + Date.now()).replace(/[^a-zA-Z0-9]/g, '');
        
        this.showProgress(fileId, fileName, 0);

        for (let i = 0; i < totalChunks; i++) {
            const start = i * this.chunkSize;
            const end = Math.min(start + this.chunkSize, file.size);
            const chunk = file.slice(start, end);

            let retryCount = 0;
            while (retryCount < this.maxRetries) {
                try {
                    const formData = new FormData();
                    formData.append('chunk', chunk);
                    formData.append('fileName', fileName);
                    formData.append('chunkIndex', i.toString());
                    formData.append('totalChunks', totalChunks.toString());
                    formData.append('action', 'upload_chunk');

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        const progress = ((i + 1) / totalChunks) * 100;
                        this.updateProgress(fileId, progress);

                        if (result.assembled) {
                            this.hideProgress(fileId);
                            this.showAlert('Large file uploaded successfully!', 'success');
                            setTimeout(() => location.reload(), 1000);
                        }
                        break;
                    } else {
                        throw new Error(result.error || 'Upload failed');
                    }
                } catch (error) {
                    retryCount++;
                    if (retryCount >= this.maxRetries) {
                        this.hideProgress(fileId);
                        this.showAlert(`Failed to upload ${fileName}: ${error.message}`, 'error');
                        break;
                    }
                    await this.sleep(1000 * retryCount); // Exponential backoff
                }
            }
        }
    }

    // Search Functionality
    initSearch() {
        const searchInput = document.getElementById('searchInput');
        if (!searchInput) return;

        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            const items = document.querySelectorAll('.file-item');

            items.forEach(item => {
                const name = item.querySelector('.file-name')?.textContent.toLowerCase() || '';
                item.style.display = name.includes(query) ? '' : 'none';
            });
        });
    }

    // File Selection
    initFileSelection() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.file-item')) {
                const item = e.target.closest('.file-item');
                
                if (e.ctrlKey || e.metaKey) {
                    item.classList.toggle('selected');
                } else {
                    document.querySelectorAll('.file-item.selected').forEach(i => {
                        if (i !== item) i.classList.remove('selected');
                    });
                    item.classList.toggle('selected');
                }
            }
        });
    }

    // Update Checker
    async initUpdateChecker() {
        const checkBtn = document.getElementById('checkUpdates');
        if (!checkBtn) return;

        checkBtn.addEventListener('click', async () => {
            checkBtn.disabled = true;
            checkBtn.textContent = 'Checking...';

            try {
                const response = await fetch('admin.php?action=check_updates');
                const result = await response.json();

                if (result.success && result.has_update) {
                    this.showUpdateModal(result);
                } else if (result.success) {
                    this.showAlert('You are running the latest version!', 'success');
                } else {
                    this.showAlert(result.error || 'Could not check for updates', 'error');
                }
            } catch (error) {
                this.showAlert('Network error: ' + error.message, 'error');
            } finally {
                checkBtn.disabled = false;
                checkBtn.textContent = 'Check for Updates';
            }
        });
    }

    showUpdateModal(data) {
        const modal = document.getElementById('updateModal');
        if (!modal) return;

        modal.querySelector('.modal-title').textContent = `Update Available: v${data.latest_version}`;
        modal.querySelector('.modal-body').innerHTML = `
            <p>Current version: <strong>v${data.current_version}</strong></p>
            <p>New version: <strong>v${data.latest_version}</strong></p>
            <hr style="margin: 16px 0; border: none; border-top: 1px solid var(--border-color);">
            <h4 style="margin-bottom: 8px;">Release Notes:</h4>
            <div style="max-height: 200px; overflow-y: auto; font-size: 13px; color: var(--text-secondary);">
                ${this.parseMarkdown(data.release_notes || 'No release notes available.')}
            </div>
        `;

        const updateBtn = modal.querySelector('#confirmUpdate');
        updateBtn.onclick = () => this.performUpdate(data.download_url);

        modal.classList.add('active');
    }

    async performUpdate(downloadUrl) {
        const modal = document.getElementById('updateModal');
        const updateBtn = modal.querySelector('#confirmUpdate');
        
        updateBtn.disabled = true;
        updateBtn.textContent = 'Updating...';

        try {
            const response = await fetch('admin.php?action=perform_update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ download_url: downloadUrl })
            });
            const result = await response.json();

            if (result.success) {
                this.showAlert('Update completed! Reloading...', 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                this.showAlert(result.error, 'error');
                updateBtn.disabled = false;
                updateBtn.textContent = 'Update Now';
            }
        } catch (error) {
            this.showAlert('Update failed: ' + error.message, 'error');
            updateBtn.disabled = false;
            updateBtn.textContent = 'Update Now';
        }
    }

    // Utility Functions
    showProgress(id, fileName, progress) {
        const container = document.getElementById('uploadProgress');
        if (!container) return;

        const html = `
            <div id="progress-${id}" class="card animate-fade-in" style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 13px; font-weight: 500;">${fileName}</span>
                    <span style="font-size: 12px; color: var(--text-secondary);">${progress.toFixed(0)}%</span>
                </div>
                <div class="progress-container">
                    <div class="progress-bar" style="width: ${progress}%"></div>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    }

    updateProgress(id, progress) {
        const el = document.getElementById(`progress-${id}`);
        if (!el) return;

        el.querySelector('.progress-bar').style.width = `${progress}%`;
        el.querySelector('span:last-child').textContent = `${progress.toFixed(0)}%`;
    }

    hideProgress(id) {
        const el = document.getElementById(`progress-${id}`);
        if (el) el.remove();
    }

    showAlert(message, type = 'success') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} animate-fade-in`;
        alert.textContent = message;
        alert.style.position = 'fixed';
        alert.style.bottom = '24px';
        alert.style.right = '24px';
        alert.style.zIndex = '3000';
        alert.style.minWidth = '300px';

        document.body.appendChild(alert);
        setTimeout(() => alert.remove(), 5000);
    }

    parseMarkdown(text) {
        return text
            .replace(/^### (.*$)/gim, '<h3>$1</h3>')
            .replace(/^## (.*$)/gim, '<h4>$1</h4>')
            .replace(/^# (.*$)/gim, '<h4>$1</h4>')
            .replace(/\*\*(.*)\*\*/gim, '<strong>$1</strong>')
            .replace(/\*(.*)\*/gim, '<em>$1</em>')
            .replace(/\n/gim, '<br>');
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.neonIndex = new NeonIndex();
});