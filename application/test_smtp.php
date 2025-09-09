<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing GoDaddy SMTP connectivity...\n\n";

// Test both ports: 465 (ssl) and 587 (tls)
$hosts = [
    // ['host' => 'smtpout.secureserver.net', 'port' => 465, 'crypto' => 'ssl'],
    ['host' => 'smtpout.secureserver.net', 'port' => 587, 'crypto' => 'tls'],
];

foreach ($hosts as $h) {
    $host = $h['host'];
    $port = $h['port'];
    $crypto = $h['crypto'];

    echo "Trying $host:$port ($crypto)...\n";

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 15);

    if (!$fp) {
        echo "❌ Connection failed: $errstr ($errno)\n\n";
    } else {
        echo "✅ Connected successfully to $host:$port\n";

        // Read server response
        $response = fgets($fp, 1024);
        echo "Server says: " . $response . "\n";

        fclose($fp);
    }
}
