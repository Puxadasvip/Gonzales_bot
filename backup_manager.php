<?php
/**
 * Sistema de Backup Automático
 * - Backup de arquivos críticos (VIP, pagamentos, configurações)
 * - Rotação de backups antigos
 * - Compressão ZIP
 */

declare(strict_types=1);

define('BACKUP_DIR', __DIR__ . '/backups');
define('MAX_BACKUPS', 10); // mantém últimos 10 backups

class BackupManager {
    
    private static array $criticalFiles = [
        'vip/users.json',
        'vip/payments.json',
        'data/security.json',
        'group_admin/data/groups.json',
        'group_admin/data_ga/groups.json',
        'misticpay/config.php',
    ];
    
    /**
     * Cria backup completo
     */
    public static function create(): array {
        // Cria diretório de backup
        if (!is_dir(BACKUP_DIR)) {
            @mkdir(BACKUP_DIR, 0775, true);
        }
        
        $timestamp = date('Y-m-d_His');
        $backupName = "backup_{$timestamp}";
        
        $result = [
            'success' => false,
            'backup_name' => $backupName,
            'timestamp' => date('Y-m-d H:i:s'),
            'files' => [],
            'errors' => []
        ];
        
        // Tenta criar ZIP se disponível
        if (class_exists('ZipArchive')) {
            return self::createZipBackup($backupName, $result);
        } else {
            return self::createFolderBackup($backupName, $result);
        }
    }
    
    /**
     * Backup em ZIP
     */
    private static function createZipBackup(string $backupName, array $result): array {
        $zipFile = BACKUP_DIR . "/{$backupName}.zip";
        $zip = new ZipArchive();
        
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $result['errors'][] = 'Não foi possível criar arquivo ZIP';
            return $result;
        }
        
        foreach (self::$criticalFiles as $file) {
            $fullPath = __DIR__ . '/' . $file;
            
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $file);
                $result['files'][] = $file;
            } else {
                $result['errors'][] = "Arquivo não encontrado: {$file}";
            }
        }
        
        $zip->close();
        
        $result['success'] = true;
        $result['backup_file'] = $zipFile;
        $result['size_kb'] = round(filesize($zipFile) / 1024, 2);
        
        self::cleanOldBackups();
        
        return $result;
    }
    
    /**
     * Backup em pasta
     */
    private static function createFolderBackup(string $backupName, array $result): array {
        $backupPath = BACKUP_DIR . '/' . $backupName;
        
        if (!@mkdir($backupPath, 0775, true)) {
            $result['errors'][] = 'Não foi possível criar diretório de backup';
            return $result;
        }
        
        foreach (self::$criticalFiles as $file) {
            $sourcePath = __DIR__ . '/' . $file;
            
            if (!file_exists($sourcePath)) {
                $result['errors'][] = "Arquivo não encontrado: {$file}";
                continue;
            }
            
            $destPath = $backupPath . '/' . $file;
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            
            if (@copy($sourcePath, $destPath)) {
                $result['files'][] = $file;
            } else {
                $result['errors'][] = "Erro ao copiar: {$file}";
            }
        }
        
        $result['success'] = true;
        $result['backup_folder'] = $backupPath;
        
        self::cleanOldBackups();
        
        return $result;
    }
    
    /**
     * Remove backups antigos
     */
    private static function cleanOldBackups(): void {
        if (!is_dir(BACKUP_DIR)) return;
        
        $backups = [];
        
        // Lista backups ZIP
        $zips = glob(BACKUP_DIR . '/backup_*.zip');
        if ($zips) {
            foreach ($zips as $zip) {
                $backups[] = ['path' => $zip, 'time' => filemtime($zip)];
            }
        }
        
        // Lista backups em pasta
        $folders = glob(BACKUP_DIR . '/backup_*', GLOB_ONLYDIR);
        if ($folders) {
            foreach ($folders as $folder) {
                $backups[] = ['path' => $folder, 'time' => filemtime($folder)];
            }
        }
        
        if (count($backups) <= MAX_BACKUPS) return;
        
        // Ordena por data
        usort($backups, fn($a, $b) => $a['time'] <=> $b['time']);
        
        // Remove os mais antigos
        $toRemove = count($backups) - MAX_BACKUPS;
        for ($i = 0; $i < $toRemove; $i++) {
            $path = $backups[$i]['path'];
            
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::removeDir($path);
            }
        }
    }
    
    /**
     * Remove diretório recursivamente
     */
    private static function removeDir(string $dir): void {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        
        @rmdir($dir);
    }
    
    /**
     * Lista backups disponíveis
     */
    public static function list(): array {
        if (!is_dir(BACKUP_DIR)) {
            return ['backups' => [], 'count' => 0];
        }
        
        $backups = [];
        
        // Lista ZIP
        $zips = glob(BACKUP_DIR . '/backup_*.zip');
        if ($zips) {
            foreach ($zips as $zip) {
                $backups[] = [
                    'name' => basename($zip),
                    'path' => $zip,
                    'type' => 'zip',
                    'size_kb' => round(filesize($zip) / 1024, 2),
                    'date' => date('Y-m-d H:i:s', filemtime($zip))
                ];
            }
        }
        
        // Lista pastas
        $folders = glob(BACKUP_DIR . '/backup_*', GLOB_ONLYDIR);
        if ($folders) {
            foreach ($folders as $folder) {
                $backups[] = [
                    'name' => basename($folder),
                    'path' => $folder,
                    'type' => 'folder',
                    'date' => date('Y-m-d H:i:s', filemtime($folder))
                ];
            }
        }
        
        // Ordena por data (mais recente primeiro)
        usort($backups, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));
        
        return [
            'backups' => $backups,
            'count' => count($backups)
        ];
    }
    
    /**
     * Restaura backup
     */
    public static function restore(string $backupName): array {
        $result = ['success' => false, 'restored' => [], 'errors' => []];
        
        $zipPath = BACKUP_DIR . "/{$backupName}.zip";
        $folderPath = BACKUP_DIR . "/{$backupName}";
        
        if (file_exists($zipPath)) {
            return self::restoreFromZip($zipPath, $result);
        } elseif (is_dir($folderPath)) {
            return self::restoreFromFolder($folderPath, $result);
        }
        
        $result['errors'][] = 'Backup não encontrado';
        return $result;
    }
    
    private static function restoreFromZip(string $zipPath, array $result): array {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            $result['errors'][] = 'Não foi possível abrir ZIP';
            return $result;
        }
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $destPath = __DIR__ . '/' . $filename;
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            
            $content = $zip->getFromIndex($i);
            if (file_put_contents($destPath, $content) !== false) {
                $result['restored'][] = $filename;
            } else {
                $result['errors'][] = "Erro ao restaurar: {$filename}";
            }
        }
        
        $zip->close();
        $result['success'] = true;
        
        return $result;
    }
    
    private static function restoreFromFolder(string $folderPath, array $result): array {
        foreach (self::$criticalFiles as $file) {
            $sourcePath = $folderPath . '/' . $file;
            $destPath = __DIR__ . '/' . $file;
            
            if (!file_exists($sourcePath)) {
                $result['errors'][] = "Arquivo não encontrado no backup: {$file}";
                continue;
            }
            
            if (@copy($sourcePath, $destPath)) {
                $result['restored'][] = $file;
            } else {
                $result['errors'][] = "Erro ao restaurar: {$file}";
            }
        }
        
        $result['success'] = true;
        return $result;
    }
}

// ===== EXECUÇÃO VIA CRON OU URL =====
if (php_sapi_name() === 'cli' || ($_GET['backup'] ?? '') === '1') {
    $result = BackupManager::create();
    
    if (php_sapi_name() === 'cli') {
        echo "=== BACKUP ===\n";
        echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        echo "Files: " . count($result['files']) . "\n";
        if ($result['errors']) {
            echo "Errors:\n";
            foreach ($result['errors'] as $err) {
                echo "  - $err\n";
            }
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== LISTA BACKUPS =====
if (($_GET['backup_list'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode(BackupManager::list(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
