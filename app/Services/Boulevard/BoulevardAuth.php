<?php

declare(strict_types=1);

final class BoulevardAuth
{
    public static function authorizationHeader(
        array $config
    ): string {

        $businessId =
            trim(
                (string) (
                    $config['business_id']
                    ?? ''
                )
            );

        $apiSecret =
            trim(
                (string) (
                    $config['secret_key']
                    ?? ''
                )
            );

        $apiKey =
            trim(
                (string) (
                    $config['api_key']
                    ?? ''
                )
            );

        if ($businessId === '') {
            throw new RuntimeException(
                'Boulevard business ID is missing.'
            );
        }

        if ($apiSecret === '') {
            throw new RuntimeException(
                'Boulevard secret key is missing.'
            );
        }

        if ($apiKey === '') {
            throw new RuntimeException(
                'Boulevard API key is missing.'
            );
        }


        /*
         * IMPORTANT:
         *
         * Unix timestamp.
         *
         * Do NOT convert this to India time.
         * Do NOT convert this to RUMA time.
         *
         * Unix timestamps are timezone-independent.
         */
        $timestamp = time();


        /*
         * Required Boulevard Admin API prefix.
         */
        $prefix = 'blvd-admin-v1';


        /*
         * Boulevard requires:
         *
         * prefix + business ID + timestamp
         */
        $payload =
            $prefix
            . $businessId
            . (string) $timestamp;


        /*
         * Boulevard secrets use URL-safe
         * Base64 characters.
         */
        $normalizedSecret =
            strtr(
                $apiSecret,
                '._-',
                '+/='
            );


        $rawKey =
            base64_decode(
                $normalizedSecret,
                true
            );


        if ($rawKey === false) {
            throw new RuntimeException(
                'Boulevard secret key could not be Base64 decoded.'
            );
        }


        /*
         * Generate raw HMAC-SHA256.
         */
        $rawMac =
            hash_hmac(
                'sha256',
                $payload,
                $rawKey,
                true
            );


        /*
         * Base64 encode raw MAC.
         */
        $signature =
            base64_encode(
                $rawMac
            );


        /*
         * Boulevard signed token.
         */
        $token =
            $signature
            . $payload;


        /*
         * Basic Authentication payload:
         *
         * API_KEY:TOKEN
         */
        $basicPayload =
            $apiKey
            . ':'
            . $token;


        $basicCredentials =
            base64_encode(
                $basicPayload
            );


        return
            'Basic '
            . $basicCredentials;
    }
}