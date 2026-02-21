<?php
session_start(); 

// --- 1. DEBUG & SETTINGS ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

// --- CRITICAL GD CHECK ---
if (!extension_loaded('gd') && !extension_loaded('gd2')) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#fff;'><h1 style='color:#d97706;'>? System Error</h1><p><strong>PHP GD Library not enabled.</strong></p></div>");
}

 $uploadDir = 'uploads/';
 $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

 $message = "";

// --- Helper Functions ---
function load_image($filepath) {
    if (!file_exists($filepath)) return false;
    $info = @getimagesize($filepath);
    if (!$info) return false;
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filepath) : false;
        case 'image/png':  return function_exists('imagecreatefrompng') ? @imagecreatefrompng($filepath) : false;
        case 'image/gif':  return function_exists('imagecreatefromgif') ? @imagecreatefromgif($filepath) : false;
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filepath) : false;
        default: return false;
    }
}

function save_image($image, $filepath, $mime) {
    switch ($mime) {
        case 'image/jpeg': return @imagejpeg($image, $filepath, 90);
        case 'image/png':  return @imagepng($image, $filepath);
        case 'image/gif':  return @imagegif($image, $filepath);
        case 'image/webp': return @imagewebp($image, $filepath);
        default: return false;
    }
}

// --- 2. MAIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: Reset Session (Delete all images)
    if (isset($_POST['action']) && $_POST['action'] === 'reset_session') {
        $files = glob($uploadDir . '*');
        foreach($files as $file) {
            if(is_file($file)) @unlink($file);
        }
        exit('cleared');
    }

    // ACTION: Upload
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        try {
            if (!empty($_FILES['image']['tmp_name'])) {
                $fileTmp = $_FILES['image']['tmp_name'];
                $fileType = mime_content_type($fileTmp);
                
                if (in_array($fileType, $allowedTypes)) {
                    $fileName = basename($_FILES['image']['name']);
                    $newFileName = time() . "_" . preg_replace('/\s+/', '_', $fileName);
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $_SESSION['flash_msg'] = "<div class='toast success'>Upload successful! ??</div>";
                    } else {
                        $_SESSION['flash_msg'] = "<div class='toast error'>Upload failed.</div>";
                    }
                } else {
                    $_SESSION['flash_msg'] = "<div class='toast error'>Invalid file type.</div>";
                }
            }
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>Error: " . $e->getMessage() . "</div>";
        }
    }

    // ACTION: Delete Single
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $targetPath = $uploadDir . basename($_POST['target_image']);
        if (file_exists($targetPath)) {
            @unlink($targetPath);
            $_SESSION['flash_msg'] = "<div class='toast success'>Image deleted.</div>";
        }
    }

    // ACTION: Edit
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        try {
            if (empty($_POST['target_image'])) throw new Exception("Please select an image.");
            $targetPath = $uploadDir . basename($_POST['target_image']);
            if (!file_exists($targetPath)) throw new Exception("File not found.");

            $saveAsCopy = isset($_POST['save_as_copy']);
            $finalSavePath = $targetPath;
            
            if ($saveAsCopy) {
                $pathInfo = pathinfo($targetPath);
                $finalSavePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_edited.' . $pathInfo['extension'];
            }

            $image = load_image($targetPath);
            if (!$image) throw new Exception("Failed to load image.");

            $info = getimagesize($targetPath);
            $mime = $info['mime'];
            $width = imagesx($image);
            $height = imagesy($image);
            $effectType = $_POST['effect_type'];
            $edited = false;

            switch ($effectType) {
                case 'grayscale': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); $edited = true; } break;
                case 'invert': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_NEGATE); $edited = true; } break;
                case 'sepia': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); imagefilter($image, IMG_FILTER_COLORIZE, 90, 60, 30); $edited = true; } break;
                case 'colorize': 
                    $hex = $_POST['tint_color'] ?? '#ff0000';
                    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
                    if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_COLORIZE, $r, $g, $b); $edited = true; } 
                    break;
                case 'brightness': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_BRIGHTNESS, intval($_POST['brightness_val'])); $edited = true; } break;
                case 'contrast': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_CONTRAST, intval($_POST['contrast_val'])); $edited = true; } break;
                case 'watermark':
                    $text = trim($_POST['wm_text'] ?? '');
                    if ($text) {
                        $color = imagecolorallocate($image, 255, 255, 255); 
                        $shadow = imagecolorallocate($image, 0, 0, 0);
                        $font = 5; $w = imagefontwidth($font) * strlen($text); $h = imagefontheight($font);
                        $pos = $_POST['wm_position'] ?? 'br';
                        $x=10; $y=10;
                        if($pos=='tr'||$pos=='br') $x=$width-$w-20;
                        if($pos=='bl'||$pos=='br') $y=$height-$h-20;
                        if($pos=='c'){$x=($width/2)-($w/2); $y=($height/2)-($h/2);}
                        imagestring($image, $font, $x+1, $y+1, $text, $shadow);
                        imagestring($image, $font, $x, $y, $text, $color);
                        $edited = true;
                    }
                    break;
                case 'rotate':
                    if(function_exists('imagerotate')) {
                        $image = imagerotate($image, intval($_POST['rotate_deg']), imagecolorallocatealpha($image, 0, 0, 0, 127));
                        imagesavealpha($image, true); $edited = true;
                    }
                    break;
                case 'resize':
                    $nw = intval($_POST['resize_width']); $nh = intval($nw * ($height/$width));
                    $newImg = imagecreatetruecolor($nw, $nh);
                    if($mime=='image/png'||$mime=='image/gif') { imagealphablending($newImg, false); imagesavealpha($newImg, true); $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127); imagefilledrectangle($newImg, 0, 0, $nw, $nh, $transparent); }
                    imagecopyresampled($newImg, $image, 0,0,0,0, $nw, $nh, $width, $height);
                    imagedestroy($image); $image = $newImg; $edited = true;
                    break;
            }

            if ($edited) {
                save_image($image, $finalSavePath, $mime);
                $_SESSION['flash_msg'] = "<div class='toast success'>Effect applied! ?</div>";
                imagedestroy($image);
            }

        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>" . $e->getMessage() . "</div>";
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

 $message = isset($_SESSION['flash_msg']) ? $_SESSION['flash_msg'] : "";
unset($_SESSION['flash_msg']);
 $images = glob($uploadDir . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);
if ($images) usort($images, function($a, $b) { return filemtime($b) - filemtime($a); });
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pink Glass Album</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6b6b; 
            --primary-dark: #ee5253;
            --pink-glass-bg: rgba(255, 182, 193, 0.45);  /* Pink Glass */
            --pink-glass-border: rgba(255, 255, 255, 0.65);
            --pink-glass-shadow: 0 8px 32px 0 rgba(255, 107, 107, 0.25);
            --dark: #2d3436;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #ffd1dc 0%, #ffe4e1 50%, #fff0f5 100%); /* Soft Pink Gradient Background */
            min-height: 100vh; 
            color: var(--dark); 
            padding: 20px; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            overflow-x: hidden;
        }

        /* ANIMATED HEARTS */
        .heart-bg { position: fixed; top: -50px; z-index: 0; animation: fallDown linear infinite; opacity: 0.6; pointer-events: none; font-size: 20px; color: #ff4757; }
        @keyframes fallDown { 0% { transform: translateY(0) rotate(0deg); opacity: 0.6; } 100% { transform: translateY(110vh) rotate(360deg); opacity: 0; } }

        .container { width: 100%; max-width: 1200px; z-index: 1; position: relative; }

        header { text-align: center; margin-bottom: 30px; animation: fadeInDown 1s ease-out; }
        h1 { font-size: 3.5rem; font-weight: 800; color: #fff; text-shadow: 0 4px 15px rgba(238, 82, 83, 0.3); display: inline-flex; align-items: center; gap: 15px; }
        .heart-icon { color: #ff4757; animation: beat 1.2s infinite linear; display: inline-block; filter: drop-shadow(0 0 10px rgba(255, 71, 87, 0.5)); }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.3); } }
        p.subtitle { font-size: 1.2rem; color: rgba(255,71,87,0.9); font-weight: 600; letter-spacing: 1px; text-shadow: 0 2px 4px rgba(0,0,0,0.05); }

        /* Toast */
        .toast { position: fixed; top: 20px; right: 20px; padding: 18px 30px; border-radius: 50px; color: white; font-weight: 600; box-shadow: 0 15px 40px rgba(0,0,0,0.2); z-index: 3000; transform: translateX(300%); transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); display: flex; align-items: center; gap: 10px; }
        .toast.show { transform: translateX(0); }
        .toast.success { background: linear-gradient(135deg, #ff9a9e, #fecfef); color: #d63384; border: 1px solid rgba(255,255,255,0.5); }
        .toast.error { background: linear-gradient(135deg, #ff6b6b, #ee5253); }

        /* MODAL STYLES */
        .modal-wrapper {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; top: 0; width: 100%; height: 100%; 
            background-color: rgba(255, 182, 193, 0.4); 
            backdrop-filter: blur(8px);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-wrapper.show { opacity: 1; }
        
        /* PINK FROSTED GLASS CARD */
        .glass-card {
            background: var(--pink-glass-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 24px;
            border: 2px solid var(--pink-glass-border);
            box-shadow: var(--pink-glass-shadow), inset 0 0 30px rgba(255, 255, 255, 0.4);
            padding: 40px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            color: #892c2c;
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .section-header { display: flex; align-items: center; gap: 15px; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.6); }
        .section-header h2 { font-size: 1.5rem; color: #fff; font-weight: 700; text-shadow: 0 2px 5px rgba(214, 51, 132, 0.2); }

        .close-btn {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 2rem;
            color: #fff;
            cursor: pointer;
            transition: 0.3s;
            z-index: 10;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .close-btn:hover { color: #ff6b6b; transform: rotate(90deg); }

        /* Forms */
        .editor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 10px; }
        label { font-size: 0.8rem; font-weight: 700; color: rgba(255,255,255,0.95); text-transform: uppercase; letter-spacing: 1.2px; text-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        
        input[type="text"], input[type="number"], select { 
            width: 100%; padding: 14px 18px; border-radius: 14px; border: 2px solid rgba(255,255,255,0.5); 
            background: rgba(255, 255, 255, 0.6); font-family: 'Poppins', sans-serif; font-size: 0.95rem; 
            transition: all 0.3s ease; color: var(--dark); 
        }
        input:focus, select:focus { background: rgba(255, 255, 255, 0.9); border-color: #ff9a9e; box-shadow: 0 0 0 4px rgba(255, 107, 107, 0.2); }

        input[type=range] { -webkit-appearance: none; width: 100%; height: 8px; background: rgba(255,255,255,0.5); border-radius: 10px; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 22px; height: 22px; border-radius: 50%; background: #ff6b6b; cursor: pointer; border: 3px solid white; }
        
        input[type="color"] { width: 100%; height: 50px; border-radius: 14px; cursor: pointer; background: none; border: none; }

        /* Buttons */
        .btn { padding: 14px 28px; border: none; border-radius: 50px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #ff9a9e, #ff6b6b); color: white; box-shadow: 0 10px 25px rgba(255, 107, 107, 0.3); }
        .btn-primary:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 15px 35px rgba(255, 107, 107, 0.5); }
        .btn-sm { padding: 10px 20px; font-size: 0.8rem; }
        .btn-danger { background: linear-gradient(135deg, #ff6b6b, #d63031); color: white; }
        .btn-success { background: linear-gradient(135deg, #ff9a9e, #ff6b6b); color: white; }
        .btn-ghost { background: rgba(255,255,255,0.8); color: #ff6b6b; font-weight: 700; border: 2px solid #ff6b6b; }

        .checkbox-group { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .checkbox-group input { width: 18px; height: 18px; accent-color: #ff6b6b; }

        /* Gallery Grid */
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
        
        .gallery-item {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            background: #fff;
            aspect-ratio: 4/3;
            transition: all 0.4s ease;
            border: 4px solid rgba(255,255,255,0.6);
        }
        .gallery-item:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 50px rgba(255, 107, 107, 0.25);
        }
        
        .img-box { width: 100%; height: 100%; overflow: hidden; cursor: zoom-in; }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.7s ease; }
        .gallery-item:hover .gallery-img { transform: scale(1.1); }

        /* OVERLAY BUTTONS */
        .overlay {
            position: absolute;
            bottom: 0; left: 0; width: 100%;
            background: linear-gradient(to top, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.7), transparent);
            padding: 40px 15px 15px 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 2;
        }

        .file-name { 
            position: absolute; 
            top: 15px; left: 15px; 
            background: rgba(255,255,255,0.9); 
            padding: 8px 15px; 
            border-radius: 50px; 
            font-size: 0.8rem; 
            font-weight: 700; 
            color: var(--dark); 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1); 
            z-index: 3; 
        }

        /* Floating Action Button (FAB) */
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            flex-direction: column-reverse;
            gap: 15px;
            z-index: 100;
        }
        .fab-main {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff9a9e, #ff6b6b);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 5px 20px rgba(255, 107, 107, 0.5);
            transition: transform 0.3s;
        }
        .fab-main:hover { transform: scale(1.1) rotate(90deg); }
        
        .fab-items {
            display: flex;
            flex-direction: column;
            gap: 15px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            transform: translateY(20px);
        }
        .fab-container.active .fab-items { opacity: 1; visibility: visible; transform: translateY(0); }
        
        .fab-item {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(5px);
            padding: 12px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            cursor: pointer;
            font-weight: 600;
            color: #ff6b6b;
            transition: transform 0.2s;
            border: 1px solid rgba(255,107,107,0.2);
        }
        .fab-item:hover { transform: translateX(-5px); }

        .hidden { display: none !important; }

        /* Zoom Modal */
        .zoom-modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(255, 182, 193, 0.9); backdrop-filter: blur(10px); opacity: 0; transition: opacity 0.3s ease; justify-content: center; align-items: center; }
        .zoom-modal.show { opacity: 1; }
        .modal-content { max-width: 95%; max-height: 95%; border-radius: 20px; box-shadow: 0 0 80px rgba(0,0,0,0.2); animation: zoomIn 0.4s ease; border: 5px solid white; }
        .close-modal { position: absolute; top: 40px; right: 50px; color: white; font-size: 70px; font-weight: 300; cursor: pointer; transition: 0.3s; z-index: 4001; line-height: 1; text-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .close-modal:hover { color: #ff6b6b; transform: rotate(90deg); }

        @keyframes zoomIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) { h1 { font-size: 2.5rem; } .gallery-grid { grid-template-columns: 1fr; } .glass-card { padding: 20px; width: 95%; } }
    </style>
</head>
<body>

<!-- Floating Hearts Container -->
<div id="hearts-container"></div>

<div class="container">
    <?= $message ?>

    <header>
        <h1>
            <span class="heart-icon"></span> Iya,dags Album <span class="heart-icon"></span>
        </h1>
        <p class="subtitle">Premium Pink Glass Edition</p>
    </header>

    <!-- GALLERY MAIN VIEW -->
    <div class="gallery-grid">
        <?php if (empty($images)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 60px; color: #ff6b6b; background: rgba(255,255,255,0.4); border-radius: 30px; border: 2px dashed rgba(255,107,107,0.5); backdrop-filter: blur(5px);">
                <h3>No memories yet.</h3><p>Click the pink button below to Upload.</p>
            </div>
        <?php else: ?>
            <?php foreach($images as $img): $imgUrl = $img . '?v=' . filemtime($img); ?>
                <div class="gallery-item">
                    <span class="file-name"><?= htmlspecialchars(basename($img)) ?></span>
                    <div class="img-box" onclick="zoomImage('<?= $imgUrl ?>')">
                        <img src="<?= $imgUrl ?>" class="gallery-img" alt="Image">
                    </div>
                    <div class="overlay">
                        <div class="action-row">
                            <a href="<?= $img ?>" download class="btn btn-sm btn-success">Download</a>
                            <button class="btn btn-sm btn-ghost" onclick="openEditor('<?= basename($img) ?>')">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?= basename($img) ?>')">Delete</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- FLOATING ACTION BUTTON -->
<div class="fab-container" id="fabBtn">
    <div class="fab-main" onclick="toggleFab()">+</div>
    <div class="fab-items">
        <div class="fab-item" onclick="openModal('upload')">
            <span style="font-size:1.2rem"></span> Upload Memory
        </div>
        <div class="fab-item" onclick="openModal('editor')">
            <span style="font-size:1.2rem"></span> Studio Editor
        </div>
    </div>
</div>

<!-- MODAL: UPLOAD -->
<div id="uploadModal" class="modal-wrapper" onclick="closeModalOutside(event, 'uploadModal')">
    <div class="glass-card" onclick="event.stopPropagation()">
        <span class="close-btn" onclick="closeModal('uploadModal')">&times;</span>
        <div class="section-header"><span style="font-size:1.5rem"></span><h2>Upload New Memory</h2></div>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <div style="margin-top: 20px;">
                <input type="file" name="image" required accept="image/*" style="width:100%; background:rgba(255,255,255,0.8); padding:15px; border-radius:15px; border:2px solid rgba(255,255,255,0.5); color:#ff6b6b;">
                <button type="submit" class="btn btn-primary" style="width:100%; margin-top:20px;">Upload Now </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: EDITOR -->
<div id="editorModal" class="modal-wrapper" onclick="closeModalOutside(event, 'editorModal')">
    <div class="glass-card" id="editor-anchor" onclick="event.stopPropagation()">
        <span class="close-btn" onclick="closeModal('editorModal')">&times;</span>
        <div class="section-header"><span style="font-size:1.5rem"></span><h2>Studio Editor</h2></div>
        <form action="" method="post">
            <input type="hidden" name="action" value="edit">
            <div class="editor-grid">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Source Image</label>
                    <select name="target_image" required id="imgSelect">
                        <option value="">-- Select from gallery --</option>
                        <?php if($images): foreach($images as $img): ?>
                            <option value="<?= basename($img) ?>"><?= htmlspecialchars(basename($img)) ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Effect Mode</label>
                    <select name="effect_type" id="effectSelect" onchange="toggleInputs()">
                        <option value="grayscale">Grayscale</option>
                        <option value="invert">Invert Colors</option>
                        <option value="sepia">Sepia (Vintage)</option>
                        <option value="colorize">Color Tint</option>
                        <option value="brightness">Brightness</option>
                        <option value="contrast">Contrast</option>
                        <option value="watermark">Watermark</option>
                        <option value="rotate">Rotate</option>
                        <option value="resize">Resize</option>
                    </select>
                </div>

                <div class="form-group hidden" id="colorizeInput"><label>Tint Color</label><input type="color" name="tint_color" value="#ff0000"></div>
                <div class="form-group hidden" id="brightnessInput"><label>Brightness</label><input type="range" name="brightness_val" min="-255" max="255" value="0"></div>
                <div class="form-group hidden" id="contrastInput"><label>Contrast</label><input type="range" name="contrast_val" min="-100" max="100" value="0"></div>
                <div class="form-group hidden" id="wmInput"><label>Watermark Text</label><input type="text" name="wm_text" placeholder="Enter text..."></div>
                <div class="form-group hidden" id="wmPosInput"><label>Placement</label><select name="wm_position"><option value="tl">Top-Left</option><option value="tr">Top-Right</option><option value="c">Center</option><option value="bl">Bottom-Left</option><option value="br" selected>Bottom-Right</option></select></div>
                <div class="form-group hidden" id="rotateInput"><label>Rotation</label><select name="rotate_deg"><option value="90">90° CW</option><option value="180">180°</option><option value="-90">90° CCW</option></select></div>
                <div class="form-group hidden" id="resizeInput"><label>Width (px)</label><input type="number" name="resize_width" placeholder="e.g. 800" min="10"><small style="color:rgba(255,255,255,0.7); font-size:0.75rem;">Height auto-calculated</small></div>

                <div class="form-group">
                    <label class="checkbox-group"><input type="checkbox" name="save_as_copy" id="saveCopy"><span>Save as New Copy</span></label>
                    <button type="submit" class="btn btn-primary">Apply Effect ?</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ZOOM MODAL -->
<div id="imageModal" class="zoom-modal">
    <span class="close-modal" onclick="closeZoom()">&times;</span>
    <img class="modal-content" id="zoomedImg">
</div>

<!-- Hidden Forms -->
<form id="deleteForm" action="" method="post">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="target_image" id="deleteTarget">
</form>

<script>
// --- Logic: Clear Images on Tab Close ---
document.addEventListener("DOMContentLoaded", () => {
    const sessionKey = 'album_is_active';
    const dirtyKey = 'album_has_data';
    const isNewTab = !sessionStorage.getItem(sessionKey);
    const hadData = localStorage.getItem(dirtyKey) === 'true';
    const serverHasFiles = <?= empty($images) ? 'false' : 'true' ?>;

    if (isNewTab) {
        sessionStorage.setItem(sessionKey, 'true');
        if (hadData || serverHasFiles) {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reset_session'
            }).then(() => {
                localStorage.removeItem(dirtyKey);
                location.reload();
            });
        }
    }

    const uploadForm = document.querySelector('form[enctype="multipart/form-data"]');
    if (uploadForm) {
        uploadForm.addEventListener('submit', () => {
            localStorage.setItem(dirtyKey, 'true');
        });
    }

    const toast = document.querySelector('.toast');
    if(toast) { setTimeout(() => toast.classList.add('show'), 100); setTimeout(() => toast.classList.remove('show'), 4000); }
});

// --- Modal Controls ---
function toggleFab() {
    document.getElementById('fabBtn').classList.toggle('active');
}

function openModal(type) {
    toggleFab(); // Close fab menu
    const modalId = type + 'Modal';
    const modal = document.getElementById(modalId);
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = 'none', 300);
}

function closeModalOutside(event, modalId) {
    if (event.target.id === modalId) {
        closeModal(modalId);
    }
}

// Specific function to open editor from gallery
function openEditor(filename) {
    openModal('editor');
    const select = document.getElementById('imgSelect');
    select.value = filename;
    select.style.borderColor = '#ff6b6b';
    setTimeout(() => select.style.borderColor = '', 500);
}

// --- Logic: Editor Inputs ---
function toggleInputs() {
    const effect = document.getElementById('effectSelect').value;
    const inputs = ['colorizeInput', 'brightnessInput', 'contrastInput', 'wmInput', 'wmPosInput', 'rotateInput', 'resizeInput'];
    inputs.forEach(id => document.getElementById(id).classList.add('hidden'));
    
    if (effect === 'colorize') document.getElementById('colorizeInput').classList.remove('hidden');
    if (effect === 'brightness') document.getElementById('brightnessInput').classList.remove('hidden');
    if (effect === 'contrast') document.getElementById('contrastInput').classList.remove('hidden');
    if (effect === 'watermark') { document.getElementById('wmInput').classList.remove('hidden'); document.getElementById('wmPosInput').classList.remove('hidden'); }
    if (effect === 'rotate') document.getElementById('rotateInput').classList.remove('hidden');
    if (effect === 'resize') document.getElementById('resizeInput').classList.remove('hidden');
}

function confirmDelete(filename) {
    if(confirm("Delete this image?")) {
        document.getElementById('deleteTarget').value = filename;
        document.getElementById('deleteForm').submit();
    }
}

// --- Zoom Logic ---
function zoomImage(src) {
    const modal = document.getElementById("imageModal");
    const modalImg = document.getElementById("zoomedImg");
    modal.style.display = "flex";
    setTimeout(() => modal.classList.add('show'), 10);
    modalImg.src = src;
}

function closeZoom() {
    const modal = document.getElementById("imageModal");
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = "none", 300);
}

window.onclick = function(event) {
    const modal = document.getElementById("imageModal");
    if (event.target == modal) closeZoom();
}

// --- Logic: Falling Hearts ---
function createHeart() {
    const heart = document.createElement('div');
    heart.innerHTML = '?';
    heart.classList.add('heart-bg');
    heart.style.left = Math.random() * 100 + 'vw';
    heart.style.fontSize = (Math.random() * 20 + 10) + 'px';
    heart.style.animationDuration = (Math.random() * 3 + 3) + 's';
    document.body.appendChild(heart);
    setTimeout(() => heart.remove(), 6000);
}
setInterval(createHeart, 300);
</script>

</body>
</html>