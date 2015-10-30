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

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Finder\SplFileInfo as FinderSplFileInfo;
use Symfony\CS\Differ\DifferInterface;
use Symfony\CS\Linter\LinterInterface;
use Symfony\CS\Linter\LintingException;
use Symfony\CS\Tokenizer\Tokens;

class FileFixer
{
    /**
     * @var FileCacheManager
     */
    private $fileCacheManager;
    /**
     * @var LinterInterface
     */
    private $linter;
    /**
     * @var DifferInterface
     */
    private $differ;

    public function __construct(FileCacheManager $fileCacheManager, LinterInterface $linter, DifferInterface $differ)
    {
        $this->fileCacheManager = $fileCacheManager;
        $this->linter = $linter;
        $this->differ = $differ;
    }

    public function execute(array $fixers, \SplFileInfo $file, $dryRun = false)
    {
        $old = file_get_contents($file->getRealPath());

        // Ensure that we should and can fix the given file
        if (
            ($result = $this->shouldFix($file, $old)) !== true ||
            ($result = $this->canFix($file)) !== true
        ) {
            return $result;
        }

        $tokens = $this->tokenize($old);

        // Fix
        try {
            $fixResult = $this->fix($fixers, $file, $tokens);
        } catch (\Exception $e) {
            return FileResult::fixException($e);
        }

        // No result from the fix so nothing changed
        if ($fixResult === null) {
            return FileResult::noChanges();
        }

        // Check the fix and apply the fix result
        $new = $tokens->generateCode();
        if (
            ($result = $this->checkFix($tokens, $new)) !== true ||
            (
                !$dryRun &&
                ($result = $this->applyFix($file, $new)) !== true
            )
        ) {
            return $result;
        }

        // Enhance the fix result with some extra data
        return $this->enhanceFixResult($fixResult, $old, $new);
    }

    /**
     * Check whether this given file should be fixed.
     *
     * @param \SplFileInfo $file
     * @param string       $source
     *
     * @return bool|FileResult True if the file requires fixing else a FileResult instance
     */
    protected function shouldFix(\SplFileInfo $file, $source)
    {
        if ('' === $source) {
            return FileResult::skipped('No content');
        }

        if (!$this->fileCacheManager->needFixing($this->getFileRelativePathname($file), $source)) {
            return FileResult::skipped('No changes');
        }

        // PHP 5.3 has a broken implementation of token_get_all when the file uses __halt_compiler() starting in 5.3.6
        if (PHP_VERSION_ID >= 50306 && PHP_VERSION_ID < 50400 && false !== stripos($source, '__halt_compiler()')) {
            return FileResult::skipped('No changes');
        }

        return true;
    }

    /**
     * Checks whether we can fix a file.
     *
     * @param \SplFileInfo $file
     *
     * @return bool|FileResult True if the file can be fixed else a FileResult instance
     */
    protected function canFix(\SplFileInfo $file)
    {
        try {
            $this->linter->lintFile($file->getRealPath());
        } catch (LintingException $e) {
            return FileResult::invalidSource($e);
        }

        return true;
    }

    /**
     * @param FixerInterface[] $fixers
     * @param \SplFileInfo     $file
     * @param Tokens           $tokens
     *
     * @return FileResult|null A FileResult or NULL if not changes where made
     */
    protected function fix(array $fixers, \SplFileInfo $file, Tokens $tokens)
    {
        $newHash = $oldHash = $tokens->getCodeHash();

        $appliedFixers = array();
        foreach ($fixers as $fixer) {
            if (!$fixer->supports($file) || !$fixer->isCandidate($tokens)) {
                continue;
            }

            $fixer->fix($file, $tokens);

            if ($tokens->isChanged()) {
                $tokens->clearEmptyTokens();
                $tokens->clearChanged();
                $appliedFixers[] = $fixer->getName();
            }
        }

        // should can do changed? correct
        if (!empty($appliedFixers)) {
            $tokens->generateCode();
            $newHash = $tokens->getCodeHash();
        }

        // We need to check if content was changed and then applied changes.
        // But we can't simple check $appliedFixers, because one fixer may revert
        // work of other and both of them will mark collection as changed.
        // Therefore we need to check if code hashes changed.
        if ($oldHash === $newHash) {
            return;
        }

        return FileResult::fixed($appliedFixers);
    }

    /**
     * Check that the fixed code is correct.
     *
     * @param Tokens $tokens
     * @param string $code
     *
     * @return bool|static
     */
    protected function checkFix(Tokens $tokens, $code)
    {
        try {
            $this->linter->lintSource($code);
        } catch (LintingException $e) {
            return FileResult::invalidAfterFixing($e);
        }

        return true;
    }

    /**
     * Write/Apply the fixed code to the file.
     *
     * @param \SplFileInfo $file
     * @param string       $code
     *
     * @return bool
     */
    protected function applyFix(\SplFileInfo $file, $code)
    {
        if (false === @file_put_contents($file->getRealPath(), $code)) {
            $error = error_get_last();
            if ($error) {
                throw new IOException(sprintf('Failed to write file "%s", "%s".', $file->getRealPath(), $error['message']), 0, null, $file->getRealPath());
            }
            throw new IOException(sprintf('Failed to write file "%s".', $file->getRealPath()), 0, null, $file->getRealPath());
        }

        $this->fileCacheManager->setFile(
            $this->getFileRelativePathname($file),
            $code
        );

        return true;
    }

    /**
     * @param FileResult $fixResult
     * @param string     $oldCode
     * @param string     $newCode
     *
     * @return FileResult
     */
    protected function enhanceFixResult(FileResult $fixResult, $oldCode, $newCode)
    {
        $fixResult->setCodeDiff(
            $this->differ->diff($oldCode, $newCode)
        );

        return $fixResult;
    }

    protected function getFileRelativePathname(\SplFileInfo $file)
    {
        if ($file instanceof FinderSplFileInfo) {
            return $file->getRelativePathname();
        }

        return $file->getPathname();
    }

    /**
     * @param string $code
     *
     * @return Tokens
     */
    protected function tokenize($code)
    {
        // we do not need Tokens to still caching previously fixed file - so clear the cache
        Tokens::clearCache();

        return Tokens::fromCode($code);
    }
}
