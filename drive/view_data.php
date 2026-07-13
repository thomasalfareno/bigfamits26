<?php

// Read-only data loader for the Drive page.
$parent_id = $_SESSION['active_folder_id'] ?? null;
$isSuperAdmin = ($_SESSION['role'] ?? '') === 'superadmin';

$breadcrumbs = [];
$currFolderId = $parent_id;
while ($currFolderId) {
    try {
        $stmt = $conn->prepare("SELECT id_folder, nama, parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt->execute([$currFolderId]);
        $folder = $stmt->fetch();
        if (!$folder) {
            break;
        }
        array_unshift($breadcrumbs, $folder);
        $currFolderId = $folder['parent_id'];
    } catch (Exception $e) {
        break;
    }
}

$backFolderId = 0;
$showBackButton = (bool)$parent_id;
if ($parent_id) {
    try {
        $stmt = $conn->prepare("SELECT parent_id FROM folders WHERE id_folder = ? LIMIT 1");
        $stmt->execute([$parent_id]);
        $currentFolder = $stmt->fetch();
        $backFolderId = ($currentFolder && $currentFolder['parent_id'])
            ? (int)$currentFolder['parent_id']
            : 0;
    } catch (Exception $e) {
        $backFolderId = 0;
    }
}

$subfolders = [];
try {
    if ($parent_id) {
        $stmt = $conn->prepare("SELECT f.*, u.nama AS creator FROM folders f JOIN users u ON f.id_user = u.id_user WHERE f.parent_id = ? ORDER BY f.nama ASC");
        $stmt->execute([$parent_id]);
    } else {
        $stmt = $conn->query("SELECT f.*, u.nama AS creator FROM folders f JOIN users u ON f.id_user = u.id_user WHERE f.parent_id IS NULL ORDER BY f.nama ASC");
    }
    $subfolders = $stmt->fetchAll();
} catch (Exception $e) {}

$files = [];
try {
    if ($parent_id) {
        $stmt = $conn->prepare("SELECT f.*, u.nama AS uploader FROM files f JOIN users u ON f.id_user = u.id_user WHERE f.id_folder = ? ORDER BY f.nama ASC");
        $stmt->execute([$parent_id]);
    } else {
        $stmt = $conn->query("SELECT f.*, u.nama AS uploader FROM files f JOIN users u ON f.id_user = u.id_user WHERE f.id_folder IS NULL ORDER BY f.nama ASC");
    }
    $files = $stmt->fetchAll();
} catch (Exception $e) {}

$allFolders = [];
try {
    $allFolders = $conn
        ->query("SELECT id_folder, nama, parent_id FROM folders ORDER BY nama ASC")
        ->fetchAll();
} catch (Exception $e) {}
