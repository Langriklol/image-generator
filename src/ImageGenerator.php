<?php

declare(strict_types=1);

namespace Baraja\ImageGenerator;


use Baraja\ImageGenerator\Entity\MaxSizeForCropEntity;
use Baraja\ImageGenerator\Optimizer\DefaultOptimizer;
use Baraja\ImageGenerator\Optimizer\Optimizer;
use Baraja\Url\Url;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\ImageException;

final class ImageGenerator
{
	private ImageGeneratorRequest $request;

	private Optimizer $optimizer;

	private SmartCrop $smartCrop;

	private string $targetPath;


	public function __construct(
		private Config $config,
		?Optimizer $optimizer = null,
	) {
		$this->optimizer = $optimizer ?? new DefaultOptimizer;
		$this->smartCrop = new SmartCrop($this);
	}


	/**
	 * Fill in the URL address of the image (it can also be relative) with parameters
	 * so that the path is valid for ImageGenerator.
	 *
	 * @param string[]|int[] $params
	 */
	public static function from(?string $url, array $params): string
	{
		if ($url === null || $url === '#INVALID_IMAGE#') {
			$url = Url::get()->getBaseUrl() . '/placeholder.png';
		} elseif (preg_match(
			'/^(?<prefix>.*\/)(?<filename>.+?)(__[^_]*?_[a-z0-9]{6})(?<suffix>\.[^.]+)$/',
			$url,
			$parser
		)) {
			$url = $parser['prefix'] . $parser['filename'] . $parser['suffix'];
		}
		if (preg_match('/(?<prefix>.*\/)?(?<filename>[\w._-]+)\.(?<suffix>.+)$/', $url, $parser)) {
			$param = Helper::paramsToString($params);
			return ($parser['prefix'] ?? '') . $parser['filename']
				. ($param !== '' ? '__' . $param . '_' . Helper::generateHash($param) : '')
				. '.' . $parser['suffix'];
		}

		throw new \InvalidArgumentException('Invalid URL "' . $url . '" given.');
	}


	public function generate(ImageGeneratorRequest $request, string $sourceFile, string $targetFile): void
	{
		@ini_set('memory_limit', '256M');

		$this->request = $request;
		$this->targetPath = $targetFile;

		if (is_file($sourceFile) === false) {
			throw new \InvalidArgumentException('Source file does not exist "' . $sourceFile . '".');
		}
		if (is_file($targetFile) === true) {
			throw new \InvalidArgumentException('Target file exist "' . $targetFile . '".');
		}
		if ($this->isOk($sourceFile) === false) {
			ImageGeneratorRoute::renderPlaceholder('w' . $request->getWidth() . 'h' . $request->getHeight());
		}

		$this->copySourceFileToTemp(
			$sourceFile,
			$tempFile = (string) preg_replace('/(.+?)(\.\w+)$/', '$1_temp$2', $targetFile)
		);

		if ($this->request->isBreakPoint()) {
			$this->cropByBreakPoint($tempFile, $this->request->getWidth());
		} elseif ($this->request->getScale() !== null) {
			$this->scale(
				$tempFile,
				$this->request->getScale(),
				[
					$this->request->getWidth(),
					$this->request->getHeight(),
				],
			);
		} elseif ($this->request->getCrop()) {
			if ($this->request->getCrop() === ImageGeneratorRequest::CROP_SMART) {
				$this->cropSmart($tempFile, $this->request->getWidth(), $this->request->getHeight());
			} else {
				$this->cropNette(
					$tempFile,
					(string) $this->request->getCrop(),
					[
						$this->request->getWidth(),
						$this->request->getHeight(),
					],
				);
			}
		} elseif ($this->request->getPx() || $this->request->getPy()) {
			$this->percentagesShift(
				$tempFile,
				[
					'px' => (int) $this->request->getPx(),
					'py' => (int) $this->request->getPy(),
				],
				[
					$this->request->getWidth(),
					$this->request->getHeight(),
				],
			);
		} else {
			$this->cropSmart($tempFile, $this->request->getWidth(), $this->request->getHeight());
		}

		$this->optimizer->optimize(
			$tempFile,
			$this->request->getWidth() * $this->request->getHeight() > 479999 ? 85 : 95,
		);

		if ($this->isOk($tempFile)) {
			FileSystem::rename($tempFile, $targetFile);
		} else {
			@unlink($tempFile);
			ImageGeneratorRoute::renderPlaceholder(
				'w' . $request->getWidth()
				. 'h' . $request->getHeight(),
			);
		}
	}


	public function isOk(string $path, ?string $format = null): bool
	{
		if ($format === null) {
			$formatMap = [
				'image/gif' => 'gif',
				'image/png' => 'png',
				'image/jpeg' => 'jpg',
			];

			/** @var resource $fInfo */
			$fInfo = finfo_open(FILEINFO_MIME_TYPE);
			$contentType = (string) finfo_file($fInfo, $path);
			if (isset($formatMap[$contentType]) === false) {
				return false;
			}
			$format = $formatMap[$contentType];
		}
		$formatToFunction = [
			'png' => 'imagecreatefrompng',
			'jpg' => 'imagecreatefromjpeg',
			'jpeg' => 'imagecreatefromjpeg',
			'gif' => 'imagecreatefromgif',
		];

		$format = strtolower($format);
		if (isset($formatToFunction[$format]) === false) {
			throw new \InvalidArgumentException(
				'Format "' . $format . '" is not supported. Did you mean "'
				. implode('", "', array_keys($formatToFunction))
				. '"?',
			);
		}
		if (Helper::functionIsAvailable($function = $formatToFunction[$format]) === false) {
			throw new \RuntimeException('Function "' . $function . '" is not available now.');
		}

		return (bool) @$function($path);
	}


	/**
	 * @param array<int, int> $size
	 */
	public function cropNette(string $path, string $crop, array $size): void
	{
		[$width, $height] = $size;

		$this->cropByCorner(
			$image = $this->loadNetteImage($path),
			$crop,
			[$image->getWidth(), $image->getHeight()],
			[$width, $height]
		);
		$this->saveNetteImage($path, $image);
	}


	private function copySourceFileToTemp(string $sourceFile, string $tempFile): void
	{
		FileSystem::copy($sourceFile, $tempFile);
		clearstatcache();
	}


	/**
	 * +----------------> X <----------------+
	 * |  [A_x, A_y]                         |
	 * ˘    * =================== \          ˘
	 * Y    |                     |          Y
	 * ^    \ =================== *          ^
	 * |                         [B_x, B_y]  |
	 * +----------------> X <----------------+
	 */
	private function cropByBreakPoint(string $absolutePath, int $width): void
	{
		$breakPoint = null;
		$breakpoints = [0];
		$cropPoints = $this->config->getCropPoints();
		foreach ($cropPoints as $cropPoint => $values) {
			$breakpoints[] = $cropPoint;
		}
		sort($breakpoints);

		for ($i = 0; isset($breakpoints[$i]); $i++) {
			$beforeBreakpoint = $breakpoints[$i];
			$afterBreakpoint = $breakpoints[$i + 1] ?? INF;

			if (
				($width >= $beforeBreakpoint && $width < $afterBreakpoint)
				|| $afterBreakpoint === INF
			) {
				$breakPoint = ($afterBreakpoint !== INF ? $afterBreakpoint : $beforeBreakpoint);
				break;
			}
		}

		if ($breakPoint === null) {
			throw new \InvalidArgumentException(
				'Undefined breakpoint. '
				. 'Possible values: "' . implode(', ', $breakpoints) . '". Did you registered some points?',
			);
		}

		[$aX, $aY] = $cropPoints[$breakPoint];

		$bX = abs($cropPoints[$breakPoint][2] - $cropPoints[$breakPoint][0]);
		$bY = abs($cropPoints[$breakPoint][3] - $cropPoints[$breakPoint][1]);

		/**
		 * @var int $aX
		 * @var int $aY
		 * @var int $bX
		 * @var int $bY
		 */
		$this->saveNetteImage($absolutePath, $this->loadNetteImage($absolutePath)->crop($aX, $aY, $bX, $bY));
	}


	private function loadNetteImage(string $path): Image
	{
		try {
			return Image::fromFile($path ?: $this->targetPath);
		} catch (\Throwable $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
	}


	private function saveNetteImage(string $path, Image $image): void
	{
		try {
			$image->save($path ?: $this->targetPath);
		} catch (ImageException $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
	}


	/**
	 * @param array{0: int|null, 1: int|null} $size
	 */
	private function scale(string $absolutePath, string $scale, array $size): void
	{
		[$width, $height] = $size;

		if ($scale === ImageGeneratorRequest::SCALE_RATIO) {
			/** @var array{0: int, 1: int} $imageSize */
			$imageSize = getimagesize($absolutePath);
			if ($width === null) {
				$needleRatio = $imageSize[0] / $imageSize[1];
				$width = (int) ($needleRatio * $height);
			}
			if ($height === null) {
				$needleRatio = $imageSize[1] / $imageSize[0];
				$height = (int) ($needleRatio * $width);
			}

			if (
				($width / $imageSize[0] < 1.3 && $height / $imageSize[1] < 1.3)
				&& (
					($width === $imageSize[0] && $height >= $imageSize[1])
					|| ($height === $imageSize[1] && $width >= $imageSize[0])
				) === false
			) {
				$this->saveNetteImage(
					$absolutePath,
					$this->loadNetteImage($absolutePath)
						->resize($width, $height),
				);
			}
		} elseif ($scale === ImageGeneratorRequest::SCALE_COVER) {
			$this->saveNetteImage(
				$absolutePath,
				$this->loadNetteImage($absolutePath)
					->resize($width, $height, Image::EXACT),
			);
		} elseif ($scale === ImageGeneratorRequest::SCALE_ABSOLUTE) {
			$this->saveNetteImage(
				$absolutePath,
				$this->loadNetteImage($absolutePath)
					->resize(
						$width,
						$height,
						Image::SHRINK_ONLY | Image::STRETCH
					),
			);
		}
	}


	private function cropSmart(string $path, ?int $width, ?int $height): void
	{
		$image = $this->loadNetteImage($path);
		$this->smartCrop->crop($path, $width, $height, $image);
	}


	/**
	 * @param array<int, int> $original
	 * @param array<int, int> $needle
	 */
	private function cropByCorner(Image $image, string $corner, array $original, array $needle): void
	{
		$corner = strtolower($corner);
		if (preg_match('/^([tmb])([lcr])$/', $corner, $cornerParser)) {
			$leftCrop = $cornerParser[0] ?? 'm';
			$topCrop = $cornerParser[1] ?? 'c';
		} else {
			throw new \InvalidArgumentException('Corner "' . $corner . '" is not in valid format.');
		}

		[$originalWidth, $originalHeight] = $original;
		[$needleWidth, $needleHeight] = $needle;

		$resize = $this->getMaxSizeForCrop(
			[$originalWidth, $originalHeight],
			[$needleWidth, $needleHeight],
		);

		if (
			$needleWidth <= $originalWidth
			&& $needleHeight <= $originalHeight
		) {
			if ($leftCrop === 'm') {
				$top = (int) round(($originalHeight - $resize->getNeedleHeight()) / 2);
			} elseif ($leftCrop === 'b') {
				$top = (int) round($originalHeight - $resize->getNeedleHeight());
			} else {
				$top = 0;
			}

			if ($topCrop === 'c') {
				$left = (int) round(($originalWidth - $resize->getNeedleWidth()) / 2);
			} elseif ($topCrop === 'r') {
				$left = (int) round($originalWidth - $resize->getNeedleWidth());
			} else {
				$left = 0;
			}

			$image->crop($left, $top, $resize->getNeedleWidth(), $resize->getNeedleHeight())
				->resize(
					$needleWidth,
					$needleHeight,
				);
		}
	}


	/**
	 * Find best scale ratio of sizes for crop
	 *
	 * @param array<int, int|null> $original
	 * @param array<int, int|null> $needle
	 */
	private function getMaxSizeForCrop(array $original, array $needle): MaxSizeForCropEntity
	{
		[$originalWidth, $originalHeight] = $original;
		[$needleWidth, $needleHeight] = $needle;

		$needleWidthIsGreater = $needleWidth > $needleHeight;
		if ($needleWidth === null || $needleHeight === null) {
			if ($needleWidth === null) {
				$needleRatio = $originalWidth / $originalHeight;
				$needleWidth = (int) ($needleRatio * $needleHeight);
			} else {
				$needleRatio = $originalHeight / $originalWidth;
				$needleHeight = (int) ($needleRatio * $needleWidth);
			}
		} else {
			$needleRatio = !$needleWidthIsGreater
				? $needleHeight / $needleWidth
				: $needleWidth / $needleHeight;

			while ($needleWidth < $originalWidth && $needleHeight < $originalHeight) {
				if ($needleWidthIsGreater) {
					$needleWidth += $needleRatio;
					$needleHeight++;
				} else {
					$needleHeight += $needleRatio;
					$needleWidth++;
				}
			}
		}
		if ($needleWidth > $originalWidth) {
			$needleWidth -= ($needleWidth - $originalWidth);
		}
		if ($needleHeight > $originalHeight) {
			$needleHeight -= ($needleHeight - $originalHeight);
		}

		return new MaxSizeForCropEntity(
			(int) $needleWidth,
			(int) $needleHeight,
			(float) $needleRatio,
		);
	}


	/**
	 * @param int[] $xy
	 * @param array<int, int> $needle
	 */
	private function percentagesShift(string $absolutePath, array $xy, array $needle): void
	{
		[$needleWidth, $needleHeight] = $needle;

		$this->saveNetteImage(
			$absolutePath,
			$this->loadNetteImage($absolutePath)
				->resize($needleWidth, $needleHeight, Image::FILL),
		);
		$image = $this->loadNetteImage($absolutePath);

		if ($this->request->getPx() !== null || $this->request->getPy() !== null) {
			$xy['px'] /= 100;
			$xy['py'] /= 100;

			$originalHeight = $image->getHeight();
			$originalWidth = $image->getWidth();
			if ($originalHeight * 2 < $originalWidth) {
				$left = (int) round(
					(($needleWidth * $originalHeight - $originalWidth * $needleHeight) / $needleHeight)
					* $xy['px'],
				);
				$top = 0;
			} else {
				$top = (int) round(
					(($originalWidth * $needleHeight - $needleWidth * $originalHeight) / $needleWidth)
					* $xy['py'],
				);
				$left = 0;
			}

			$image->crop($left, $top, $needleWidth, $needleHeight);
			$this->saveNetteImage($absolutePath, $image);
		}
	}
}
