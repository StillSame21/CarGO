<?php

function sendEmail(string $to, string $subject, string $body): bool
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . '/emails.txt';
    $timestamp = date('Y-m-d H:i:s');
    
    $logEntry = "[$timestamp] To: $to | Subject: $subject | Body: $body\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    return file_put_contents($logFile, $logEntry, FILE_APPEND) !== false;
}
