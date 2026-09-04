<?php

$config = require __DIR__ .
    '/app/private/boulevard-secrets.php';

$businessId =
    trim($config['business_id'] ?? '');

$apiKey =
    trim($config['api_key'] ?? '');

$secret =
    trim($config['secret_key'] ?? '');

$normalizedSecret =
    strtr(
        $secret,
        '._-',
        '+/='
    );

$decodedSecret =
    base64_decode(
        $normalizedSecret,
        true
    );

echo "Boulevard Auth Diagnostic\n";
echo "=========================\n\n";

echo "API key present: ";
echo $apiKey !== '' ? "YES\n" : "NO\n";

echo "API key length: ";
echo strlen($apiKey) . "\n\n";

echo "Secret present: ";
echo $secret !== '' ? "YES\n" : "NO\n";

echo "Secret length: ";
echo strlen($secret) . "\n";

echo "Secret Base64 decodes: ";
echo $decodedSecret !== false
    ? "YES\n"
    : "NO\n";

echo "\nBusiness ID present: ";
echo $businessId !== ''
    ? "YES\n"
    : "NO\n";

echo "Business ID length: ";
echo strlen($businessId) . "\n";

echo "Business ID starts with URN: ";
echo str_starts_with(
    $businessId,
    'urn:blvd:Business:'
)
    ? "YES\n"
    : "NO\n";

echo "\nPHP timestamp: ";
echo time() . "\n";

echo "PHP time: ";
echo date('c') . "\n";