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

    public function testGenerateDefaultsToFitBounds(): void
    {
        $url = $this->builder()->setSourceUrl('/images/photo.jpg')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('bounds', $params['fit']);
        $this->assertArrayNotHasKey('format', $params);
        $this->assertArrayNotHasKey('enable', $params);
    }

    public function testGenerateWithResizeTypeSetsFitCrop(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setResizeType('force')
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('crop', $params['fit']);
        $this->assertArrayNotHasKey('enable', $params);
    }

    public function testGenerateWithAllowUpscalingSetsBoundsAndEnableUpscale(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->allowUpscaling()
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('bounds', $params['fit']);
        $this->assertSame('upscale', $params['enable']);
    }

    public function testSetWidthAndHeightAppearInQuery(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setWidth(200)
            ->setHeight(100)
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('200', $params['width']);
        $this->assertSame('100', $params['height']);
    }

    public function testSetCropEncodesCorrectPrecropString(): void
    {
        $url = $this->builder()
            ->setSourceUrl('/img.jpg')
            ->setCrop(400.0, 300.0, 10.0, 20.0)
            ->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('400,300,x10,y20', $params['precrop']);
    }

    public function testSetQualityWithInteger(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setQuality(85)->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('85', $params['quality']);
    }

    public function testSetQualityWithString(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setQuality('85,75')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('85,75', $params['quality']);
    }

    public function testSetFormatOverridesAutoDefault(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setFormat('webp')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('webp', $params['format']);
    }

    public function testSetCacheBusterSetsCbParam(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->setCacheBuster('abc123')->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('abc123', $params['cb']);
    }

    public function testEnableAutoWebpSetsAutoParam(): void
    {
        $url = $this->builder()->setSourceUrl('/img.jpg')->enableAutoWebp()->generate();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertSame('webp', $params['auto']);
    }

    public function testSourceUrlHostIsRewrittenToAssetHost(): void
    {
        $url = $this->builder()
            ->setSourceUrl('https://cdn.example.com/images/foo.jpg')
            ->generate();

        $this->assertStringStartsWith('https://cdn.example.com/images/foo.jpg?', $url);
    }

    public function testFluentInterfaceReturnsSameInstance(): void
    {
        $builder = $this->builder();

        $this->assertSame($builder, $builder->setSourceUrl('/img.jpg'));
        $this->assertSame($builder, $builder->setWidth(100));
        $this->assertSame($builder, $builder->setHeight(100));
        $this->assertSame($builder, $builder->setQuality(80));
        $this->assertSame($builder, $builder->setFormat('jpeg'));
        $this->assertSame($builder, $builder->setCacheBuster('x'));
        $this->assertSame($builder, $builder->allowUpscaling());
        $this->assertSame($builder, $builder->enableAutoWebp());
        $this->assertSame($builder, $builder->setResizeType('crop'));
        $this->assertSame($builder, $builder->setCrop(100.0, 100.0, 0.0, 0.0));
    }

    public function testRelativeSourceUrlGetsAssetHostPrepended(): void
    {
        $url = $this->builder()->setSourceUrl('/fileadmin/img.jpg')->generate();

        $this->assertStringStartsWith('//cdn.example.com/fileadmin/img.jpg?', $url);
    }
}
