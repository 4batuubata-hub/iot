<?php
// Script to zip the iot directory and send it via Telegram Bot
date_default_timezone_set('Asia/Jakarta');

$botToken = '5619749746:AAET5C5PoczxCnd-p_-DQ9HraceecRAMYRs';
$chatId = '2057077079';
$password = 'Merah123';
$sourceDir = 'C:\xampp\htdocs\iot';
$zipFile = 'C:\xampp\htdocs\iot_backup_' . date('Ymd_His') . '.zip';

// Ambil argument update dari command line
$updateDetails = isset($argv[1]) ? $argv[1] : "Pembaruan rutin sistem IoT.";
$caption = "📦 *UPDATE SISTEM IOT*\n📅 Tanggal: " . date('Y-m-d H:i:s') . "\n\n*Detail Update:*\n" . $updateDetails;

echo "Mulai mengompres direktori IoT...\n";

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    if (method_exists($zip, 'setEncryptionName')) {
        $zip->setPassword($password);
    } else {
        echo "Warning: PHP versi ini tidak mendukung setEncryptionName, zip tidak dipassword!\n";
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            
            // Exclude .git and .agents folders if desired to save size
            if (strpos($relativePath, '.git') !== false) {
                continue;
            }

            $zip->addFile($filePath, $relativePath);
            
            if (method_exists($zip, 'setEncryptionName')) {
                $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256);
            }
        }
    }
    $zip->close();
    echo "Berhasil membuat file ZIP: $zipFile\n";
} else {
    die("Gagal membuat file ZIP!\n");
}

echo "Mengirim ke Telegram...\n";

// Mengirim via Telegram menggunakan cURL
$url = "https://api.telegram.org/bot$botToken/sendDocument";

$cfile = new CURLFile($zipFile, 'application/zip', basename($zipFile));
$data = [
    'chat_id' => $chatId,
    'document' => $cfile,
    'caption' => $caption,
    'parse_mode' => 'Markdown'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 200) {
    echo "Sukses! Berhasil mengirim ZIP ke Telegram.\n";
} else {
    echo "Gagal mengirim ke Telegram. Error: $response\n";
}

// Opsional: Hapus file zip lokal setelah dikirim
if (file_exists($zipFile)) {
    unlink($zipFile);
}
?>
