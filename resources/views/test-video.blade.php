<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Player Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f0f0f0; }
        .test-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; }
        button { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        .success { background: #4CAF50; color: white; }
        .error { background: #f44336; color: white; }
        .info { background: #2196F3; color: white; }
        .debug { background: #333; color: #0f0; padding: 15px; border-radius: 4px; font-family: monospace; max-height: 300px; overflow-y: auto; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .status.error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .status.success { background: #e8f5e8; color: #2e7d32; border: 1px solid #c8e6c9; }
    </style>
</head>
<body>
    <h1>🔧 Video Player Debug Test</h1>
    
    <!-- Status Display -->
    <div id="page-status" class="status error">
        <strong>Status:</strong> <span id="status-text">Checking JavaScript...</span>
    </div>
    
    <!-- Basic Test -->
    <div class="test-section">
        <h2>1. Basic JavaScript Test</h2>
        <button onclick="testBasicJS()" class="info">Test JavaScript</button>
        <button onclick="testAlert()" class="info">Test Alert</button>
        <p id="basic-result">Click buttons to test...</p>
    </div>
    
    <!-- DOM Test -->
    <div class="test-section">
        <h2>2. DOM Element Test</h2>
        <button onclick="testDOM()" class="info">Test DOM Elements</button>
        <p id="dom-result">Click button to test...</p>
    </div>
    
    <!-- Video Test -->
    <div class="test-section">
        <h2>3. Video Player Test</h2>
        <div id="video-area" style="width: 400px; height: 225px; background: #ddd; margin: 10px 0; position: relative;">
            <div id="video-thumbnail" style="width: 100%; height: 100%; background: #999; display: flex; align-items: center; justify-content: center; color: white;">
                Video Thumbnail
            </div>
            <div id="video-player" style="width: 100%; height: 100%; background: #000; display: none;">
                <iframe id="video-iframe" width="100%" height="100%" frameborder="0" allowfullscreen></iframe>
            </div>
        </div>
        <button onclick="loadVideo()" class="success">Load Video</button>
        <button onclick="closeVideo()" class="error">Close Video</button>
        <button onclick="testDifferentVideo()" class="info">Test MP4 Video</button>
        <p id="video-result">Click Load Video to test...</p>
    </div>
    
    <!-- Debug Output -->
    <div class="test-section">
        <h2>4. Debug Output</h2>
        <div id="debug-output" class="debug">Debug output will appear here...</div>
    </div>
    
    <!-- Manual Test -->
    <div class="test-section">
        <h2>5. Manual Test</h2>
        <p>If buttons don't work, try this:</p>
        <button onclick="alert('Manual test')" style="background: orange; color: white;">Manual Alert Test</button>
        <p id="manual-result">Click the orange button above...</p>
    </div>
    
    <script>
        // Immediate status check
        console.log('=== SCRIPT STARTED ===');
        
        // Check if we can even write to console
        try {
            console.log('Console logging works');
            document.getElementById('status-text').textContent = 'Console logging works';
        } catch (e) {
            console.error('Console error:', e);
            document.getElementById('status-text').textContent = 'Console error: ' + e.message;
        }
        
        // Debug function
        function debug(message) {
            console.log('DEBUG:', message);
            
            try {
                const output = document.getElementById('debug-output');
                if (output) {
                    const timestamp = new Date().toLocaleTimeString();
                    output.innerHTML += `\n[${timestamp}] ${message}`;
                    output.scrollTop = output.scrollHeight;
                }
            } catch (error) {
                console.error('Error writing to debug output:', error);
            }
        }
        
        // Test 1: Basic JavaScript
        function testBasicJS() {
            console.log('testBasicJS called');
            debug('=== Testing Basic JavaScript ===');
            
            try {
                const result = document.getElementById('basic-result');
                if (result) {
                    result.innerHTML = '<span style="color: green;">✅ JavaScript is working!</span>';
                    debug('✅ Basic JavaScript test passed');
                    updateStatus('JavaScript test passed!', 'success');
                    return true;
                } else {
                    debug('❌ basic-result element not found');
                    updateStatus('JavaScript test failed - element not found', 'error');
                    return false;
                }
            } catch (error) {
                debug('❌ Basic JavaScript test failed: ' + error.message);
                updateStatus('JavaScript test failed: ' + error.message, 'error');
                return false;
            }
        }
        
        // Test 2: Alert function
        function testAlert() {
            console.log('testAlert called');
            debug('=== Testing Alert Function ===');
            
            try {
                alert('Alert function is working!');
                debug('✅ Alert function test passed');
                updateStatus('Alert test passed!', 'success');
                return true;
            } catch (error) {
                debug('❌ Alert function test failed: ' + error.message);
                updateStatus('Alert test failed: ' + error.message, 'error');
                return false;
            }
        }
        
        // Test 3: DOM elements
        function testDOM() {
            console.log('testDOM called');
            debug('=== Testing DOM Elements ===');
            
            try {
                const thumbnail = document.getElementById('video-thumbnail');
                const player = document.getElementById('video-player');
                const iframe = document.getElementById('video-iframe');
                
                debug(`Thumbnail found: ${!!thumbnail}`);
                debug(`Player found: ${!!player}`);
                debug(`Iframe found: ${!!iframe}`);
                
                if (thumbnail && player && iframe) {
                    document.getElementById('dom-result').innerHTML = '<span style="color: green;">✅ All DOM elements found!</span>';
                    debug('✅ DOM test passed');
                    updateStatus('DOM test passed!', 'success');
                    return true;
                } else {
                    document.getElementById('dom-result').innerHTML = '<span style="color: red;">❌ Some DOM elements missing</span>';
                    debug('❌ DOM test failed - missing elements');
                    updateStatus('DOM test failed - missing elements', 'error');
                    return false;
                }
            } catch (error) {
                debug('❌ DOM test failed: ' + error.message);
                updateStatus('DOM test failed: ' + error.message, 'error');
                return false;
            }
        }
        
        // Test 4: Video loading
        function loadVideo() {
            console.log('loadVideo called');
            debug('=== Testing Video Loading ===');
            
            try {
                const thumbnail = document.getElementById('video-thumbnail');
                const player = document.getElementById('video-player');
                const iframe = document.getElementById('video-iframe');
                
                if (!thumbnail || !player || !iframe) {
                    debug('❌ Cannot load video - missing elements');
                    updateStatus('Cannot load video - missing elements', 'error');
                    return false;
                }
                
                // Set video source - using a video that definitely allows embedding
                const videoId = 'dQw4w9WgXcQ'; // Rick Roll - always allows embedding
                const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1`;
                
                debug(`Setting iframe source: ${embedUrl}`);
                iframe.src = embedUrl;
                
                // Show player, hide thumbnail
                thumbnail.style.display = 'none';
                player.style.display = 'block';
                
                document.getElementById('video-result').innerHTML = '<span style="color: green;">✅ Video loaded successfully!</span>';
                debug('✅ Video loading test passed');
                updateStatus('Video loaded successfully!', 'success');
                return true;
                
            } catch (error) {
                debug('❌ Video loading test failed: ' + error.message);
                document.getElementById('video-result').innerHTML = '<span style="color: red;">❌ Video loading failed</span>';
                updateStatus('Video loading failed: ' + error.message, 'error');
                return false;
            }
        }
        
        // Test with different video types
        function testDifferentVideo() {
            debug('=== Testing Different Video Types ===');
            
            const iframe = document.getElementById('video-iframe');
            if (!iframe) {
                debug('❌ Iframe not found');
                return;
            }
            
            // Test with a simple MP4 video
            const mp4Url = 'https://www.w3schools.com/html/mov_bbb.mp4';
            debug(`Testing MP4 video: ${mp4Url}`);
            
            // Change iframe to video element for MP4
            const videoArea = document.getElementById('video-area');
            const player = document.getElementById('video-player');
            
            // Create video element
            const video = document.createElement('video');
            video.controls = true;
            video.autoplay = false;
            video.style.width = '100%';
            video.style.height = '100%';
            
            // Create source
            const source = document.createElement('source');
            source.src = mp4Url;
            source.type = 'video/mp4';
            
            video.appendChild(source);
            
            // Replace iframe with video
            iframe.style.display = 'none';
            player.appendChild(video);
            
            debug('✅ MP4 video element created');
            updateStatus('MP4 video loaded for testing', 'success');
        }
        
        // Close video
        function closeVideo() {
            console.log('closeVideo called');
            debug('=== Closing Video ===');
            
            try {
                const thumbnail = document.getElementById('video-thumbnail');
                const player = document.getElementById('video-player');
                const iframe = document.getElementById('video-iframe');
                
                if (thumbnail && player && iframe) {
                    // Clear iframe
                    iframe.src = '';
                    
                    // Show thumbnail, hide player
                    thumbnail.style.display = 'flex';
                    player.style.display = 'none';
                    
                    document.getElementById('video-result').innerHTML = 'Video closed - ready to test again';
                    debug('✅ Video closed successfully');
                    updateStatus('Video closed successfully', 'success');
                }
            } catch (error) {
                debug('❌ Error closing video: ' + error.message);
                updateStatus('Error closing video: ' + error.message, 'error');
            }
        }
        
        // Update status
        function updateStatus(message, type) {
            try {
                const statusDiv = document.getElementById('page-status');
                const statusText = document.getElementById('status-text');
                
                if (statusDiv && statusText) {
                    statusDiv.className = `status ${type}`;
                    statusText.textContent = message;
                }
            } catch (error) {
                console.error('Error updating status:', error);
            }
        }
        
        // Page load
        window.onload = function() {
            console.log('=== WINDOW ONLOAD FIRED ===');
            debug('=== Page Loaded Successfully ===');
            debug('All tests ready to run');
            updateStatus('Page loaded - JavaScript is working!', 'success');
            
            // Auto-run basic tests
            setTimeout(() => {
                debug('Running automatic tests...');
                testBasicJS();
                testDOM();
            }, 1000);
        };
        
        // Fallback if onload doesn't work
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== DOM CONTENT LOADED ===');
            debug('=== DOM Content Loaded ===');
        });
        
        // Immediate test
        console.log('=== SCRIPT LOADED ===');
        debug('Script loaded and running');
        
        // Check if functions are accessible
        console.log('Functions available:', {
            testBasicJS: typeof testBasicJS,
            testAlert: typeof testAlert,
            testDOM: typeof testDOM,
            loadVideo: typeof loadVideo,
            closeVideo: typeof closeVideo
        });
        
    </script>
</body>
</html>
