<?php
/**
 * Camera and Microphone Access Check
 * Tests device permissions before joining a Google Meet session
 */

session_start();
require_once __DIR__ . '/../../db/connect.php';

// Check if student is logged in
if (!isset($_SESSION['Sid']) || empty($_SESSION['Sid'])) {
    header('Location: ../../student_login.php');
    exit;
}

$student_id = $_SESSION['Sid'];
$session_id = (int)($_GET['session_id'] ?? 0);
$meeting_link = $_GET['meeting_link'] ?? '';

if ($session_id <= 0 || empty($meeting_link)) {
    header('Location: live_sessions.php');
    exit;
}

// Fetch session details
$stmt = $db->prepare("SELECT * FROM lms_sessions WHERE id = ?");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    header('Location: live_sessions.php');
    exit;
}

require_once __DIR__ . '/../includes/student_header.php';
?>

<style>
.camera-check-container {
    max-width: 900px;
    margin: 40px auto;
}

.video-preview {
    width: 100%;
    max-width: 640px;
    height: 480px;
    background: #000;
    border-radius: 8px;
    object-fit: cover;
}

.device-status {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.device-status.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
}

.device-status.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
}

.device-status.pending {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
}

.audio-visualizer {
    width: 100%;
    height: 60px;
    background: #f8f9fa;
    border-radius: 4px;
    position: relative;
    overflow: hidden;
}

.audio-bar {
    width: 4px;
    background: #28a745;
    position: absolute;
    bottom: 0;
    transition: height 0.1s;
}

.permission-button {
    min-width: 200px;
}
</style>

<div class="container-fluid camera-check-container">
    <div class="row mb-4">
        <div class="col text-center">
            <h2><i class="fas fa-video me-2"></i>Device Check</h2>
            <p class="text-muted">Test your camera and microphone before joining</p>
            <h5 class="text-primary"><?php echo htmlspecialchars($session['topic']); ?></h5>
        </div>
    </div>

    <!-- Device Status Indicators -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="device-status pending" id="cameraStatus">
                <div class="d-flex align-items-center">
                    <i class="fas fa-video fa-2x me-3"></i>
                    <div class="flex-grow-1">
                        <h6 class="mb-1">Camera</h6>
                        <p class="mb-0 small" id="cameraMessage">Click "Test Devices" to check camera access</p>
                    </div>
                    <i class="fas fa-spinner fa-spin fa-2x" id="cameraSpinner" style="display: none;"></i>
                    <i class="fas fa-check-circle text-success fa-2x" id="cameraCheck" style="display: none;"></i>
                    <i class="fas fa-times-circle text-danger fa-2x" id="cameraError" style="display: none;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="device-status pending" id="micStatus">
                <div class="d-flex align-items-center">
                    <i class="fas fa-microphone fa-2x me-3"></i>
                    <div class="flex-grow-1">
                        <h6 class="mb-1">Microphone</h6>
                        <p class="mb-0 small" id="micMessage">Click "Test Devices" to check microphone access</p>
                    </div>
                    <i class="fas fa-spinner fa-spin fa-2x" id="micSpinner" style="display: none;"></i>
                    <i class="fas fa-check-circle text-success fa-2x" id="micCheck" style="display: none;"></i>
                    <i class="fas fa-times-circle text-danger fa-2x" id="micError" style="display: none;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Preview -->
    <div class="row mb-4">
        <div class="col text-center">
            <video id="videoPreview" class="video-preview" autoplay muted playsinline></video>
            <div class="mt-3">
                <button class="btn btn-outline-secondary me-2" id="toggleVideo" disabled>
                    <i class="fas fa-video-slash"></i> Turn Off Camera
                </button>
                <button class="btn btn-outline-secondary" id="toggleAudio" disabled>
                    <i class="fas fa-microphone-slash"></i> Mute Microphone
                </button>
            </div>
        </div>
    </div>

    <!-- Audio Visualizer -->
    <div class="row mb-4">
        <div class="col-md-8 offset-md-2">
            <h6 class="mb-2">Microphone Level</h6>
            <div class="audio-visualizer" id="audioVisualizer">
                <div id="audioBars"></div>
            </div>
            <small class="text-muted">Speak to test your microphone</small>
        </div>
    </div>

    <!-- Device Selection -->
    <div class="row mb-4">
        <div class="col-md-6">
            <label class="form-label" for="cameraSelect">Select Camera</label>
            <select class="form-select" id="cameraSelect" disabled>
                <option>Loading cameras...</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="microphoneSelect">Select Microphone</label>
            <select class="form-select" id="microphoneSelect" disabled>
                <option>Loading microphones...</option>
            </select>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="row">
        <div class="col text-center">
            <button class="btn btn-primary btn-lg permission-button me-3" id="testDevices">
                <i class="fas fa-play me-2"></i>Test Devices
            </button>
            <button class="btn btn-success btn-lg permission-button" id="joinMeeting" disabled>
                <i class="fas fa-video me-2"></i>Join Meeting
            </button>
            <a href="live_sessions.php" class="btn btn-secondary btn-lg permission-button ms-3">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>
    </div>

    <!-- Troubleshooting Tips -->
    <div class="row mt-5">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-info-circle me-2"></i>Troubleshooting Tips
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Make sure your browser has permission to access camera and microphone</li>
                        <li>Check that no other application is using your camera or microphone</li>
                        <li>Try refreshing the page if devices are not detected</li>
                        <li>For Chrome: Click the lock icon in the address bar to manage permissions</li>
                        <li>For Firefox: Click the shield icon in the address bar to manage permissions</li>
                        <li>Ensure your camera and microphone are properly connected</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let videoStream = null;
let audioStream = null;
let audioContext = null;
let analyser = null;
let cameraEnabled = false;
let audioEnabled = false;
let animationId = null;

const meetingLink = <?php echo json_encode($meeting_link); ?>;
const sessionId = <?php echo json_encode($session_id); ?>;

// Test devices button
document.getElementById('testDevices').addEventListener('click', async () => {
    await requestPermissions();
});

// Join meeting button
document.getElementById('joinMeeting').addEventListener('click', () => {
    // Stop all streams before redirecting
    stopAllStreams();
    window.location.href = meetingLink;
});

// Toggle video
document.getElementById('toggleVideo').addEventListener('click', function() {
    if (videoStream) {
        const videoTrack = videoStream.getVideoTracks()[0];
        cameraEnabled = !cameraEnabled;
        videoTrack.enabled = cameraEnabled;
        this.innerHTML = cameraEnabled ? 
            '<i class="fas fa-video-slash"></i> Turn Off Camera' : 
            '<i class="fas fa-video"></i> Turn On Camera';
    }
});

// Toggle audio
document.getElementById('toggleAudio').addEventListener('click', function() {
    if (audioStream) {
        const audioTrack = audioStream.getAudioTracks()[0];
        audioEnabled = !audioEnabled;
        audioTrack.enabled = audioEnabled;
        this.innerHTML = audioEnabled ? 
            '<i class="fas fa-microphone-slash"></i> Mute Microphone' : 
            '<i class="fas fa-microphone"></i> Unmute Microphone';
        
        if (!audioEnabled && animationId) {
            cancelAnimationFrame(animationId);
            clearAudioBars();
        }
    }
});

async function requestPermissions() {
    document.getElementById('testDevices').disabled = true;
    
    // Test Camera
    await testCamera();
    
    // Test Microphone
    await testMicrophone();
    
    // Load devices
    await loadDevices();
    
    document.getElementById('testDevices').textContent = 'Retest Devices';
    document.getElementById('testDevices').disabled = false;
}

async function testCamera() {
    updateStatus('camera', 'pending', 'Requesting camera access...', true);
    
    try {
        videoStream = await navigator.mediaDevices.getUserMedia({ 
            video: { 
                width: { ideal: 1280 },
                height: { ideal: 720 }
            } 
        });
        
        const videoElement = document.getElementById('videoPreview');
        videoElement.srcObject = videoStream;
        cameraEnabled = true;
        
        updateStatus('camera', 'success', 'Camera is working properly', false, true);
        document.getElementById('toggleVideo').disabled = false;
        
        checkIfReadyToJoin();
    } catch (error) {
        console.error('Camera error:', error);
        let errorMsg = 'Camera access denied or unavailable';
        
        if (error.name === 'NotAllowedError') {
            errorMsg = 'Please allow camera access in your browser';
        } else if (error.name === 'NotFoundError') {
            errorMsg = 'No camera device found';
        } else if (error.name === 'NotReadableError') {
            errorMsg = 'Camera is being used by another application';
        }
        
        updateStatus('camera', 'error', errorMsg, false, false, true);
    }
}

async function testMicrophone() {
    updateStatus('mic', 'pending', 'Requesting microphone access...', true);
    
    try {
        audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioEnabled = true;
        
        // Setup audio visualization
        setupAudioVisualization();
        
        updateStatus('mic', 'success', 'Microphone is working properly', false, true);
        document.getElementById('toggleAudio').disabled = false;
        
        checkIfReadyToJoin();
    } catch (error) {
        console.error('Microphone error:', error);
        let errorMsg = 'Microphone access denied or unavailable';
        
        if (error.name === 'NotAllowedError') {
            errorMsg = 'Please allow microphone access in your browser';
        } else if (error.name === 'NotFoundError') {
            errorMsg = 'No microphone device found';
        } else if (error.name === 'NotReadableError') {
            errorMsg = 'Microphone is being used by another application';
        }
        
        updateStatus('mic', 'error', errorMsg, false, false, true);
    }
}

function setupAudioVisualization() {
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(audioStream);
    
    analyser.fftSize = 128;
    source.connect(analyser);
    
    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    
    const visualizer = document.getElementById('audioBars');
    visualizer.innerHTML = '';
    
    // Create audio bars
    for (let i = 0; i < 32; i++) {
        const bar = document.createElement('div');
        bar.className = 'audio-bar';
        bar.style.left = (i * 100 / 32) + '%';
        visualizer.appendChild(bar);
    }
    
    const bars = visualizer.querySelectorAll('.audio-bar');
    
    function animate() {
        animationId = requestAnimationFrame(animate);
        analyser.getByteFrequencyData(dataArray);
        
        bars.forEach((bar, i) => {
            const value = dataArray[i * 2];
            const height = (value / 255) * 60;
            bar.style.height = height + 'px';
        });
    }
    
    animate();
}

function clearAudioBars() {
    const bars = document.querySelectorAll('.audio-bar');
    bars.forEach(bar => bar.style.height = '0px');
}

async function loadDevices() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        
        const cameras = devices.filter(device => device.kind === 'videoinput');
        const microphones = devices.filter(device => device.kind === 'audioinput');
        
        // Populate camera select
        const cameraSelect = document.getElementById('cameraSelect');
        cameraSelect.innerHTML = cameras.map(device => 
            `<option value="${device.deviceId}">${device.label || 'Camera ' + (cameras.indexOf(device) + 1)}</option>`
        ).join('');
        cameraSelect.disabled = false;
        
        // Populate microphone select
        const micSelect = document.getElementById('microphoneSelect');
        micSelect.innerHTML = microphones.map(device => 
            `<option value="${device.deviceId}">${device.label || 'Microphone ' + (microphones.indexOf(device) + 1)}</option>`
        ).join('');
        micSelect.disabled = false;
        
        // Handle device change
        cameraSelect.addEventListener('change', async (e) => {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
            }
            videoStream = await navigator.mediaDevices.getUserMedia({ 
                video: { deviceId: { exact: e.target.value } } 
            });
            document.getElementById('videoPreview').srcObject = videoStream;
        });
        
        micSelect.addEventListener('change', async (e) => {
            if (audioStream) {
                audioStream.getTracks().forEach(track => track.stop());
            }
            if (audioContext) {
                audioContext.close();
            }
            audioStream = await navigator.mediaDevices.getUserMedia({ 
                audio: { deviceId: { exact: e.target.value } } 
            });
            setupAudioVisualization();
        });
        
    } catch (error) {
        console.error('Error loading devices:', error);
    }
}

function updateStatus(device, status, message, showSpinner, showCheck, showError) {
    const statusDiv = document.getElementById(device + 'Status');
    const messageDiv = document.getElementById(device + 'Message');
    const spinner = document.getElementById(device + 'Spinner');
    const check = document.getElementById(device + 'Check');
    const error = document.getElementById(device + 'Error');
    
    statusDiv.className = 'device-status ' + status;
    messageDiv.textContent = message;
    
    spinner.style.display = showSpinner ? 'block' : 'none';
    check.style.display = showCheck ? 'block' : 'none';
    error.style.display = showError ? 'block' : 'none';
}

function checkIfReadyToJoin() {
    const cameraOk = document.getElementById('cameraCheck').style.display === 'block';
    const micOk = document.getElementById('micCheck').style.display === 'block';
    
    if (cameraOk && micOk) {
        document.getElementById('joinMeeting').disabled = false;
    }
}

function stopAllStreams() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
    }
    if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
    }
    if (audioContext) {
        audioContext.close();
    }
    if (animationId) {
        cancelAnimationFrame(animationId);
    }
}

// Cleanup on page unload
window.addEventListener('beforeunload', stopAllStreams);
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
