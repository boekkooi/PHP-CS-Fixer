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

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo as FinderSplFileInfo;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\CS\Differ\DiffDiffer;
use Symfony\CS\Differ\DifferInterface;
use Symfony\CS\Differ\NullDiffer;
use Symfony\CS\Error\Error;
use Symfony\CS\Error\ErrorsManager;
use Symfony\CS\Linter\LinterInterface;
use Symfony\CS\Linter\NullLinter;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class Fixer
{
    const VERSION = '2.0-DEV';

    protected $configs = array();

    /**
     * EventDispatcher instance.
     *
     * @var EventDispatcher|null
     */
    protected $eventDispatcher;

    /**
     * Errors manager instance.
     *
     * @var ErrorsManager
     */
    protected $errorsManager;

    /**
     * Linter instance.
     *
     * @var LinterInterface
     */
    protected $linter;

    /**
     * Stopwatch instance.
     *
     * @var Stopwatch
     */
    protected $stopwatch;

    public function __construct()
    {
        $this->errorsManager = new ErrorsManager();
        $this->linter = new NullLinter();
        $this->stopwatch = new Stopwatch();
    }

    public function registerBuiltInConfigs()
    {
        foreach (Finder::create()->files()->in(__DIR__.'/Config') as $file) {
            $relativeNamespace = $file->getRelativePath();
            $class = 'Symfony\\CS\\Config\\'.($relativeNamespace ? $relativeNamespace.'\\' : '').$file->getBasename('.php');
            $this->addConfig(new $class());
        }
    }

    public function addConfig(ConfigInterface $config)
    {
        $this->configs[] = $config;
    }

    public function getConfigs()
    {
        return $this->configs;
    }

    /**
     * Get the errors manager instance.
     *
     * @return ErrorsManager
     */
    public function getErrorsManager()
    {
        return $this->errorsManager;
    }

    /**
     * Get stopwatch instance.
     *
     * @return Stopwatch
     */
    public function getStopwatch()
    {
        return $this->stopwatch;
    }

    /**
     * Fixes all files for the given finder.
     *
     * @param ConfigInterface $config A ConfigInterface instance
     * @param bool            $dryRun Whether to simulate the changes or not
     * @param bool            $diff   Whether to provide diff
     *
     * @return array
     */
    public function fix(ConfigInterface $config, $dryRun = false, $diff = false)
    {
        $changed = array();
        $fixers = $config->getFixers();

        $this->stopwatch->openSection();

        $fileCacheManager = new FileCacheManager(
            $config->usingCache(),
            $config->getCacheFile(),
            $config->getRules()
        );

        foreach ($config->getFinder() as $file) {
            if ($file->isDir() || $file->isLink()) {
                continue;
            }

            $this->stopwatch->start($this->getFileRelativePathname($file));

            if ($fixInfo = $this->fixFile($file, $fixers, $dryRun, $diff, $fileCacheManager)) {
                $changed[$this->getFileRelativePathname($file)] = $fixInfo;
            }

            $this->stopwatch->stop($this->getFileRelativePathname($file));
        }

        $this->stopwatch->stopSection('fixFile');

        return $changed;
    }

    public function fixFile(\SplFileInfo $file, array $fixers, $dryRun, $diff, FileCacheManager $fileCacheManager)
    {
        $fileFixer = new FileFixer($fileCacheManager, $this->linter, $this->resolveDiffer($diff));
        $result = $fileFixer->execute($fixers, $file, $dryRun);

        $this->dispatchEvent(
            FixerFileProcessedEvent::NAME,
            FixerFileProcessedEvent::create()->setStatus($result->getStatus())
        );

        if ($result->getStatus() === FileResult::STATUS_FIXED) {
            $fixInfo = array(
                'appliedFixers' => $result->getAppliedFixers(),
            );
            if ($diff) {
                $fixInfo['diff'] = $result->getCodeDiff();
            }

            return $fixInfo;
        }

        switch ($result->getStatus()) {
            case FileResult::STATUS_LINT:
                $this->errorsManager->report(new Error(
                    Error::TYPE_LINT,
                    $this->getFileRelativePathname($file)
                ));
                break;
            case FileResult::STATUS_EXCEPTION:
                $this->errorsManager->report(new Error(
                    Error::TYPE_EXCEPTION,
                    $this->getFileRelativePathname($file)
                ));
                break;
            case FileResult::STATUS_INVALID:
                $this->errorsManager->report(new Error(
                    Error::TYPE_INVALID,
                    $this->getFileRelativePathname($file)
                ));
                break;
        }
    }

    private function getFileRelativePathname(\SplFileInfo $file)
    {
        if ($file instanceof FinderSplFileInfo) {
            return $file->getRelativePathname();
        }

        return $file->getPathname();
    }

    private function resolveDiffer($differ)
    {
        if ($differ === false || $differ === null) {
            return new NullDiffer();
        }
        if ($differ === true) {
            return new DiffDiffer();
        }
        if ($differ instanceof DifferInterface) {
            return $differ;
        }

        throw new \InvalidArgumentException();
    }

    /**
     * Set EventDispatcher instance.
     *
     * @param EventDispatcher|null $eventDispatcher
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher = null)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Set linter instance.
     *
     * @param LinterInterface $linter
     */
    public function setLinter(LinterInterface $linter)
    {
        $this->linter = $linter;
    }

    /**
     * Dispatch event.
     *
     * @param string $name
     * @param Event  $event
     */
    private function dispatchEvent($name, Event $event)
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $this->eventDispatcher->dispatch($name, $event);
    }
}
