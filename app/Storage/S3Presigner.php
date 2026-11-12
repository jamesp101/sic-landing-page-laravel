<?php

namespace App\Storage;

use Aws\S3\S3Client;

/**
 * SigV4 presigned PUT URLs that sign `content-type` and `content-length` (the AWS SDK presigner signs neither), so
 * the browser cannot store a different type or size than the server approved. Offline: needs the client's
 * credentials and region only.
 */
final class S3Presigner
{
    /**
     * @param  string  $objectKey  Key inside the bucket (with the disk prefix).
     * @param  ?string  $contentType  Left unsigned when null.
     * @param  ?int  $contentLength  Left unsigned when null.
     */
    public static function putUrl(S3Client $client, string $bucket, string $objectKey, ?string $contentType, ?int $contentLength, int $expiresSeconds, ?int $now = null): string
    {
        $credentials = $client->getCredentials()->wait();
        $region = $client->getRegion();
        $url = parse_url($client->getObjectUrl($bucket, $objectKey));
        $host = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $now ??= time();
        $date = gmdate('Ymd\THis\Z', $now);
        $day = gmdate('Ymd', $now);
        $scope = "{$day}/{$region}/s3/aws4_request";

        $headers = ['host' => $host];
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }
        if ($contentLength !== null) {
            $headers['content-length'] = (string) $contentLength;
        }
        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credentials->getAccessKeyId().'/'.$scope,
            'X-Amz-Content-Sha256' => 'UNSIGNED-PAYLOAD',
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];
        if ($credentials->getSecurityToken()) {
            $query['X-Amz-Security-Token'] = $credentials->getSecurityToken();
        }
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim(preg_replace('/\s+/', ' ', $value))."\n";
        }
        $path = $url['path'] ?? '/';
        $canonicalRequest = implode("\n", ['PUT', $path, $canonicalQuery, $canonicalHeaders, $signedHeaders, 'UNSIGNED-PAYLOAD']);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $date, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4'.$credentials->getSecretKey(), true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return $url['scheme'].'://'.$host.$path.'?'.$canonicalQuery.'&X-Amz-Signature='.$signature;
    }
}
