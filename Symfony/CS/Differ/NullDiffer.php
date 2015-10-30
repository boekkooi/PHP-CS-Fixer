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

/**
 * Dummy differ. No diffing is performed. No error is raised.
 *
 * @internal
 */
final class NullDiffer implements DifferInterface
{
    /**
     * {@inheritdoc}
     */
    public function diff($from, $to)
    {
        unset($from, $to);
    }
}
