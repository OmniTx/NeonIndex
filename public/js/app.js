/**
 * NeonIndex v2.1 — Frontend JavaScript
 *
 * Handles:
 *  - Chunked file uploads with drag-and-drop, granular progress, speed & ETA
 *  - Rename / Delete modals (populated dynamically from table buttons)
 *
 * All DOM IDs referenced here match the elements rendered by index.php & admin.php.
 *
 * @author  OmniTx
 * @license MIT
 */

'use strict';

// ─── Modal Helpers (Rename / Delete) ────────────────────────────────────────

/**
 * Open the Rename modal and pre-fill its fields.
 *
 * @param {string} filePath  Relative path of the file/folder
 * @param {string} fileName  Current basename
 */
function showRename(filePath, fileName) {
    document.getElementById('renameFile').value = filePath;
    document.getElementById('renameName').value = fileName;
    new bootstrap.Modal(document.getElementById('renameModal')).show();
}

/**
 * Open the Delete confirmation modal.
 *
 * @param {string} filePath  Relative path of the file/folder
 * @param {string} fileName  Basename to display
 */
function showDelete(filePath, fileName) {
    document.getElementById('deleteFile').value = filePath;
    document.getElementById('deleteFileName').textContent = fileName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}


// ─── Chunked Upload Engine ──────────────────────────────────────────────────

/**
 * Initialise the upload dropzone, file/folder pickers, and drag-and-drop.
 * Only runs if the upload modal exists on the page (i.e. admin is logged in).
 */
document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('prettyFileInput');
    const folderInput = document.getElementById('prettyFolderInput');

    // Exit early if the upload UI isn't present
    if (!dropZone) return;

    // ── Drag & Drop ──
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(evt => {
        dropZone.addEventListener(evt, () => dropZone.classList.add('dragover'));
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover'));
    });

    dropZone.addEventListener('drop', e => {
        const items = e.dataTransfer.items;
        if (items && items.length) {
            handleDropItems(items);
        } else if (e.dataTransfer.files.length) {
            startUploadQueue(Array.from(e.dataTransfer.files));
        }
    });

    // ── File / Folder picker ──
    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) startUploadQueue(Array.from(fileInput.files));
        });
    }
    if (folderInput) {
        folderInput.addEventListener('change', () => {
            if (folderInput.files.length) startUploadQueue(Array.from(folderInput.files));
        });
    }
});

/**
 * Handle items dropped from the browser (may include directories via
 * webkitGetAsEntry). Falls back to plain file list if the API is missing.
 */
async function handleDropItems(items) {
    const files = [];
    const entries = [];

    for (const item of items) {
        const entry = item.webkitGetAsEntry?.();
        if (entry) {
            entries.push(entry);
        } else if (item.kind === 'file') {
            files.push(item.getAsFile());
        }
    }

    if (entries.length) {
        for (const entry of entries) {
            await traverseEntry(entry, '', files);
        }
    }

    if (files.length) startUploadQueue(files);
}

/**
 * Recursively walk a FileSystemEntry tree and collect all files.
 */
function traverseEntry(entry, basePath, fileList) {
    return new Promise(resolve => {
        if (entry.isFile) {
            entry.file(f => {
                // Preserve relative path for folder uploads
                Object.defineProperty(f, 'webkitRelativePath', {
                    value: basePath + f.name,
                    writable: false,
                });
                fileList.push(f);
                resolve();
            });
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            reader.readEntries(async entries => {
                for (const child of entries) {
                    await traverseEntry(child, basePath + entry.name + '/', fileList);
                }
                resolve();
            });
        } else {
            resolve();
        }
    });
}


// ── Upload Queue ─────────────────────────────────────────────────────────────

/** Chunk size for large file uploads (matches server-side CHUNK_SIZE_MB). */
const CHUNK_SIZE = 8 * 1024 * 1024; // 8 MB default
const MAX_RETRIES = 3;

/**
 * Upload an array of File objects, showing granular byte-level progress,
 * upload speed, and estimated time remaining.
 */
async function startUploadQueue(files) {
    const progressWrap  = document.getElementById('uploadProgress');
    const filesList     = document.getElementById('uploadFilesList');
    const overallStatus = document.getElementById('uploadOverallStatus');
    const percentLabel  = document.getElementById('uploadPercent');
    const progressBar   = document.getElementById('uploadProgressBar');
    const speedLabel    = document.getElementById('uploadSpeed');
    const etaLabel      = document.getElementById('uploadEta');
    const transferLabel = document.getElementById('uploadTransferred');
    const resultWrap    = document.getElementById('uploadResult');
    const resultAlert   = document.getElementById('uploadResultAlert');
    const dropZone      = document.getElementById('uploadDropZone');

    // Show progress UI, hide dropzone
    dropZone.classList.add('d-none');
    progressWrap.classList.remove('d-none');
    resultWrap.classList.add('d-none');

    // Calculate total bytes across all files
    const totalBytes = files.reduce((sum, f) => sum + f.size, 0);
    let uploadedBytes = 0;
    let completed = 0;
    let failed = 0;
    const startTime = Date.now();
    filesList.innerHTML = '';

    // Build file list display with individual progress
    files.forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'upload-file-row';
        row.id = `upload-file-${i}`;
        row.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <span class="upload-file-name">${escapeHtml(f.webkitRelativePath || f.name)}</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="upload-file-size">${formatBytes(f.size)}</span>
                    <span class="badge bg-secondary" id="upload-status-${i}">Queued</span>
                </div>
            </div>
            <div class="progress mt-1" style="height:4px;border-radius:2px">
                <div class="progress-bar" id="upload-bar-${i}" style="width:0%;background:var(--accent);transition:width 0.15s"></div>
            </div>
        `;
        filesList.appendChild(row);
    });

    // Update the aggregate transfer display
    function updateOverallProgress() {
        const pct = totalBytes > 0 ? Math.round((uploadedBytes / totalBytes) * 100) : 0;
        progressBar.style.width = pct + '%';
        percentLabel.textContent = pct + '%';

        // Transfer stats
        if (transferLabel) {
            transferLabel.textContent = `${formatBytes(uploadedBytes)} / ${formatBytes(totalBytes)}`;
        }

        // Speed calculation (bytes/sec averaged over entire elapsed time)
        const elapsed = (Date.now() - startTime) / 1000;
        if (elapsed > 0 && speedLabel) {
            const speed = uploadedBytes / elapsed;
            speedLabel.textContent = formatBytes(Math.round(speed)) + '/s';

            // ETA
            if (etaLabel) {
                const remaining = totalBytes - uploadedBytes;
                if (speed > 0 && remaining > 0) {
                    const etaSec = remaining / speed;
                    etaLabel.textContent = formatDuration(etaSec);
                } else {
                    etaLabel.textContent = '—';
                }
            }
        }

        overallStatus.textContent = `Uploading ${completed + failed} / ${files.length} files`;
    }

    // Process files sequentially
    for (let i = 0; i < files.length; i++) {
        const statusBadge = document.getElementById(`upload-status-${i}`);
        const fileBar = document.getElementById(`upload-bar-${i}`);
        statusBadge.textContent = 'Uploading…';
        statusBadge.className = 'badge bg-warning text-dark';

        const fileBytesStart = uploadedBytes;

        try {
            await uploadSingleFile(files[i], (chunkBytes) => {
                // Per-chunk progress callback
                uploadedBytes = fileBytesStart + chunkBytes;
                const filePct = Math.round((chunkBytes / files[i].size) * 100);
                if (fileBar) fileBar.style.width = filePct + '%';
                updateOverallProgress();
            });
            statusBadge.textContent = 'Done';
            statusBadge.className = 'badge bg-success';
            if (fileBar) fileBar.style.width = '100%';
            uploadedBytes = fileBytesStart + files[i].size;
            completed++;
        } catch (err) {
            statusBadge.textContent = 'Failed';
            statusBadge.className = 'badge bg-danger';
            if (fileBar) {
                fileBar.style.width = '100%';
                fileBar.style.background = 'var(--bs-danger, #dc3545)';
            }
            failed++;
        }

        updateOverallProgress();
    }

    // Show final result
    progressWrap.classList.add('d-none');
    resultWrap.classList.remove('d-none');

    if (failed === 0) {
        resultAlert.className = 'alert alert-success mb-0';
        resultAlert.textContent = `All ${completed} file(s) uploaded successfully! (${formatBytes(totalBytes)})`;
        setTimeout(() => location.reload(), 1200);
    } else {
        resultAlert.className = 'alert alert-warning mb-0';
        resultAlert.textContent = `${completed} uploaded, ${failed} failed. (${formatBytes(uploadedBytes)} transferred)`;
    }
}

/**
 * Upload a single file. Uses chunked upload for files > CHUNK_SIZE,
 * otherwise falls back to a simple POST. Reports byte-level progress
 * via the onProgress callback.
 *
 * @param {File} file
 * @param {function} onProgress  Called with (bytesUploaded) after each chunk
 */
async function uploadSingleFile(file, onProgress) {
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    const fileName = file.webkitRelativePath || file.name;
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    // Get current directory from the page URL (if browsing a subdirectory)
    const urlParams = new URLSearchParams(window.location.search);
    const dir = urlParams.get('dir') || '';

    for (let i = 0; i < totalChunks; i++) {
        const start = i * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('action', 'chunked_upload');
        formData.append('csrf_token', csrfToken);
        formData.append('chunk', chunk, 'chunk');
        formData.append('fileName', fileName);
        formData.append('chunkIndex', i.toString());
        formData.append('totalChunks', totalChunks.toString());
        if (dir) formData.append('dir', dir);

        // Retry logic with exponential back-off
        let lastErr;
        for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
            try {
                const resp = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData,
                });
                const json = await resp.json();

                if (!json.success) throw new Error(json.error || 'Upload failed');
                lastErr = null;

                // Report progress: bytes uploaded so far for this file
                if (onProgress) onProgress(end);
                break; // chunk OK
            } catch (err) {
                lastErr = err;
                await sleep(1000 * (attempt + 1));
            }
        }

        if (lastErr) throw lastErr;
    }
}


// ─── Utility Functions ──────────────────────────────────────────────────────

/** Promise-based sleep for retry back-off. */
function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
}

/** Basic HTML entity escaping. */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format byte count into a human-readable string (KB, MB, GB, etc.).
 * @param {number} bytes
 * @returns {string}
 */
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

/**
 * Format seconds into a human-readable duration (e.g. "2m 15s").
 * @param {number} seconds
 * @returns {string}
 */
function formatDuration(seconds) {
    if (seconds < 1) return '< 1s';
    if (seconds < 60) return Math.round(seconds) + 's';
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    if (m < 60) return m + 'm ' + s + 's';
    const h = Math.floor(m / 60);
    return h + 'h ' + (m % 60) + 'm';
}