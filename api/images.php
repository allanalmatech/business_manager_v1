<?php
// api/images.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_permission('products.update');

$db = $GLOBALS['db'];

function json_ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data]); exit; }
function json_err($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

function compressImage($source, $destination, $quality = 75) {
    $info = getimagesize($source);
    if ($info === false) return false;
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    $result = imagejpeg($image, $destination, $quality);
    imagedestroy($image);
    return $result;
}

function generateImageName($category, $productName, $index) {
    $cat = $category ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $category)) : '';
    $name = $productName ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $productName)) : '';
    return sprintf('%s_%s_%03d', $cat ?: 'GEN', $name ?: 'IMG', $index);
}

$action = $_GET['action'] ?? '';
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) json_err('Invalid product');
    if (!isset($_FILES['file'])) json_err('No file uploaded');

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_err('Upload error');
    $tmpName = $file['tmp_name'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','gif'];
    if (!in_array($ext, $allowedExts)) json_err('Invalid image type');

    // MIME type check
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpName);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg','image/png','image/gif'];
    if (!in_array($mime, $allowedMimes)) json_err('Invalid file MIME type');

    // Check if we should overwrite an existing file
    $overwritePath = $_POST['overwrite_path'] ?? null;
    if ($overwritePath) {
        // Validate overwrite_path belongs to this product
        $stmt = $db->prepare("SELECT images FROM products WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $images = json_decode($row['images'] ?? '[]', true) ?: [];
        if (!in_array($overwritePath, $images)) json_err('Invalid overwrite path');
        $destPath = __DIR__ . '/../' . ltrim($overwritePath, '/');
        // Ensure directory exists
        $dir = dirname($destPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!compressImage($tmpName, $destPath, 75)) json_err('Image compression failed');
        // Return same images array to indicate replacement
        json_ok(['images' => $images]);
    }

    // Fetch product details for naming
    $stmt = $db->prepare("SELECT p.name, c.name AS category FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.id=? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$prod) json_err('Product not found');

    // Get existing images
    $stmt = $db->prepare("SELECT images FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $images = json_decode($row['images'] ?? '[]', true) ?: [];
    if (count($images) >= 5) json_err('Maximum 5 images allowed');

    $nextIndex = 1;
    foreach ($images as $img) {
        if (preg_match('/_(\d{3})\./', $img, $m)) {
            $nextIndex = max($nextIndex, (int)$m[1] + 1);
        }
    }
    $fileName = generateImageName($prod['category'], $prod['name'], $nextIndex) . '.jpg';
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $destPath = $uploadDir . $fileName;

    if (!compressImage($tmpName, $destPath, 75)) json_err('Image compression failed');

    $images[] = 'uploads/products/' . $fileName;
    $jsonImages = json_encode($images);
    $stmt = $db->prepare("UPDATE products SET images=? WHERE id=?");
    $stmt->bind_param("si", $jsonImages, $productId);
    $stmt->execute();
    $stmt->close();

    audit_log('products.image_upload', 'product', (string)$productId, "Uploaded image: $fileName");
    json_ok(['images' => $images]);
}

if ($action === 'import_url') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $raw = json_decode((string)file_get_contents('php://input'), true);
    $productId = (int)($raw['product_id'] ?? 0);
    $url = trim((string)($raw['url'] ?? ''));
    if ($productId <= 0 || $url === '') json_err('Invalid input');

    $stmt = $db->prepare("SELECT p.name, c.name AS category FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.id=? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$prod) json_err('Product not found');

    $stmt = $db->prepare("SELECT images FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $images = json_decode($row['images'] ?? '[]', true) ?: [];
    if (count($images) >= 5) json_err('Maximum 5 images allowed');

    $nextIndex = 1;
    foreach ($images as $img) {
        if (preg_match('/_(\d{3})\./', $img, $m)) {
            $nextIndex = max($nextIndex, (int)$m[1] + 1);
        }
    }
    $fileName = generateImageName($prod['category'], $prod['name'], $nextIndex) . '.jpg';
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $destPath = $uploadDir . $fileName;

    $imgData = @file_get_contents($url);
    if ($imgData === false) json_err('Failed to fetch image from URL');
    $tmp = tempnam(sys_get_temp_dir(), 'imgurl');
    file_put_contents($tmp, $imgData);
    // MIME check
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg','image/png','image/gif'];
    if (!in_array($mime, $allowedMimes)) {
      unlink($tmp);
      json_err('Invalid image MIME type from URL');
    }
    if (!compressImage($tmp, $destPath, 75)) {
      unlink($tmp);
      json_err('Image compression failed');
    }
    unlink($tmp);

    $images[] = 'uploads/products/' . $fileName;
    $jsonImages = json_encode($images);
    $stmt = $db->prepare("UPDATE products SET images=? WHERE id=?");
    $stmt->bind_param("si", $jsonImages, $productId);
    $stmt->execute();
    $stmt->close();

    audit_log('products.image_import', 'product', (string)$productId, "Imported image from URL: $fileName");
    json_ok(['images' => $images]);
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $raw = json_decode((string)file_get_contents('php://input'), true);
    $productId = (int)($raw['product_id'] ?? 0);
    $imagePath = trim((string)($raw['image_path'] ?? ''));
    if ($productId <= 0 || $imagePath === '') json_err('Invalid input');

    $stmt = $db->prepare("SELECT images FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $images = json_decode($row['images'] ?? '[]', true) ?: [];
    $newImages = array_values(array_filter($images, fn($i) => $i !== $imagePath));
    $jsonImages = json_encode($newImages);
    $stmt = $db->prepare("UPDATE products SET images=? WHERE id=?");
    $stmt->bind_param("si", $jsonImages, $productId);
    $stmt->execute();
    $stmt->close();

    $fullPath = __DIR__ . '/../' . $imagePath;
    if (is_file($fullPath)) unlink($fullPath);

    audit_log('products.image_delete', 'product', (string)$productId, "Deleted image: $imagePath");
    json_ok(['images' => $newImages]);
}

json_err('Unknown action', 400);
