<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS\Differ;

use SebastianBergmann\Diff\Differ;

class DiffDiffer implements DifferInterface
{
    /**
     * Differ instance.
     *
     * @var Differ
     */
    protected $diff;

    public function __construct()
    {
        $this->diff = new Differ();
    }

    /**
     * {@inheritdoc}
     */
    public function diff($from, $to)
    {
        $diff = $this->diff->diff($from, $to);

        $diff = implode(
            PHP_EOL,
            array_map(
                function ($string) {
                    $string = preg_replace('/^(\+){3}/', '<info>+++</info>', $string);
                    $string = preg_replace('/^(\+){1}/', '<info>+</info>', $string);

                    $string = preg_replace('/^(\-){3}/', '<error>---</error>', $string);
                    $string = preg_replace('/^(\-){1}/', '<error>-</error>', $string);

                    $string = str_repeat(' ', 6).$string;

                    return $string;
                },
                explode(PHP_EOL, $diff)
            )
        );

        return $diff;
    }
}
