<?php

declare(strict_types=1);

namespace Baraja\ImageGenerator;


final class ImageGeneratorRequest
{
	public const
		CROP_SMART = 'crop_smart',
		CROP_RATIO = 'crop_ratio';

	public const
		SCALE_R = 'scale_ratio',
		SCALE_C = 'scale_crop',
		SCALE_A = 'scale_absolute';

	private ?int $width = null;

	private ?int $height = null;

	private bool $breakPoint = false;

	/**
	 * Scale strategy:
	 * "r" - ratio
	 * "c" - cover
	 * "a" - absolute
	 */
	private ?string $scale = null;

	/** Possible values: self::CROP_* */
	private ?string $crop = self::CROP_SMART;

	private ?int $px = null;

	private ?int $py = null;


	/**
	 * @param array{
	 *     width: int,
	 *     height: int,
	 *     breakPoint: bool,
	 *     scale: string,
	 *     crop: string,
	 *     px: int,
	 *     py: int
	 * } $params
	 */
	public function __construct(array $params = [])
	{
		if (isset($params['width'], $params['height'])) {
			$this->setWidth((int)$params['width']);
			$this->setHeight((int)$params['height']);
		} else {
			throw new \InvalidArgumentException('Width or height params are required.');
		}
		$this->breakPoint = $params['breakPoint'] ?? false;
		$this->scale = $params['scale'] ?? null;
		$this->crop = $params['crop'] ?? null;
		$this->px = $params['px'] ?? null;
		$this->py = $params['py'] ?? null;
	}


	/**
	 * @param string|array<string, string|int> $params
	 */
	public static function createFromParams(string|array $params): self
	{
		return is_string($params)
			? self::createFromStringParams($params)
			: new self($params);
	}


	public static function createFromStringParams(string $params): self
	{
		preg_match('/^w(\d+)/i', $params, $w);
		preg_match('/^(w\d+)?h(\d+)/i', $params, $h);

		$return = [];
		$return['width'] = (isset($w[1]) && $w[1]) ? (int)$w[1] : null;
		$return['height'] = (isset($h[2]) && $h[2]) ? (int)$h[2] : null;
		$return['breakPoint'] = str_contains($params, '-br');
		if (preg_match('/-sc([rca])/i', $params, $sc)) {
			$return['scale'] = (isset($sc[1]) && $sc[1]) ? $sc[1] : null;
		}
		if (preg_match('/-c([a-z]{2,5})/', $params, $c)) {
			$return['crop'] = (isset($c[1]) && $c[1]) ? $c[1] : null;
		}
		if (preg_match('/-px(\d+)/i', $params, $px)) {
			$return['px'] = (isset($px[1]) && $px[1]) ? (int)$px[1] : null;
		}
		if (preg_match('/-py(\d+)/i', $params, $py)) {
			$return['py'] = (isset($py[1]) && $py[1]) ? (int)$py[1] : null;
		}

		return new self($return);
	}


	private function setWidth(int $width): void
	{
		if ($width < 16) {
			trigger_error('Minimal mandatory width is 16px, but "' . $width . '" given.');
			$width = 16;
		}
		if ($width > 3000) {
			trigger_error('Image is so large. Maximal width is 3000px, but "' . $width . '" given.');
		}
		$this->width = $width;
	}


	private function setHeight(int $height): void
	{
		if ($height < 16) {
			trigger_error('Minimal mandatory height is 16px, but "' . $height . '" given.');
			$height = 16;
		}
		if ($height > 3000) {
			trigger_error('Image is so large. Maximal height is 3000px, but "' . $height . '" given.');
		}
		$this->height = $height;
	}


	public function getWidth(): int
	{
		return $this->width ?? 64;
	}


	public function getHeight(): int
	{
		return $this->height ?? 64;
	}


	public function isBreakPoint(): bool
	{
		return $this->breakPoint;
	}


	public function getScale(): ?string
	{
		return $this->scale;
	}


	public function getCrop(): ?string
	{
		return $this->crop;
	}


	public function getPx(): ?int
	{
		return $this->px;
	}


	public function getPy(): ?int
	{
		return $this->py;
	}
}
