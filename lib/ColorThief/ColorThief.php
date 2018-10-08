<?php

/*
 * Color Thief PHP
 *
 * Grabs the dominant color or a representative color palette from an image.
 *
 * This class requires the GD library to be installed on the server.
 *
 * It's a PHP port of the Color Thief Javascript library
 * (http://github.com/lokesh/color-thief), using the MMCQ
 * (modified median cut quantization) algorithm from
 * the Leptonica library (http://www.leptonica.com/).
 *
 * by Kevin Subileau - http://www.kevinsubileau.fr
 * Based on the work done by Lokesh Dhakar - http://www.lokeshdhakar.com
 * and Nick Rabinowitz
 *
 * License
 * -------
 * Creative Commons Attribution 2.5 License:
 * http://creativecommons.org/licenses/by/2.5/
 *
 * Thanks
 * ------
 * Lokesh Dhakar - For creating the original project.
 * Nick Rabinowitz - For creating quantize.js.
 *
 */

namespace ColorThief;

use SplFixedArray;
use ColorThief\Image\ImageLoader;

class ColorThief
{
    const SIGBITS = 5;
    const RSHIFT = 3;
    const MAX_ITERATIONS = 1000;
    const FRACT_BY_POPULATIONS = 0.75;
    const THRESHOLD_ALPHA = 62;
    const THRESHOLD_WHITE = 250;

    /**
      * Return additional palette properties such as VBox count, VBox volume,
      * and color prevalence (Vbox->count/totalNonWhitePixels)
      *
      * @var returnPaletteMetrics
      */
    private static $returnPaletteMetrics = false;

    /**
     * Get reduced-space color index for a pixel by zeroing non-significant bits and
     * combining red, green, and blue integers into one by bit-shifting.
     */
    public static function getColorIndex(int $red, int $green, int $blue, int $sigBits = self::SIGBITS): int
    {
        $mask = 255 ^ ((1 << (8-$sigBits)) - 1);

        return (($red & $mask) << 16) + (($green & $mask) << 8) + ($blue & $mask);
    }

    /**
     * Get red, green and blue components from reduced-space color index for a pixel.
     */
    public static function getColorsFromIndex(int $index): array
    {
        return [
            ($index >> 16) & 255,
            ($index >> 8) & 255,
            $index & 255
        ];
    }

    public static function setReturnPaletteMetrics(bool $returnPaletteMetrics): void
    {
        self::$returnPaletteMetrics = $returnPaletteMetrics;
    }

    /**
     * Use the median cut algorithm to cluster similar colors.
     *
     * @bug Function does not always return the requested amount of colors. It can be +/- 2.
     *
     * @param mixed      $sourceImage   Path/URL to the image, GD resource, Imagick instance, or image as binary string
     * @param int        $quality       1 is the highest quality. There is a trade-off between quality and speed.
     *                                  The bigger the number, the faster the palette generation but the greater the
     *                                  likelihood that colors will be missed.
     * @param array|null $area[x,y,w,h] It allows you to specify a rectangular area in the image in order to get
     *                                  colors only for this area. It needs to be an associative array with the
     *                                  following keys:
     *                                  $area['x']: The x-coordinate of the top left corner of the area. Default to 0.
     *                                  $area['y']: The y-coordinate of the top left corner of the area. Default to 0.
     *                                  $area['w']: The width of the area. Default to image width minus x-coordinate.
     *                                  $area['h']: The height of the area. Default to image height minus y-coordinate.
     *
     * @return array|bool
     */
    public static function getColor($sourceImage, int $quality = 10, ?array $area = null, ?callable $filterFunction = null)
    {
        $palette = static::getPalette($sourceImage, 5, $quality, $area);

        return $palette ? $palette[0] : false;
    }

    /**
     * Use the median cut algorithm to cluster similar colors.
     *
     * @bug Function does not always return the requested amount of colors. It can be +/- 2.
     *
     * @param mixed      $sourceImage     Path/URL to the image, GD resource, Imagick instance, or image as binary string
     * @param int        $colorCount      it determines the size of the palette; the number of colors returned
     * @param int        $quality         1 is the highest quality
     * @param array|null $area[x,y,w,h]
     * @param callable   $filterFunction  An array_filter compatible function to be used to filter the palette
     *
     * @return array
     */
    public static function getPalette($sourceImage, int $colorCount = 10, int $quality = 10, ?array $area = null, ?callable $filterFunction = null): array
    {
        if ($colorCount < 2 || $colorCount > 256) {
            throw new \InvalidArgumentException('The number of palette colors must be between 2 and 256 inclusive.');
        }

        if ($quality < 1) {
            throw new \InvalidArgumentException('The quality argument must be an integer greater than one.');
        }

        $histo = [];
        $pixelArray = static::loadImage($sourceImage, $quality, $area, $histo);
        if ($pixelArray->getSize() === 0) {
            throw new \RuntimeException('Unable to compute the color palette of a blank or transparent image.', 1);
        }

        // Send array to quantize function which clusters values using median cut algorithm
        $palette = static::quantize($pixelArray, $colorCount, $histo)->palette(self::$returnPaletteMetrics, $pixelArray->getSize());

        if (null !== $filterFunction) {
            $fallbackColor = $palette[0];
            $palette = array_filter($palette, $filterFunction);
            if (count($palette) === 0) {
                // The filter removed all the palette colors, so use the first as a fallback
                $palette[] = $fallbackColor;
            }
        }

        return $palette;
    }

    /**
     * @param mixed      $sourceImage Path/URL to the image, GD resource, Imagick instance, or image as binary string
     * @param int        $quality
     * @param array|null $area
     * @param array      $histo Array to store the histogram in while loading pixels
     *
     * @return SplFixedArray
     */
    private static function loadImage($sourceImage, int $quality, ?array $area = null, array &$histo): SplFixedArray
    {
        $loader = new ImageLoader();
        $image = $loader->load($sourceImage);
        $startX = 0;
        $startY = 0;
        $width = $image->getWidth();
        $height = $image->getHeight();

        if ($area) {
            $startX = $area['x'] ?? 0;
            $startY = $area['y'] ?? 0;
            $width  = $area['w'] ?? $width - $startX;
            $height = $area['h'] ?? $height - $startY;

            if ((($startX + $width) > $image->getWidth()) || (($startY + $height) > $image->getHeight())) {
                throw new \InvalidArgumentException('Area is out of image bounds.');
            }
        }

        $pixelCount = $width * $height;

        // Store the RGB values in an array format suitable for quantize function
        // SplFixedArray is faster and more memory-efficient than normal PHP array.
        $pixelArray = new SplFixedArray((int) ceil($pixelCount / $quality));

        $size = 0;
        $histo = [];
        for ($i = 0; $i < $pixelCount; $i += $quality) {
            $x = $startX + ($i % $width);
            $y = (int) ($startY + $i / $width);
            $color = $image->getPixelColor($x, $y);

            if (
              // Is clearly visible
              ($color->alpha <= self::THRESHOLD_ALPHA)
              // Is Non-White
              && !($color->red > self::THRESHOLD_WHITE && $color->green > self::THRESHOLD_WHITE && $color->blue > self::THRESHOLD_WHITE)) {
                // Save all bits of the colors in pixelArray
                $pixelArray[$size++] = static::getColorIndex($color->red, $color->green, $color->blue, 8);

                // Compute the histogram while we load the pixels (saves one iteration over all pixels)
                // The index keeps only the self::SIGBITS most significant bits of each color
                $index = static::getColorIndex($color->red, $color->green, $color->blue);
                $histo[$index] = ($histo[$index] ?? 0) + 1;
            }
        }

        // Reset SplFixedArray size as pixels may be ignored due to transparency or being white
        $pixelArray->setSize($size);

        // Don't destroy a resource passed by the user !
        // TODO Add a method in ImageLoader to know if the image should be destroy
        // (or to know the detected image source type)
        if (is_string($sourceImage)) {
            $image->destroy();
        }

        return $pixelArray;
    }

    private static function vboxFromHistogram(array $histo): VBox
    {
        $rgbMin = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
        $rgbMax = [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN];

        // find min/max
        foreach ($histo as $index => $count) {
            $rgb = static::getColorsFromIndex($index);

            // For each color components
            for ($i = 0; $i < 3; $i++) {
                if ($rgb[$i] < $rgbMin[$i]) {
                    $rgbMin[$i] = $rgb[$i];
                } elseif ($rgb[$i] > $rgbMax[$i]) {
                    $rgbMax[$i] = $rgb[$i];
                }
            }
        }

        return new VBox($rgbMin[0], $rgbMax[0], $rgbMin[1], $rgbMax[1], $rgbMin[2], $rgbMax[2], $histo);
    }

    private static function doCut(string $color, VBox $vBox, array $partialSum, int $total): array
    {
        $dim1 = $color . '1';
        $dim2 = $color . '2';

        for ($i = $vBox->$dim1; $i <= $vBox->$dim2; $i++) {
            if ($partialSum[$i] > $total / 2) {
                $vBox1 = $vBox->copy();
                $vBox2 = $vBox->copy();
                $left = $i - $vBox->$dim1;
                $right = $vBox->$dim2 - $i;

                // Choose the cut plane within the greater of the (left, right) sides
                // of the bin in which the median pixel resides
                if ($left <= $right) {
                    $d2 = min($vBox->$dim2 - 1, (int) ($i + $right / 2));
                } else { /* left > right */
                    $d2 = max($vBox->$dim1, (int) ($i - 1 - $left / 2));
                }

                while (empty($partialSum[$d2])) {
                    $d2++;
                }
                // Avoid 0-count boxes
                while ($partialSum[$d2] >= $total && !empty($partialSum[$d2 - 1])) {
                    $d2--;
                }

                // set dimensions
                $vBox1->$dim2 = $d2;
                $vBox2->$dim1 = $d2 + 1;

                return [$vBox1, $vBox2];
            }
        }
    }

    /**
     * @return array|void
     */
    private static function medianCutApply(array $histo, VBox $vBox)
    {
        if (!$vBox->count()) {
            return;
        }

        // If the vbox occupies just one element in color space, it can't be split
        if ($vBox->count() == 1) {
            return [
                $vBox->copy(),
            ];
        }

        // Select the longest axis for splitting
        $cutColor = $vBox->longestAxis();

        // Find the partial sum arrays along the selected axis.
        list($total, $partialSum) = static::sumColors($cutColor, $histo, $vBox);

        return static::doCut($cutColor, $vBox, $partialSum, $total);
    }

    /**
     * Find the partial sum arrays along the selected axis.
     */
    private static function sumColors(string $axis, array $histo, VBox $vBox): array
    {
        $colorIterateOrders = [
            'r' => ['r','g','b'],
            'g' => ['g','r','b'],
            'b' => ['b','r','g'],
        ];
        $colorRangeOrders = [
            'r' => ['firstColor','secondColor','thirdColor'],
            'g' => ['secondColor','firstColor','thirdColor'],
            'b' => ['secondColor','thirdColor','firstColor']
        ];

        $total = 0;
        $partialSum = [];

        // The selected axis should be the first range
        $colorIterateOrder = $colorIterateOrders[$axis];

        // Retrieves iteration ranges
        $firstRange  = range($vBox->{$colorIterateOrders[$axis][0].'1'}, $vBox->{$colorIterateOrders[$axis][0].'2'});
        $secondRange = range($vBox->{$colorIterateOrders[$axis][1].'1'}, $vBox->{$colorIterateOrders[$axis][1].'2'});
        $thirdRange  = range($vBox->{$colorIterateOrders[$axis][2].'1'}, $vBox->{$colorIterateOrders[$axis][2].'2'});

        foreach ($firstRange as $firstColor) {
            $sum = 0;
            foreach ($secondRange as $secondColor) {
                foreach ($thirdRange as $thirdColor) {
                    $index = static::getColorIndex(${$colorRangeOrders[$axis][0]}, ${$colorRangeOrders[$axis][1]}, ${$colorRangeOrders[$axis][2]});

                    if (isset($histo[$index])) {
                        $sum += $histo[$index];
                    }
                }
            }
            $total += $sum;
            $partialSum[$firstColor] = $total;
        }

        return [$total, $partialSum];
    }

    /**
     * Inner function to do the iteration.
     */
    private static function quantizeIter(PQueue &$priorityQueue, float $target, array $histo): void
    {
        $nColors = 1;
        $nIterations = 0;

        while ($nIterations < static::MAX_ITERATIONS) {
            $vBox = $priorityQueue->pop();

            if (!$vBox->count()) { /* just put it back */
                $priorityQueue->push($vBox);
                $nIterations++;
                continue;
            }
            // do the cut
            $vBoxes = static::medianCutApply($histo, $vBox);

            if (!(is_array($vBoxes) && isset($vBoxes[0]))) {
                // echo "vbox1 not defined; shouldn't happen!"."\n";
                return;
            }

            $priorityQueue->push($vBoxes[0]);

            if (isset($vBoxes[1])) { /* vbox2 can be null */
                $priorityQueue->push($vBoxes[1]);
                $nColors++;
            }

            if ($nColors >= $target) {
                return;
            }

            if ($nIterations++ > static::MAX_ITERATIONS) {
                // echo "infinite loop; perhaps too few pixels!"."\n";
                return;
            }
        }
    }

    private static function quantize(SplFixedArray $pixels, int $maxColors, array &$histo): CMap
    {
        // Short-Circuits
        if ($pixels->getSize() === 0) {
            throw InvalidArgumentException('Zero useable pixels found in image.');
        }
        if ($maxColors < 2 || $maxColors > 256) {
            throw InvalidArgumentException('The maxColors parameter must be between 2 and 256 inclusive.');
        }
        if (count($histo) === 0) {
            throw InvalidArgumentException('Image produced an empty histogram.');
        }


        // check that we aren't below maxcolors already
        //if (count($histo) <= $maxcolors) {
        // XXX: generate the new colors from the histo and return
        //}

        $vBox = static::vboxFromHistogram($histo);

        $priorityQueue = new PQueue(function($a, $b): int {
            return $a->count() <=> $b->count();
        });
        $priorityQueue->push($vBox);

        // first set of colors, sorted by population
        static::quantizeIter($priorityQueue, static::FRACT_BY_POPULATIONS * $maxColors, $histo);

        // Re-sort by the product of pixel occupancy times the size in color space.
        $priorityQueue->setComparator(function($a, $b): int {
            return ($a->count() * $a->volume()) <=> ($b->count() * $b->volume());
        });

        // next set - generate the median cuts using the (npix * vol) sorting.
        static::quantizeIter($priorityQueue, $maxColors - $priorityQueue->size(), $histo);

        // calculate the actual colors
        $cmap = new CMap();
        for ($i = $priorityQueue->size(); $i > 0; $i--) {
            $cmap->push($priorityQueue->pop());
        }

        return $cmap;
    }
}
