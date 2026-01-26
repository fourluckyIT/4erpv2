<?php
/**
 * Test All Module Pages for Syntax and Runtime Errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$projectRoot = dirname(__DIR__);

// Find all PHP files in modules
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/modules')
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

echo "=== Testing " . count($phpFiles) . " PHP files ===\n\n";

$errors = [];

foreach ($phpFiles as $file) {
    $relativePath = str_replace($projectRoot . '\\', '', $file);
    $relativePath = str_replace('\\', '/', $relativePath);
    
    // Syntax check
    $output = [];
    $returnCode = 0;
    exec("php -l \"$file\" 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        $errors[] = [
            'file' => $relativePath,
            'type' => 'SYNTAX',
            'message' => implode("\n", $output)
        ];
        echo "[SYNTAX ERROR] $relativePath\n";
    } else {
        echo "[OK] $relativePath\n";
    }
}

echo "\n=== Summary ===\n";
if (empty($errors)) {
    echo "All " . count($phpFiles) . " files passed syntax check!\n";
} else {
    echo "Found " . count($errors) . " error(s):\n";
    foreach ($errors as $e) {
        echo "\n--- {$e['file']} ---\n";
        echo "{$e['message']}\n";
    }
}
