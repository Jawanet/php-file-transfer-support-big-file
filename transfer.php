<?php
session_start();

// Definisi path file progress berdasarkan Session ID agar unik per user
$progressFile = sys_get_temp_dir() . '/download_progress_' . session_id() . '.json';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // -----------------------------------------------------------
    // ACTION: CHECK PROGRESS
    // -----------------------------------------------------------
    if ($_POST['action'] === 'check_progress') {
        // Kita tidak butuh session lagi di sini, tutup biar performa lancar
        session_write_close();

        if (file_exists($progressFile)) {
            // Bersihkan cache statfiles agar data selalu fresh
            clearstatcache(true, $progressFile);
            $data = file_get_contents($progressFile);
            echo $data;
        } else {
            echo json_encode([
                'status' => 'idle',
                'downloaded' => 0,
                'total' => 0,
                'percent' => 0,
                'speed' => 0,
                'message' => 'Menunggu antrian...'
            ]);
        }
        exit;
    }
    
    // -----------------------------------------------------------
    // ACTION: GET FILE SIZE
    // -----------------------------------------------------------

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
        
        // Fungsi helper untuk mendapatkan ukuran file dengan metode bertingkat
        function getRemoteFileSize($url) {
            // METHOD 1: Coba HEAD request standar
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true); // Hanya minta header
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $data = curl_exec($ch);
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // URL asli setelah redirect
            curl_close($ch);

            // Jika Method 1 berhasil dapat ukuran valid (> 0)
            if ($httpCode == 200 && $size > 0) {
                return ['size' => $size, 'url' => $finalUrl];
            }

            // METHOD 2: Range Request (Fallback jika Method 1 gagal)
            // Kita minta 1 byte saja, server biasanya akan membalas dengan header Content-Range: bytes 0-0/TOTAL_SIZE
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, false); // Harus GET, bukan HEAD
            curl_setopt($ch, CURLOPT_RANGE, '0-0');  // Minta byte ke-0 saja
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);  // Kita butuh header response
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $data = curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            // Cari header Content-Range (Case insensitive)
            if (preg_match('/Content-Range: bytes \d+-\d+\/(\d+)/i', $data, $matches)) {
                return ['size' => (int)$matches[1], 'url' => $finalUrl];
            }

            // Jika masih gagal, kembalikan -1
            return ['size' => -1, 'url' => $finalUrl];
        }

        try {
            $result = getRemoteFileSize($url);
            $fileSize = $result['size'];
            
            // Ambil nama file dari URL terakhir (setelah redirect)
            $pathInfo = pathinfo(parse_url($result['url'], PHP_URL_PATH));
            $filename = isset($pathInfo['basename']) && !empty($pathInfo['basename']) 
                      ? urldecode($pathInfo['basename']) 
                      : 'downloaded_file_' . time();

            // Jika filename masih kosong atau aneh, beri nama default
            if (!$filename || strlen($filename) < 2) {
                $filename = 'file_' . time() . '.bin';
            }

            $unknownSize = ($fileSize <= 0);

            echo json_encode([
                'success' => true,
                'size' => $fileSize,
                'filename' => $filename,
                'unknown_size' => $unknownSize
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // -----------------------------------------------------------
    // ACTION: DOWNLOAD
    // -----------------------------------------------------------
    if ($_POST['action'] === 'download') {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        // PENTING: Tutup sesi SEBELUM proses berat dimulai.
        // Ini membiarkan request 'check_progress' berjalan paralel tanpa menunggu download selesai.
        session_write_close(); 

        $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
        
        // Setup nama file lokal
        $parsedUrl = parse_url($url);
        $pathInfo = pathinfo($parsedUrl['path']);
        $originalFilename = isset($pathInfo['basename']) && !empty($pathInfo['basename']) ? $pathInfo['basename'] : 'file.bin';
        
        // Fungsi unique filename (disederhanakan)
        $saveDir = __DIR__;
        $filename = $saveDir . '/' . $originalFilename;
        $counter = 1;
        while(file_exists($filename)) {
            $filename = $saveDir . '/' . pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . $counter . '.' . pathinfo($originalFilename, PATHINFO_EXTENSION);
            $counter++;
        }

        // Inisialisasi Data Progress Awal ke File JSON
        $initialData = [
            'status' => 'starting',
            'downloaded' => 0,
            'total' => 0,
            'percent' => 0,
            'speed' => 0,
            'message' => 'Memulai koneksi...'
        ];
        file_put_contents($progressFile, json_encode($initialData));
        
        // Variabel untuk tracking speed
        $lastUpdate = microtime(true);
        $lastDownloaded = 0;

        try {
            $fp = fopen($filename, 'wb');
            if (!$fp) throw new Exception('Tidak bisa membuat file lokal.');

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            // Tambahkan buffer size agar tidak terlalu sering memanggil callback I/O
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 128 * 1024); 
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            // Pass variabel ke callback menggunakan 'use'
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($progressFile, &$lastUpdate, &$lastDownloaded) {
                
                // Update file status setiap 0.5 detik (jangan terlalu cepat agar tidak membebani Disk I/O)
                $now = microtime(true);
                if (($now - $lastUpdate) >= 0.5 && $download_size > 0) {
                    $timeDiff = $now - $lastUpdate;
                    $downloadedDiff = $downloaded - $lastDownloaded;
                    $speed = $timeDiff > 0 ? $downloadedDiff / $timeDiff : 0;
                    
                    $progressData = [
                        'status' => 'downloading',
                        'downloaded' => $downloaded,
                        'total' => $download_size,
                        'percent' => ($downloaded / $download_size) * 100,
                        'speed' => $speed,
                        'message' => 'Downloading...'
                    ];
                    
                    // Tulis ke FILE JSON fisik, BUKAN $_SESSION
                    file_put_contents($progressFile, json_encode($progressData));
                    
                    $lastUpdate = $now;
                    $lastDownloaded = $downloaded;
                }
            });
            
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            fclose($fp);
            
            if (!$result || $httpCode != 200) {
                unlink($filename); // Hapus file jika gagal
                throw new Exception("Gagal download. HTTP Code: $httpCode. $error");
            }
            
            // Hapus file progress json setelah selesai
            if(file_exists($progressFile)) unlink($progressFile);

            echo json_encode([
                'success' => true,
                'message' => "Download selesai",
                'filename' => basename($filename),
                'formatted_size' => formatBytes(filesize($filename)),
                'size' => filesize($filename)
            ]);
            
        } catch (Exception $e) {
            // Hapus file progress json jika error
            if(file_exists($progressFile)) unlink($progressFile);
            
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
    <title>Php File Download</title>
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
                        <h4 class="mb-0 text-center"><i class="fas fa-download me-2"></i> PHP File Download</h4>
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
