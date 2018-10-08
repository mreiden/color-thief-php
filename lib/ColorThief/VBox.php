<?php declare(strict_types=1);

namespace ColorThief;

class VBox
{
    public $r1;
    public $r2;
    public $g1;
    public $g2;
    public $b1;
    public $b2;
    public $histo;

    private $volume = false;
    private $count;
    private $count_set = false;
    private $avg = false;

    public function __construct(int $r1, int $r2, int $g1, int $g2, int $b1, int $b2, array $histo)
    {
        $this->r1 = $r1;
        $this->r2 = $r2;
        $this->g1 = $g1;
        $this->g2 = $g2;
        $this->b1 = $b1;
        $this->b2 = $b2;
        $this->histo = $histo;
    }

    public function volume(?bool $force = false): int
    {
        if (!$this->volume || $force) {
            $this->volume = (($this->r2 - $this->r1 + 1) * ($this->g2 - $this->g1 + 1) * ($this->b2 - $this->b1 + 1));
        }

        return $this->volume;
    }

    public function count(?bool $force = false): int
    {
        if (!$this->count_set || $force) {
            $npix = 0;

            // Select the fastest way (i.e. with the fewest iterations) to count
            // the number of pixels contained in this vbox.
            if ($this->volume() > count($this->histo)) {
                // Iterate over the histogram if the size of this histogram is lower than the vbox volume
                foreach ($this->histo as $rgb => $count) {
                    $rgb_array = ColorThief::getColorsFromIndex($rgb);
                    if ($this->contains($rgb_array, 0)) {
                        $npix += $count;
                    }
                }
            } else {
                // Or iterate over points of the vbox if the size of the histogram is greater than the vbox volume
                for ($i = $this->r1; $i <= $this->r2; $i++) {
                    for ($j = $this->g1; $j <= $this->g2; $j++) {
                        for ($k = $this->b1; $k <= $this->b2; $k++) {
                            $index = ColorThief::getColorIndex($i, $j, $k);
                            if (isset($this->histo[$index])) {
                                $npix += $this->histo[$index];
                            }
                        }
                    }
                }
            }
            $this->count = $npix;
            $this->count_set = true;
        }

        return $this->count;
    }

    public function copy(): VBox
    {
        return new self($this->r1, $this->r2, $this->g1, $this->g2, $this->b1, $this->b2, $this->histo);
    }

    public function avg(?bool $force = false): array
    {
        if (!$this->avg || $force) {
            $ntot = 0;
            $rsum = 0;
            $gsum = 0;
            $bsum = 0;

            for ($i = $this->r1; $i <= $this->r2; $i++) {
                for ($j = $this->g1; $j <= $this->g2; $j++) {
                    for ($k = $this->b1; $k <= $this->b2; $k++) {
                        $histoindex = ColorThief::getColorIndex($i, $j, $k);
                        $hval = isset($this->histo[$histoindex]) ? $this->histo[$histoindex] : 0;
                        $ntot += $hval;
                        $rsum += ($hval * ($i + 0.5));
                        $gsum += ($hval * ($j + 0.5));
                        $bsum += ($hval * ($k + 0.5));
                    }
                }
            }

            if ($ntot) {
                $this->avg = [
                    (int) ($rsum / $ntot),
                    (int) ($gsum / $ntot),
                    (int) ($bsum / $ntot),
                ];
            } else {
                // echo 'empty box'."\n";
                $this->avg = [
                    (int) (($this->r1 + $this->r2 + 1) / 2),
                    (int) (($this->g1 + $this->g2 + 1) / 2),
                    (int) (($this->b1 + $this->b2 + 1) / 2),
                ];

                // Ensure all channel values are leather or equal 255 (Issue #24)
                $this->avg = array_map(function ($val) {
                    return min($val, 255);
                }, $this->avg);
            }
        }

        return $this->avg;
    }

    public function contains(array $pixel, ?int $rshift = ColorThief::RSHIFT): bool
    {
        $rval = $pixel[0] >> $rshift;
        $gval = $pixel[1] >> $rshift;
        $bval = $pixel[2] >> $rshift;

        return
            $rval >= $this->r1 &&
            $rval <= $this->r2 &&
            $gval >= $this->g1 &&
            $gval <= $this->g2 &&
            $bval >= $this->b1 &&
            $bval <= $this->b2;
    }

    /**
     * Determines the longest axis.
     */
    public function longestAxis(): string
    {
        // Color-Width for RGB
        $red = $this->r2 - $this->r1;
        $green = $this->g2 - $this->g1;
        $blue = $this->b2 - $this->b1;

        return $red >= $green && $red >= $blue ? 'r' : ($green >= $red && $green >= $blue ? 'g' : 'b');
    }
}
