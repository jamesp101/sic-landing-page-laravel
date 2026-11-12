<?php

namespace Tests\Unit\Storage;

use App\Storage\ImageFile;
use App\Storage\ImageUpload;
use App\Support\RequestError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ImageFileTest extends TestCase
{
    use UsesUploadsDisk;

    private function validate(mixed $value, int $max = 1024, array $before = ['required']): \Illuminate\Validation\Validator
    {
        return Validator::make(['image' => $value], ['image' => [...$before, ...ImageFile::imageFile($max, 'content')]], ['image.required' => ImageFile::MISSING]);
    }

    public function test_it_only_checks_type_and_size_undecodable_bytes_with_an_image_type_pass_until_they_are_stored(): void
    {
        $fake = $this->upload('not really an image', 'x.png', 'image/png');
        $this->assertTrue($this->validate($fake)->passes());

        $upload = ImageFile::upload($fake, 'content');
        $this->assertInstanceOf(ImageUpload::class, $upload);
        try {
            ImageFile::storedImage($upload);
            $this->fail('must be rejected');
        } catch (RequestError $error) {
            $this->assertSame(RequestError::BAD_REQUEST, $error->errorCode);
            $this->assertSame('The image could not be read. Choose another file.', $error->getMessage());
        }
    }

    public function test_it_rejects_a_wrong_declared_type_and_an_oversized_file(): void
    {
        $wrongType = $this->validate($this->upload('x', 'a.svg', 'image/svg+xml'), 10);
        $this->assertSame(ImageFile::BAD_TYPE, $wrongType->errors()->first('image'));
        $big = $this->validate($this->upload($this->imageBytes(8, 8), 'a.png', 'image/png'), 10);
        $this->assertMatchesRegularExpression('/must not be larger/', $big->errors()->first('image'));
        $this->assertSame('The image must not be larger than 5 MB.', ImageFile::tooLarge(5 * 1024 * 1024));
    }

    public function test_a_missing_value_gets_the_choose_message_and_nullable_passes(): void
    {
        $this->assertSame('Choose an image file.', $this->validate(null)->errors()->first('image'));
        $this->assertSame('Choose an image file.', $this->validate('text')->errors()->first('image'));
        $this->assertTrue($this->validate(null, 1024, ['nullable'])->passes());
    }

    public function test_it_converts_an_image_upload_to_webp_inside_its_target(): void
    {
        $upload = ImageFile::upload($this->upload($this->imageBytes(8, 8), 'Photo.PNG', 'image/png'), 'logo');
        $out = ImageFile::storedImage($upload);
        $this->assertSame(['image/webp', 'Photo.webp'], [$out->getClientMimeType(), $out->getClientOriginalName()]);
    }

    public function test_it_passes_any_other_file_through_untouched(): void
    {
        $file = $this->upload('pdf', 'a.pdf', 'application/pdf');
        $this->assertSame($file, ImageFile::storedImage($file));
        $this->assertInstanceOf(UploadedFile::class, $file);
    }

    public function test_it_reports_a_disallowed_real_format_as_a_request_error(): void
    {
        $svg = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>', 'a.png', 'image/png');
        $this->expectException(RequestError::class);
        ImageFile::storedImage(ImageFile::upload($svg, 'content'));
    }
}
