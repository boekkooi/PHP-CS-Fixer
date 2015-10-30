<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS;

use Exception;

class FileResult
{
    const STATUS_INVALID = 1;
    const STATUS_SKIPPED = 2;
    const STATUS_NO_CHANGES = 3;
    const STATUS_FIXED = 4;
    const STATUS_EXCEPTION = 5;
    const STATUS_LINT = 6;

    /**
     * @var int
     */
    private $status;
    /**
     * @var string|null
     */
    private $message;
    /**
     * @var Exception|null
     */
    private $exception;
    /**
     * @var string[]
     */
    private $appliedFixers;
    /**
     * @var mixed
     */
    private $codeDiff;

    private function __construct($status)
    {
        $this->status = $status;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return null|string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return Exception|null
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * @return \string[]
     */
    public function getAppliedFixers()
    {
        return $this->appliedFixers;
    }

    /**
     * @return mixed
     */
    public function getCodeDiff()
    {
        return $this->codeDiff;
    }

    public function setCodeDiff($diff = null)
    {
        $this->codeDiff = $diff;
    }

    public static function skipped($reason)
    {
        $result = new static(self::STATUS_SKIPPED);
        $result->message = $reason;

        return $result;
    }

    public static function invalidSource(Exception $exception)
    {
        $result = new static(self::STATUS_INVALID);
        $result->message = $exception->getMessage();
        $result->exception = $exception;

        return $result;
    }

    public static function invalidAfterFixing(Exception $exception)
    {
        $result = new static(self::STATUS_LINT);
        $result->message = $exception->getMessage();
        $result->exception = $exception;

        return $result;
    }

    public static function fixException(Exception $exception)
    {
        $result = new static(self::STATUS_EXCEPTION);
        $result->message = $exception->getMessage();
        $result->exception = $exception;

        return $result;
    }

    public static function fixed(array $appliedFixers)
    {
        $result = new static(self::STATUS_FIXED);
        $result->appliedFixers = $appliedFixers;

        return $result;
    }

    public static function noChanges()
    {
        return new static(self::STATUS_NO_CHANGES);
    }
}
