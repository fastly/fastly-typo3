<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

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
        $this->parameter['precrop'] = "{$width},{$height},x{$offsetX},y{$offsetY}";
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
        $path = parse_url($this->sourceUrl, PHP_URL_PATH) ?? '';
        $params = $this->parameter;

        if (!isset($params['format'])) {
            $params['format'] = 'auto';
        }

        if ($this->resizeType !== null) {
            $params['fit'] = 'crop';
        } elseif ($this->upscaling) {
            $params['fit'] = 'bounds';
            $params['enable'] = 'upscale';
        } else {
            $params['fit'] = 'bounds';
        }

        return $path . '?' . http_build_query($params);
    }
}
