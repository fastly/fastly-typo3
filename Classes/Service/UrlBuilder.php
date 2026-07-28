<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use TYPO3\CMS\Core\Http\Uri;

final class UrlBuilder
{
    protected string $sourceUrl = '';

    protected array $parameter = [];

    protected bool $upscaling = false;

    protected ?string $resizeType = null;

    public function __construct(
        protected readonly string $assetUrl
    ) {
    }

    public function setSourceUrl(string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    public function setWidth(int $width): self
    {
        $this->parameter['width'] = $width;
        return $this;
    }

    public function setHeight(int $height): self
    {
        $this->parameter['height'] = $height;
        return $this;
    }

    /**
     * Crops the source image to the given pixel area before resizing.
     * Uses Fastly IO precrop so the selection happens before width/height resize.
     */
    public function setCrop(float $width, float $height, float $offsetX, float $offsetY): self
    {
        $this->parameter['precrop'] = sprintf('%s,%s,x%s,y%s', $width, $height, $offsetX, $offsetY);
        return $this;
    }

    public function setQuality(string|int $quality): self
    {
        $this->parameter['quality'] = $quality;
        return $this;
    }

    public function setFormat(string $format): self
    {
        $this->parameter['format'] = $format;
        return $this;
    }

    public function setCacheBuster(string $cacheBuster): self
    {
        $this->parameter['cb'] = $cacheBuster;
        return $this;
    }

    public function allowUpscaling(): self
    {
        $this->upscaling = true;
        return $this;
    }

    /**
     * Enables automatic WebP conversion.
     * https://www.fastly.com/documentation/reference/io/auto/
     */
    public function enableAutoWebp(): self
    {
        $this->parameter['auto'] = 'webp';
        return $this;
    }

    public function setResizeType(string $resizeType): self
    {
        $this->resizeType = $resizeType;
        return $this;
    }

    public function generate(): string
    {
        $uri = new Uri($this->sourceUrl);
        $assetHost = new Uri($this->assetUrl)->getHost();
        if ($assetHost !== '' && $assetHost !== '0') {
            $uri = $uri->withHost($assetHost);
        }

        $params = $this->parameter;

        if ($this->resizeType !== null) {
            $params['fit'] = 'crop';
        } elseif ($this->upscaling) {
            $params['fit'] = 'bounds';
            $params['enable'] = 'upscale';
        } else {
            $params['fit'] = 'bounds';
        }

        $uri = $uri->withQuery(http_build_query($params));
        return (string) $uri;
    }
}
