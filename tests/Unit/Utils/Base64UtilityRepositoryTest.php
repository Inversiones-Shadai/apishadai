<?php

namespace Tests\Unit\Utils;

use App\Utils\Repositories\Base64UtilityRepository;
use PHPUnit\Framework\TestCase;

class Base64UtilityRepositoryTest extends TestCase
{
    public function test_it_reencodes_png_and_removes_appended_content(): void
    {
        $image = imagecreatetruecolor(12, 12);
        imagefilledrectangle($image, 0, 0, 11, 11, imagecolorallocate($image, 25, 155, 90));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        unset($image);

        $this->assertIsString($png);
        $result = (new Base64UtilityRepository)->sanitize(
            base64_encode($png.'<?php echo "unsafe";'),
        );

        $this->assertIsString($result);
        $sanitized = base64_decode($result, true);
        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString('<?php', $sanitized);
        $this->assertSame('image/png', getimagesizefromstring($sanitized)['mime']);
    }

    public function test_it_accepts_only_jpeg_and_png(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);

        $this->assertIsString($gif);
        $this->assertNull((new Base64UtilityRepository)->sanitize(base64_encode($gif)));
        $this->assertNull((new Base64UtilityRepository)->sanitize(base64_encode('not-an-image')));
    }

    public function test_it_accepts_images_between_one_and_two_megabytes(): void
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        unset($image);

        $this->assertIsString($png);
        $this->assertIsString((new Base64UtilityRepository)->sanitize(
            base64_encode($png.str_repeat('A', 1_200_000)),
        ));
    }

    public function test_it_rejects_decoded_payloads_larger_than_two_megabytes(): void
    {
        $payload = base64_encode(str_repeat('A', 2_097_153));

        $this->assertNull((new Base64UtilityRepository)->sanitize($payload));
    }

    public function test_it_validates_a_clean_image_without_recompressing_it(): void
    {
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($image, null, 9, PNG_ALL_FILTERS);
        $png = ob_get_clean();
        unset($image);

        $this->assertIsString($png);
        $base64 = base64_encode($png);
        $repository = new Base64UtilityRepository;

        $this->assertSame($base64, $repository->validate($base64));
        $this->assertNull($repository->validate(base64_encode($png.'extra')));
    }
}
