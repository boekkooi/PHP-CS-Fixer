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

interface DifferInterface
{
    /**
     * Returns the diff between two arrays or strings as string.
     *
     * @param array|string $from
     * @param array|string $to
     *
     * @return string|null
     */
    public function diff($from, $to);
}
