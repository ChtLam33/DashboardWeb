<?php
header("Content-Type: application/json; charset=utf-8");

$file = __DIR__ . "/data_cuves.csv";
$result = [];

if (file_exists($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > 1) {
        $header = str_getcsv(array_shift($lines), ';');
        foreach ($lines as $line) {
            $cols = str_getcsv($line, ';');
            if (count($cols) >= count($header)) {
                $result[] = array_combine($header, $cols);
            }
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
