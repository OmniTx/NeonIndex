/**
 * NeonIndex v2.1 — Frontend JavaScript
 *
 * Handles:
 *  - Theme toggling (light / dark / auto, via data-bs-theme attribute)
 *  - Chunked file uploads with drag-and-drop, progress bar & retry logic
 *  - Rename / Delete modals (populated dynamically from table buttons)
 *
 * All DOM IDs referenced here match the elements rendered by index.php.
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
 * Upload an array of File objects, showing aggregate progress in the modal.
 */
async function startUploadQueue(files) {
    const progressWrap = document.getElementById('uploadProgress');
    const filesList = document.getElementById('uploadFilesList');
    const overallStatus = document.getElementById('uploadOverallStatus');
    const percentLabel = document.getElementById('uploadPercent');
    const progressBar = document.getElementById('uploadProgressBar');
    const resultWrap = document.getElementById('uploadResult');
    const resultAlert = document.getElementById('uploadResultAlert');
    const dropZone = document.getElementById('uploadDropZone');

    // Show progress UI, hide dropzone
    dropZone.classList.add('d-none');
    progressWrap.classList.remove('d-none');
    resultWrap.classList.add('d-none');

    let completed = 0;
    let failed = 0;
    filesList.innerHTML = '';

    // Build file list display
    files.forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'd-flex justify-content-between align-items-center py-1';
        row.id = `upload-file-${i}`;
        row.innerHTML = `
            <span style="font-size:.82rem">${escapeHtml(f.webkitRelativePath || f.name)}</span>
            <span class="badge bg-secondary" id="upload-status-${i}">Queued</span>
        `;
        filesList.appendChild(row);
    });

    // Process files sequentially
    for (let i = 0; i < files.length; i++) {
        const statusBadge = document.getElementById(`upload-status-${i}`);
        statusBadge.textContent = 'Uploading…';
        statusBadge.className = 'badge bg-warning text-dark';

        try {
            await uploadSingleFile(files[i]);
            statusBadge.textContent = '✓ Done';
            statusBadge.className = 'badge bg-success';
            completed++;
        } catch (err) {
            statusBadge.textContent = '✗ Failed';
            statusBadge.className = 'badge bg-danger';
            failed++;
        }

        // Update overall progress
        const pct = Math.round(((completed + failed) / files.length) * 100);
        progressBar.style.width = pct + '%';
        percentLabel.textContent = pct + '%';
        overallStatus.textContent = `Uploaded ${completed + failed} / ${files.length}`;
    }

    // Show final result
    progressWrap.classList.add('d-none');
    resultWrap.classList.remove('d-none');

    if (failed === 0) {
        resultAlert.className = 'alert alert-success mb-0';
        resultAlert.textContent = `All ${completed} file(s) uploaded successfully!`;
        setTimeout(() => location.reload(), 1200);
    } else {
        resultAlert.className = 'alert alert-warning mb-0';
        resultAlert.textContent = `${completed} uploaded, ${failed} failed.`;
    }
}

/**
 * Upload a single file. Uses chunked upload for files > CHUNK_SIZE,
 * otherwise falls back to a simple POST.
 */
async function uploadSingleFile(file) {
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