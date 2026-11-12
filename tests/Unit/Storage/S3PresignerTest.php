<?php

namespace Tests\Unit\Storage;

use App\Storage\S3Presigner;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

class S3PresignerTest extends TestCase
{
    private function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'ap-southeast-1',
            'credentials' => new Credentials('AKIATEST', 'secret'),
        ]);
    }

    public function test_it_produces_the_same_signature_as_the_sdk_for_the_headers_the_sdk_signs(): void
    {
        $client = $this->client();
        $now = 1_700_000_000;
        $mine = S3Presigner::putUrl($client, 'sic', 'site/downloads/a b.pdf', null, null, 600, $now);

        $command = $client->getCommand('PutObject', ['Bucket' => 'sic', 'Key' => 'site/downloads/a b.pdf']);
        $sdk = (string) $client->createPresignedRequest($command, $now + 600, ['start_time' => $now])->getUri();

        parse_str(parse_url($mine, PHP_URL_QUERY), $a);
        parse_str(parse_url($sdk, PHP_URL_QUERY), $b);
        $this->assertSame($b['X-Amz-SignedHeaders'], $a['X-Amz-SignedHeaders']);
        $this->assertSame($b['X-Amz-Signature'], $a['X-Amz-Signature']);
    }

    public function test_the_length_is_part_of_the_signature(): void
    {
        $client = $this->client();
        $one = S3Presigner::putUrl($client, 'sic', 'a.pdf', 'application/pdf', 10, 600, 1_700_000_000);
        $other = S3Presigner::putUrl($client, 'sic', 'a.pdf', 'application/pdf', 11, 600, 1_700_000_000);
        parse_str(parse_url($one, PHP_URL_QUERY), $a);
        parse_str(parse_url($other, PHP_URL_QUERY), $b);
        $this->assertSame('content-length;content-type;host', $a['X-Amz-SignedHeaders']);
        $this->assertNotSame($a['X-Amz-Signature'], $b['X-Amz-Signature']);
    }
}
