<?php
session_start();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Progress check
    if ($_POST['action'] === 'check_progress') {
        if (isset($_SESSION['download_progress'])) {
            echo json_encode($_SESSION['download_progress']);
        } else {
            echo json_encode([
                'status' => 'idle',
                'downloaded' => 0,
                'total' => 0,
                'percent' => 0,
                'speed' => 0,
                'message' => 'Belum ada download aktif'
            ]);
        }
        exit;
    }
    
    // Get file size
    if ($_POST['action'] === 'get_file_size') {
        if (!isset($_POST['url'])) {
            echo json_encode(['success' => false, 'message' => 'URL harus diisi!']);
            exit;
        }
        
        $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'message' => 'URL tidak valid!']);
            exit;
        }
        
        try {
            // Method 1: Coba dengan HEAD request terlebih dahulu
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            
            // Tambahan header untuk compatibility
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: */*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Connection: keep-alive'
            ]);
            
            $response = curl_exec($ch);
            $fileSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Ekstrak nama file dari URL
            $parsedUrl = parse_url($url);
            $pathInfo = pathinfo($parsedUrl['path']);
            $filename = isset($pathInfo['basename']) && !empty($pathInfo['basename']) 
                      ? $pathInfo['basename'] 
                      : 'downloaded_file';
            
            // Jika HEAD request gagal atau error, coba dengan GET request (range request)
            if ($httpCode == 0 || $httpCode >= 400 || $error) {
                // Method 2: Coba dengan Range Request untuk mendapatkan ukuran
                $ch2 = curl_init($url);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_HEADER, true);
                curl_setopt($ch2, CURLOPT_NOBODY, false);
                curl_setopt($ch2, CURLOPT_RANGE, '0-0'); // Request hanya 1 byte pertama
                curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch2, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
                curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
                
                $response2 = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $fileSize2 = curl_getinfo($ch2, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                
                // Parse Content-Range header untuk mendapatkan ukuran total
                if (preg_match('/Content-Range: bytes \d+-\d+\/(\d+)/i', $response2, $matches)) {
                    $fileSize = $matches[1];
                } else {
                    $fileSize = $fileSize2;
                }
                
                curl_close($ch2);
                
                // Jika masih gagal
                if ($httpCode2 == 0 || ($httpCode2 >= 400 && $httpCode2 != 416)) {
                    echo json_encode([
                        'success' => true,
                        'size' => 0,
                        'filename' => $filename,
                        'unknown_size' => true,
                        'message' => 'Server tidak mendukung pengecekan ukuran file. Download akan tetap dilanjutkan.'
                    ]);
                    exit;
                }
                
                $httpCode = $httpCode2;
            }
            
            // Validasi HTTP code
            if ($httpCode >= 400 && $httpCode != 416) {
                echo json_encode([
                    'success' => false,
                    'message' => "HTTP Error: $httpCode"
                ]);
                exit;
            }
            
            // Jika ukuran tidak terdeteksi
            if ($fileSize <= 0 || $fileSize == -1) {
                echo json_encode([
                    'success' => true,
                    'size' => 0,
                    'filename' => $filename,
                    'unknown_size' => true,
                    'message' => 'Ukuran file tidak dapat dideteksi. Download akan tetap dilanjutkan.'
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'size' => $fileSize,
                'filename' => $filename,
                'unknown_size' => false
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    // Download file
    if ($_POST['action'] === 'download') {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        // Validasi input
        if (!isset($_POST['url'])) {
            echo json_encode([
                'success' => false,
                'message' => 'URL harus diisi!'
            ]);
            exit;
        }
        
        $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
        
        // Validasi URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode([
                'success' => false,
                'message' => 'URL tidak valid!'
            ]);
            exit;
        }
        
        // Ekstrak nama file dari URL
        $parsedUrl = parse_url($url);
        $pathInfo = pathinfo($parsedUrl['path']);
        $originalFilename = isset($pathInfo['basename']) && !empty($pathInfo['basename']) 
                          ? $pathInfo['basename'] 
                          : 'downloaded_file';
        
        // Fungsi untuk mendapatkan nama file unik
        function getUniqueFilename($filename) {
            if (!file_exists($filename)) {
                return $filename;
            }
            
            $pathInfo = pathinfo($filename);
            $dirname = $pathInfo['dirname'];
            $basename = $pathInfo['filename'];
            $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
            
            $counter = 1;
            while (file_exists($filename)) {
                $filename = $dirname . '/' . $basename . '_' . $counter . $extension;
                $counter++;
            }
            
            return $filename;
        }
        
        // Direktori penyimpanan
        $saveDir = __DIR__;
        $fullPath = $saveDir . '/' . $originalFilename;
        $filename = getUniqueFilename($fullPath);
        
        // Reset progress
        $_SESSION['download_progress'] = [
            'status' => 'starting',
            'downloaded' => 0,
            'total' => 0,
            'percent' => 0,
            'speed' => 0,
            'message' => 'Memulai download...'
        ];
        $_SESSION['last_update'] = microtime(true);
        $_SESSION['last_downloaded'] = 0;
        
        try {
            // Inisialisasi cURL
            $ch = curl_init($url);
            $fp = fopen($filename, 'wb');
            
            if (!$fp) {
                throw new Exception('Tidak dapat membuat file output!');
            }
            
            // Setup cURL options untuk performa maksimal
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024 * 256); // Buffer 256KB untuk performa lebih baik
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            
            // Optimasi koneksi
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            // Progress callback
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                if ($download_size > 0) {
                    $now = microtime(true);
                    $timeDiff = $now - $_SESSION['last_update'];
                    
                    // Update setiap 0.5 detik untuk mengurangi overhead
                    if ($timeDiff >= 0.5) {
                        $downloadedDiff = $downloaded - $_SESSION['last_downloaded'];
                        $speed = $timeDiff > 0 ? $downloadedDiff / $timeDiff : 0;
                        
                        $_SESSION['download_progress'] = [
                            'status' => 'downloading',
                            'downloaded' => $downloaded,
                            'total' => $download_size,
                            'percent' => ($downloaded / $download_size) * 100,
                            'speed' => $speed,
                            'message' => sprintf(
                                'Downloaded %s / %s', 
                                formatBytes($downloaded),
                                formatBytes($download_size)
                            ),
                            'timestamp' => time()
                        ];
                        
                        $_SESSION['last_update'] = $now;
                        $_SESSION['last_downloaded'] = $downloaded;
                    }
                }
            });
            
            // SSL verification
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            
            // Kompresi untuk mempercepat transfer
            curl_setopt($ch, CURLOPT_ENCODING, '');
            
            // Execute download
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            fclose($fp);
            
            if ($result === false || $httpCode != 200) {
                unlink($filename);
                throw new Exception($error ?: "HTTP Error: $httpCode");
            }
            
            $fileSize = filesize($filename);
            
            // Update final progress
            $_SESSION['download_progress'] = [
                'status' => 'completed',
                'downloaded' => $fileSize,
                'total' => $fileSize,
                'percent' => 100,
                'speed' => 0,
                'message' => 'Download selesai!'
            ];
            
            echo json_encode([
                'success' => true,
                'message' => "File berhasil didownload sebagai: " . basename($filename),
                'filename' => basename($filename),
                'fullpath' => $filename,
                'size' => $fileSize,
                'formatted_size' => formatBytes($fileSize)
            ]);
            
        } catch (Exception $e) {
            if (isset($fp) && is_resource($fp)) {
                fclose($fp);
            }
            if (file_exists($filename)) {
                unlink($filename);
            }
            
            $_SESSION['download_progress'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Downloader Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .card {
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            border: none;
            border-radius: 15px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .progress {
            height: 30px;
            border-radius: 10px;
            background-color: #e9ecef;
        }
        .progress-bar {
            border-radius: 10px;
            transition: width 0.3s ease;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        .download-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .btn-download {
            padding: 0.75rem 2rem;
            font-size: 1.1rem;
            border-radius: 10px;
        }
        .file-info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card">
                    <div class="card-header py-3">
                        <h4 class="mb-0 text-center"><i class="fas fa-download me-2"></i>File Downloader Pro</h4>
                    </div>
                    <div class="card-body p-4">
                        <form id="downloadForm">
                            <div class="mb-3">
                                <label for="fileUrl" class="form-label fw-bold">URL File</label>
                                <input type="url" class="form-control form-control-lg" id="fileUrl" 
                                       placeholder="https://example.com/file.zip" required>
                                <div class="form-text">Masukkan URL file yang ingin didownload</div>
                            </div>
                            
                            <!-- File Info Section -->
                            <div id="fileInfoSection" style="display: none;">
                                <div class="file-info-box">
                                    <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i>Informasi File</h6>
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-muted">Nama File:</small>
                                            <div class="fw-bold" id="infoFilename">-</div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Ukuran:</small>
                                            <div class="fw-bold" id="infoFilesize">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-download" id="downloadBtn">
                                    <i class="fas fa-cloud-download-alt me-2"></i>Download File
                                </button>
                            </div>
                        </form>

                        <!-- Progress Section -->
                        <div id="progressSection" class="mt-4" style="display: none;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Status Download</h6>
                                <span class="badge status-badge" id="statusBadge">Memulai...</span>
                            </div>
                            
                            <div class="progress mb-3">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     id="progressBar" role="progressbar" style="width: 0%">
                                    <span id="progressText">0%</span>
                                </div>
                            </div>

                            <div class="download-info">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Data Terdownload</small>
                                        <strong id="downloadedSize">0 B</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Total Size</small>
                                        <strong id="totalSize">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Kecepatan</small>
                                        <strong id="downloadSpeed">0 KB/s</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Waktu Tersisa</small>
                                        <strong id="timeRemaining">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Result Section -->
                        <div id="resultSection" class="mt-4" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let downloadInterval;
        let startTime;
        let fileInfo = null;

        document.getElementById('downloadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const fileUrl = document.getElementById('fileUrl').value;
            
            if (!fileUrl) {
                alert('Masukkan URL terlebih dahulu!');
                return;
            }
            
            // Disable button dan tampilkan loading
            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.disabled = true;
            downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengecek file...';
            
            // Cek ukuran file terlebih dahulu secara otomatis
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_file_size&url=${encodeURIComponent(fileUrl)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fileInfo = data;
                    
                    // Tampilkan info file
                    document.getElementById('fileInfoSection').style.display = 'block';
                    document.getElementById('infoFilename').textContent = data.filename;
                    
                    if (data.unknown_size) {
                        document.getElementById('infoFilesize').textContent = 'Tidak diketahui';
                        document.getElementById('infoFilesize').className = 'fw-bold text-warning';
                    } else {
                        document.getElementById('infoFilesize').textContent = formatBytes(data.size);
                        document.getElementById('infoFilesize').className = 'fw-bold';
                    }
                    
                    // Mulai download
                    setTimeout(() => {
                        startDownload();
                    }, 500);
                } else {
                    alert('Error: ' + data.message);
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>Download File';
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
                downloadBtn.disabled = false;
                downloadBtn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>Download File';
            });
        });

        function startDownload() {
            const fileUrl = document.getElementById('fileUrl').value;
            
            // Reset UI
            document.getElementById('progressSection').style.display = 'block';
            document.getElementById('resultSection').style.display = 'none';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = '0%';
            
            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.disabled = true;
            downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Downloading...';
            
            // Set total size dari file info
            if (fileInfo && fileInfo.size && !fileInfo.unknown_size) {
                document.getElementById('totalSize').textContent = formatBytes(fileInfo.size);
            } else {
                document.getElementById('totalSize').textContent = 'Unknown';
            }
            
            updateStatus('Memulai download...', 'bg-primary');
            startTime = Date.now();
            
            // Start progress monitoring (setiap 300ms untuk lebih responsif)
            downloadInterval = setInterval(checkProgress, 300);
            
            // Send download request
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=download&url=${encodeURIComponent(fileUrl)}`
            })
            .then(response => response.json())
            .then(data => {
                clearInterval(downloadInterval);
                handleDownloadComplete(data);
            })
            .catch(error => {
                clearInterval(downloadInterval);
                handleError(error);
            });
        }

        function checkProgress() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_progress'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'downloading') {
                    updateProgress(data);
                }
            })
            .catch(error => console.error('Progress check error:', error));
        }

        function updateProgress(data) {
            const percent = Math.min(data.percent || 0, 100);
            const downloaded = data.downloaded || 0;
            const total = data.total || 0;
            const speed = data.speed || 0;
            
            // Update progress bar
            document.getElementById('progressBar').style.width = percent.toFixed(1) + '%';
            document.getElementById('progressText').textContent = percent.toFixed(1) + '%';
            
            // Update downloaded size
            document.getElementById('downloadedSize').textContent = formatBytes(downloaded);
            
            // Update total size
            if (total > 0) {
                document.getElementById('totalSize').textContent = formatBytes(total);
            }
            
            // Update speed dengan format yang lebih baik
            if (speed > 0) {
                document.getElementById('downloadSpeed').textContent = formatBytes(speed) + '/s';
            } else {
                document.getElementById('downloadSpeed').textContent = 'Calculating...';
            }
            
            // Calculate time remaining
            if (speed > 0 && total > downloaded) {
                const remaining = (total - downloaded) / speed;
                document.getElementById('timeRemaining').textContent = formatTime(remaining);
            } else if (percent >= 99) {
                document.getElementById('timeRemaining').textContent = 'Almost done...';
            } else {
                document.getElementById('timeRemaining').textContent = 'Calculating...';
            }
            
            // Update status badge dengan info progress
            const downloadedMB = (downloaded / (1024 * 1024)).toFixed(1);
            const totalMB = (total / (1024 * 1024)).toFixed(1);
            updateStatus(`Downloading... ${downloadedMB} MB / ${totalMB} MB`, 'bg-info');
        }

        function handleDownloadComplete(data) {
            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.disabled = false;
            downloadBtn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>Download File';
            
            if (data.success) {
                // Update progress bar ke 100%
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressText').textContent = '100%';
                
                // Update semua info dengan data final
                const fileSize = data.size || 0;
                document.getElementById('downloadedSize').textContent = formatBytes(fileSize);
                document.getElementById('totalSize').textContent = formatBytes(fileSize);
                document.getElementById('timeRemaining').textContent = 'Completed!';
                
                // Hitung kecepatan rata-rata
                const elapsed = (Date.now() - startTime) / 1000;
                const avgSpeed = elapsed > 0 ? fileSize / elapsed : 0;
                document.getElementById('downloadSpeed').textContent = formatBytes(avgSpeed) + '/s (avg)';
                
                updateStatus('Download Selesai!', 'bg-success');
                
                document.getElementById('resultSection').innerHTML = `
                    <div class="alert alert-success">
                        <h6 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Download Berhasil!</h6>
                        <hr>
                        <p class="mb-1"><strong>File:</strong> ${data.filename}</p>
                        <p class="mb-1"><strong>Ukuran:</strong> ${data.formatted_size}</p>
                        <p class="mb-1"><strong>Waktu:</strong> ${formatTime(elapsed)}</p>
                        <p class="mb-0"><strong>Kecepatan Rata-rata:</strong> ${formatBytes(avgSpeed)}/s</p>
                    </div>
                `;
            } else {
                updateStatus('Error', 'bg-danger');
                document.getElementById('resultSection').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Gagal!</strong> ${data.message}
                    </div>
                `;
            }
            
            document.getElementById('resultSection').style.display = 'block';
            
            // Reset fileInfo untuk download berikutnya
            fileInfo = null;
            document.getElementById('fileInfoSection').style.display = 'none';
        }

        function handleError(error) {
            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.disabled = false;
            downloadBtn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>Download File';
            
            updateStatus('Error', 'bg-danger');
            document.getElementById('resultSection').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <strong>Error!</strong> ${error.message}
                </div>
            `;
            document.getElementById('resultSection').style.display = 'block';
        }

        function updateStatus(text, className) {
            const badge = document.getElementById('statusBadge');
            badge.textContent = text;
            badge.className = 'badge status-badge ' + className;
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function formatTime(seconds) {
            if (seconds < 60) return seconds.toFixed(0) + 's';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + Math.floor(seconds % 60) + 's';
            return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
        }
    </script>
</body>
</html>
