<?php

namespace Tests\Unit\Storage;

use App\Storage\DirectUpload;
use App\Storage\FileTypes;
use PHPUnit\Framework\TestCase;

class DirectUploadTest extends TestCase
{
    public function test_it_issues_random_downloads_keys_with_an_allowlisted_extension(): void
    {
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.pdf$#', DirectUpload::newDirectUploadKey('Student Handbook.PDF', 'application/pdf'));
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}$#', DirectUpload::newDirectUploadKey('no-extension', 'application/octet-stream'));
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.txt$#', DirectUpload::newDirectUploadKey('a.b/../c', 'text/plain'));
        $this->assertNotSame(DirectUpload::newDirectUploadKey('x.pdf', 'application/pdf'), DirectUpload::newDirectUploadKey('x.pdf', 'application/pdf'));
    }

    public function test_it_never_keeps_an_extension_the_storage_route_would_serve_as_markup(): void
    {
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.png$#', DirectUpload::newDirectUploadKey('x.html', 'image/png'));
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.pdf$#', DirectUpload::newDirectUploadKey('x.svg', 'application/pdf'));
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}$#', DirectUpload::newDirectUploadKey('x.htm', 'text/html'));
    }

    public function test_it_only_accepts_server_shaped_keys(): void
    {
        $this->assertTrue(DirectUpload::isDirectUploadKey('downloads/0123456789abcdef0123456789abcdef.pdf'));
        $this->assertFalse(DirectUpload::isDirectUploadKey('posts/0123456789abcdef0123456789abcdef.pdf'));
        $this->assertFalse(DirectUpload::isDirectUploadKey('downloads/../x.pdf'));
        $this->assertFalse(DirectUpload::isDirectUploadKey('downloads/chosen-by-client.pdf'));
        $this->assertFalse(DirectUpload::isDirectUploadKey("downloads/0123456789abcdef0123456789abcdef.pdf\n"));
    }

    public function test_upload_content_types_accept_documents_archives_and_raster_images(): void
    {
        foreach (['application/pdf', 'application/zip', 'application/x-zip-compressed', 'text/csv', 'image/png', 'image/webp'] as $type) {
            $this->assertTrue(FileTypes::isAllowedContentType($type), $type);
        }
    }

    public function test_upload_content_types_reject_executable_markup_and_unknown_types(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/javascript', 'application/octet-stream', '', 'image/', "image/png\r\nx", 'constructor'] as $type) {
            $this->assertFalse(FileTypes::isAllowedContentType($type), $type);
        }
    }

    public function test_it_stores_the_type_implied_by_the_extension_never_the_declared_one(): void
    {
        $this->assertSame(['ext' => '.txt', 'contentType' => 'text/plain'], FileTypes::storedType('notes.txt', 'text/html'));
        $this->assertSame(['ext' => '', 'contentType' => 'application/octet-stream'], FileTypes::storedType('page.html', 'text/html'));
        $this->assertSame(['ext' => '.jpg', 'contentType' => 'image/jpeg'], FileTypes::storedType('photo', 'image/jpeg'));
        $this->assertSame(['ext' => '.webm', 'contentType' => 'video/webm'], FileTypes::storedType('a.webm', ''));
    }
}
