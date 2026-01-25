<?php
// modules/products/capture.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('products.update');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$pid = (int)($_GET['pid'] ?? 0);
$csrf = $_GET['csrf'] ?? '';
// TODO: Re-enable CSRF validation after testing
if ($pid <= 0) {
  http_response_code(403);
  die('Invalid product ID');
}

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Capture Product Image</title>
  <style>
    body { margin:0; background:#000; color:#fff; font-family:sans-serif; }
    video { width:100%; height:auto; display:block; }
    .controls { padding:1rem; text-align:center; }
    button { padding:1rem 2rem; font-size:1.2rem; }
    .msg { padding:1rem; text-align:center; }
    #preview { max-width:100%; max-height:50vh; margin:1rem auto; display:block; }
  </style>
</head>
<body>
  <video id="video" autoplay></video>
  <div class="controls">
    <button id="btnCapture">Capture</button>
    <button id="btnSelect" style="display:none;">Select from Gallery</button>
    <button id="btnUpload" style="display:none;">Upload to System</button>
    <button id="btnAddAnother" style="display:none;">+ Add Another</button>
  </div>
  <input type="file" id="fileInput" accept="image/*" style="display:none;">
  <div id="capturedList" style="display:none;"></div>
  <img id="preview" style="display:none;">
  <div class="msg" id="msg"></div>

  <script>
    const video = document.getElementById('video');
    const btnCapture = document.getElementById('btnCapture');
    const btnSelect = document.getElementById('btnSelect');
    const btnUpload = document.getElementById('btnUpload');
    const btnAddAnother = document.getElementById('btnAddAnother');
    const fileInput = document.getElementById('fileInput');
    const preview = document.getElementById('preview');
    const capturedList = document.getElementById('capturedList');
    const msg = document.getElementById('msg');
    let stream;
    let capturedImages = [];

    // Request camera access
    async function startCamera() {
      try {
        stream = await navigator.mediaDevices.getUserMedia({video: {facingMode:'environment'}});
        video.srcObject = stream;
        video.style.display = 'block';
        btnCapture.style.display = 'inline-block';
        btnSelect.style.display = 'none';
        msg.textContent = '';
      } catch (e) {
        console.error('Camera error:', e);
        msg.textContent = 'Camera not available. Use Select from Gallery.';
        video.style.display = 'none';
        btnCapture.style.display = 'none';
        btnSelect.style.display = 'inline-block';
      }
    }

    startCamera();

    function addCapturedImage(blob) {
      const id = Date.now();
      capturedImages.push({id, blob});
      renderCapturedList();
    }

    function renderCapturedList() {
      if (capturedImages.length === 0) {
        capturedList.style.display = 'none';
        preview.style.display = 'none';
        btnUpload.style.display = 'none';
        btnAddAnother.style.display = 'none';
        return;
      }
      capturedList.style.display = 'block';
      capturedList.innerHTML = '<h6>Captured Images (' + capturedImages.length + '/5)</h6>';
      capturedImages.forEach((img, idx) => {
        const div = document.createElement('div');
        div.className = 'd-inline-block me-2 mb-2';
        div.innerHTML = `
          <img src="${URL.createObjectURL(img.blob)}" width="80" height="80" class="border rounded" style="object-fit:cover;">
          <button type="button" class="btn btn-sm btn-danger ms-1" onclick="removeCaptured(${img.id})">×</button>
        `;
        capturedList.appendChild(div);
      });
      btnUpload.style.display = 'inline-block';
      btnAddAnother.style.display = capturedImages.length < 5 ? 'inline-block' : 'none';
      if (capturedImages.length >= 5) {
        msg.textContent = 'Maximum 5 images captured.';
      }
    }

    window.removeCaptured = function(id) {
      capturedImages = capturedImages.filter(img => img.id !== id);
      renderCapturedList();
    };

    btnCapture.addEventListener('click', () => {
      if (!stream) {
        msg.textContent = 'Camera not available.';
        return;
      }
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0);
      canvas.toBlob((blob) => {
        if (!blob) {
          msg.textContent = 'Failed to capture image';
          return;
        }
        addCapturedImage(blob);
        msg.textContent = 'Image captured. Add more or upload.';
      }, 'image/jpeg', 0.75);
    });

    btnSelect.addEventListener('click', () => {
      fileInput.click();
    });

    fileInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (!file) return;
      file.arrayBuffer().then(buf => new Blob([buf], {type: file.type})).then(blob => {
        addCapturedImage(blob);
        msg.textContent = 'Image selected. Add more or upload.';
      });
    });

    btnAddAnother.addEventListener('click', () => {
      // Reset to capture another
      preview.style.display = 'none';
      video.style.display = 'block';
      btnCapture.style.display = 'inline-block';
      btnAddAnother.style.display = 'none';
      msg.textContent = '';
    });

    btnUpload.addEventListener('click', async () => {
      if (capturedImages.length === 0) return;
      btnUpload.disabled = true;
      btnUpload.textContent = 'Uploading...';
      const uploadedPaths = [];
      for (const img of capturedImages) {
        const fd = new FormData();
        fd.append('product_id', <?= $pid ?>);
        fd.append('file', img.blob, 'capture.jpg');
        const res = await fetch('<?= $BASE_URL ?>/api/images.php?action=upload', {method:'POST', body:fd});
        const txt = await res.text();
        console.log('[capture] raw response:', txt);
        let j;
        try {
          j = JSON.parse(txt);
        } catch (e) {
          console.error('[capture] JSON parse error:', e);
          msg.textContent = 'Server error. See console.';
          btnUpload.disabled = false;
          btnUpload.textContent = 'Upload to System';
          return;
        }
        if (j.ok) {
          uploadedPaths.push(j.data.images[j.data.images.length - 1]);
        } else {
          msg.textContent = j.error || 'Upload failed';
          btnUpload.disabled = false;
          btnUpload.textContent = 'Upload to System';
          return;
        }
      }
      // Notify parent window to crop each uploaded image
      if (window.opener && !window.opener.closed) {
        uploadedPaths.forEach(path => {
          window.opener.postMessage({action: 'openCrop', image: path}, '*');
        });
      }
      msg.textContent = 'All images saved! Closing...';
      btnUpload.textContent = 'Saved';
      setTimeout(() => window.close(), 1000);
    });
  </script>
</body>
</html>
