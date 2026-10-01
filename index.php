<?php
/**
 * Optimized PHP File & Folder Manager
 */

// ==========================================
// 1. CONFIGURATION
// ==========================================
$REL_BASE = "../Downloads";
$UPLOAD_BASE = __DIR__ . '/' . $REL_BASE;

// ==========================================
// 2. HELPER FUNCTIONS
// ==========================================

/**
 * Safely resolves and constrains relative paths within the base directory.
 */
function resolvePath(string $base, string $relative): array {
    $realBase = realpath($base);
    if ($realBase === false) {
        mkdir($base, 0755, true);
        $realBase = realpath($base);
    }

    $cleanRelative = str_replace(['..', "\0"], '', $relative);
    $cleanRelative = trim($cleanRelative, '/\\');
    
    $target = $realBase . ($cleanRelative ? '/' . $cleanRelative : '');
    $realTarget = realpath($target);

    if ($realTarget && is_dir($realTarget) && strpos($realTarget, $realBase) === 0) {
        $normalizedRel = trim(str_replace($realBase, '', $realTarget), '/\\');
        return [
            'absolute' => $realTarget,
            'relative' => str_replace('\\', '/', $normalizedRel)
        ];
    }

    return ['absolute' => $realBase, 'relative' => ''];
}

/**
 * Converts bytes to a human-readable string.
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Recursively deletes a directory and its contents.
 */
function deleteRecursive(string $dir): bool {
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteRecursive($path) : unlink($path);
    }
    return rmdir($dir);
}

// ==========================================
// 3. INITIALIZATION & ROUTING
// ==========================================
$resolved = resolvePath($UPLOAD_BASE, $_REQUEST['current_dir'] ?? '');
$currentDir = $resolved['absolute'];
$relativeDir = $resolved['relative'];
$rootUploads = realpath($UPLOAD_BASE);

$message = '';
$messageType = 'info';

// ==========================================
// 4. REQUEST HANDLING (POST ACTIONS)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'upload':
            if (!empty($_FILES['fileToUpload']['name'][0])) {
                $files = $_FILES['fileToUpload'];
                $successCount = 0;

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $originalName = $files['name'][$i];
                        
                        // Sanitize path parts to prevent traversal
                        $pathParts = explode('/', str_replace('\\', '/', $originalName));
                        $sanitizedParts = array_map(function($part) {
                            return preg_replace("/[^a-zA-Z0-9\.\-_]/", "_", $part);
                        }, $pathParts);
                        
                        $safeRelativePath = implode('/', $sanitizedParts);
                        $targetFilePath = $currentDir . '/' . $safeRelativePath;
                        
                        // Create nested folders if uploading a directory tree
                        $targetSubDir = dirname($targetFilePath);
                        if (!is_dir($targetSubDir)) {
                            mkdir($targetSubDir, 0755, true);
                        }

                        if (move_uploaded_file($files['tmp_name'][$i], $targetFilePath)) {
                            $successCount++;
                        }
                    }
                }

                $message = $successCount > 0 ? "Successfully uploaded $successCount item(s)." : "Failed to upload items.";
                $messageType = $successCount > 0 ? 'success' : 'error';
            } else {
                $message = "No files selected.";
                $messageType = 'error';
            }
            break;

        case 'create_folder':
            $folderName = trim($_POST['folder_name'] ?? '');
            $folderName = preg_replace("/[^a-zA-Z0-9\-_]/", "_", $folderName);

            if (!empty($folderName)) {
                $newFolderPath = $currentDir . '/' . $folderName;
                if (!is_dir($newFolderPath)) {
                    if (mkdir($newFolderPath, 0755, true)) {
                        $message = "Folder '$folderName' created successfully.";
                        $messageType = 'success';
                    } else {
                        $message = "Could not create folder.";
                        $messageType = 'error';
                    }
                } else {
                    $message = "Folder already exists.";
                    $messageType = 'error';
                }
            } else {
                $message = "Invalid folder name.";
                $messageType = 'error';
            }
            break;

        case 'rename':
            $oldName = basename($_POST['old_name'] ?? '');
            $newName = basename($_POST['new_name'] ?? '');
            $newName = preg_replace("/[^a-zA-Z0-9\.\-_]/", "_", $newName);

            $oldPath = $currentDir . '/' . $oldName;
            $newPath = $currentDir . '/' . $newName;

            if ($oldName && $newName && file_exists($oldPath)) {
                if (!file_exists($newPath)) {
                    if (rename($oldPath, $newPath)) {
                        $message = "Renamed successfully.";
                        $messageType = 'success';
                    } else {
                        $message = "Could not rename item.";
                        $messageType = 'error';
                    }
                } else {
                    $message = "An item with that name already exists.";
                    $messageType = 'error';
                }
            } else {
                $message = "Invalid operation or target not found.";
                $messageType = 'error';
            }
            break;

        case 'delete':
            $itemName = basename($_POST['item_name'] ?? '');
            $itemPath = $currentDir . '/' . $itemName;

            if ($itemName && file_exists($itemPath)) {
                $deleted = is_dir($itemPath) ? deleteRecursive($itemPath) : unlink($itemPath);
                if ($deleted) {
                    $message = "Item '$itemName' deleted successfully.";
                    $messageType = 'success';
                } else {
                    $message = "Could not delete item.";
                    $messageType = 'error';
                }
            } else {
                $message = "Item not found.";
                $messageType = 'error';
            }
            break;
    }
}

// ==========================================
// 5. DATA PREPARATION (DIRECTORY CONTENTS)
// ==========================================
$items = array_diff(scandir($currentDir), ['.', '..']);
$folders = [];
$files = [];

foreach ($items as $item) {
    if (is_dir($currentDir . '/' . $item)) {
        $folders[] = $item;
    } else {
        $files[] = $item;
    }
}
sort($folders);
sort($files);

// Breadcrumb/Parent path calculations
$parentDir = '';
if ($relativeDir !== '') {
    $pathParts = explode('/', $relativeDir);
    array_pop($pathParts);
    $parentDir = implode('/', $pathParts);
}
$defaultFolderName = bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File & Folder Manager</title>
    <style>
        :root {
            --primary: #0066cc;
            --success: #28a745;
            --danger: #dc3545;
            --bg-light: #f8f9fa;
            --border-color: #dee2e6;
        }
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #333; background: #fff; }
        h2, h3 { margin-top: 0; }
        .message { padding: 12px 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid transparent; }
        .message.success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .message.info { background: #e2e3e5; color: #383d41; border-color: #d6d8db; }
        .actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .section { padding: 20px; background: var(--bg-light); border: 1px solid var(--border-color); border-radius: 6px; }
        .breadcrumb { font-weight: 600; margin-bottom: 15px; font-size: 1.1em; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-color); }
        th, td { padding: 12px 15px; border-bottom: 1px solid var(--border-color); text-align: left; }
        th { background: #f1f3f5; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        form { display: inline-block; margin: 0; }
        input[type="text"] { padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 4px; width: 110px; }
        button { padding: 6px 12px; border: 1px solid var(--border-color); background: #fff; border-radius: 4px; cursor: pointer; font-weight: 500; }
        button:hover { background: #e9ecef; }
        button.btn-danger { color: var(--danger); border-color: #f5c6cb; }
        button.btn-danger:hover { background: #f8d7da; }
        button.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        button.btn-primary:hover { background: #0056b3; }
        .folder-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        .folder-link:hover { text-decoration: underline; }
        .back-link { display: inline-block; margin-bottom: 15px; color: var(--primary); text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 600px) { .actions-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <h2>File & Folder Manager</h2>

    <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb">
        Path: /<?php echo htmlspecialchars(basename($rootUploads)); ?>/<?php echo htmlspecialchars($relativeDir); ?>
    </div>

    <?php if ($relativeDir !== ''): ?>
        <a class="back-link" href="?current_dir=<?php echo urlencode($parentDir); ?>">← Back to Parent Directory</a>
    <?php endif; ?>

    <!-- Action Sections -->
    <div class="actions-grid">
        <div class="section">
            <h3>Upload Content</h3>
            <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <input type="file" name="fileToUpload[]" webkitdirectory directory multiple required style="width: 100%; margin-bottom: 12px;">
                <button type="submit" class="btn-primary">Upload Selected</button>
            </form>
        </div>

        <div class="section">
            <h3>Create Subfolder</h3>
            <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST">
                <input type="hidden" name="action" value="create_folder">
                <input type="text" name="folder_name" value="<?php echo htmlspecialchars($defaultFolderName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Folder name" required style="width: 100%; margin-bottom: 12px; box-sizing: border-box;">
                <button type="submit" class="btn-primary">Create Folder</button>
            </form>
        </div>
    </div>

    <!-- Contents Table -->
    <h3>Directory Contents</h3>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($folders) && empty($files)): ?>
                <tr><td colspan="4" style="text-align: center; color: #777;">This directory is empty.</td></tr>
            <?php endif; ?>

            <!-- Folders -->
            <?php foreach ($folders as $folder): ?>
                <?php $subDirPath = ($relativeDir ? $relativeDir . '/' : '') . $folder; ?>
                <?php $folderUrlPath = $REL_BASE . "/" . ($relativeDir ? $relativeDir . '/' : '') . $folder; ?>
                <tr>
                    <td>
                        📁 <a class="folder-link" href="?current_dir=<?php echo urlencode($subDirPath); ?>">
                            <?php echo htmlspecialchars($folder); ?>
                        </a>
                    </td>
                    <td>Folder</td>
                    <td>-</td>
                    <td>
                        <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST" style="margin-right: 4px;">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($folder); ?>">
                            <input type="text" name="new_name" placeholder="New name" required>
                            <button type="submit">Rename</button>
                        </form>
                        <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST" onsubmit="return confirm('Delete folder and all contents recursively?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($folder); ?>">
                            <button type="submit" class="btn-danger">Delete</button>
                        </form>
                        <a href="<?php echo htmlspecialchars($folderUrlPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">🡆</a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <!-- Files -->
            <?php foreach ($files as $file): ?>
                <?php $filePath = $currentDir . '/' . $file; ?>
                <?php $fileUrlPath = $REL_BASE . "/" . ($relativeDir ? $relativeDir . '/' : '') . $file; ?>
                <tr>
                    <td>📄 <?php echo htmlspecialchars($file); ?></td>
                    <td>File</td>
                    <td><?php echo formatBytes(filesize($filePath)); ?></td>
                    <td>
                        <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST" style="margin-right: 4px;">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($file); ?>">
                            <input type="text" name="new_name" placeholder="New name" required>
                            <button type="submit">Rename</button>
                        </form>
                        <form action="?current_dir=<?php echo urlencode($relativeDir); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this file?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($file); ?>">
                            <button type="submit" class="btn-danger">Delete</button>
                        </form>
                        <a href="<?php echo htmlspecialchars($fileUrlPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">🡆</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>

