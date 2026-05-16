<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Service\UrlBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UrlBuilderTest extends UnitTestCase
{
    private function builder(): UrlBuilder
    {
        return new UrlBuilder('https://cdn.example.com');
    }

    public function testGenerateDefaultsToFitBoundsAndFormatAuto(): void
    {
        $url = $this->builder()->setSourceUrl('/images/photo.jpg')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('bounds', $params['fit']);
        self::assertSame('auto', $params['format']);
        self::assertArrayNotHasKey('enable', $params);
    }

    public function testGenerateWithResizeTypeSetsFitCrop(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setResizeType('force')
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('crop', $params['fit']);
        self::assertArrayNotHasKey('enable', $params);
    }

    public function testGenerateWithAllowUpscalingSetsBoundsAndEnableUpscale(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->allowUpscaling()
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('bounds', $params['fit']);
        self::assertSame('upscale', $params['enable']);
    }

    public function testSetWidthAndHeightAppearInQuery(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setWidth(200)
            ->setHeight(100)
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('200', $params['width']);
        self::assertSame('100', $params['height']);
    }

    public function testSetCropEncodesCorrectPrecropString(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setCrop(400.0, 300.0, 10.0, 20.0)
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('400,300,x10,y20', $params['precrop']);
    }

    public function testSetQualityWithInteger(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setQuality(85)->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('85', $params['quality']);
    }

    public function testSetQualityWithString(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setQuality('85,75')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('85,75', $params['quality']);
    }

    public function testSetFormatOverridesAutoDefault(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setFormat('webp')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('webp', $params['format']);
    }

    public function testSetCacheBusterSetsCbParam(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setCacheBuster('abc123')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('abc123', $params['cb']);
    }

    public function testEnableAutoWebpSetsAutoParam(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->enableAutoWebp()->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame('webp', $params['auto']);
    }

    public function testSourceUrlPathIsExtractedFromFullUrl(): void
    {
        $url = $this->builder()
            ->setSourceUrl('https://cdn.example.com/images/foo.jpg')
            ->generate();

        self::assertStringStartsWith('/images/foo.jpg?', $url);
    }

    public function testFluentInterfaceReturnsSameInstance(): void
    {
        $builder = $this->builder();

        self::assertSame($builder, $builder->setSourceUrl('/img.jpg'));
        self::assertSame($builder, $builder->setWidth(100));
        self::assertSame($builder, $builder->setHeight(100));
        self::assertSame($builder, $builder->setQuality(80));
        self::assertSame($builder, $builder->setFormat('jpeg'));
        self::assertSame($builder, $builder->setCacheBuster('x'));
        self::assertSame($builder, $builder->allowUpscaling());
        self::assertSame($builder, $builder->enableAutoWebp());
        self::assertSame($builder, $builder->setResizeType('crop'));
        self::assertSame($builder, $builder->setCrop(100.0, 100.0, 0.0, 0.0));
    }

    public function testGeneratePathOnlyFromRelativeSourceUrl(): void
    {
        $url = $this->builder()->setSourceUrl('/fileadmin/img.jpg')->generate();

        self::assertStringStartsWith('/fileadmin/img.jpg?', $url);
    }
}
