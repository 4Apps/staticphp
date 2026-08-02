<?php

namespace StaticPHP\Skeleton\Tests;

/**
 * Throwaway directory trees, written from a path => contents map.
 *
 * The upgrader's whole interface is three directories, so nearly every test here is "build
 * these trees, compare them, assert what came out". Nothing in this trait touches git or the
 * network - that is only needed by the integration test.
 */
trait TempTree
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * @param array<string, string> $files Relative path => contents
     */
    private function tree(array $files): string
    {
        $dir = $this->makeDir();

        foreach ($files as $path => $contents) {
            $this->put($dir, $path, $contents);
        }

        return $dir;
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . '/staticphp-test-' . bin2hex(random_bytes(6));
        if (mkdir($dir, 0700, true) === false) {
            self::fail("Could not create {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function put(string $dir, string $path, string $contents): void
    {
        $full = $dir . '/' . $path;
        $parent = dirname($full);

        if (is_dir($parent) === false && mkdir($parent, 0700, true) === false && is_dir($parent) === false) {
            self::fail("Could not create {$parent}");
        }

        if (file_put_contents($full, $contents) === false) {
            self::fail("Could not write {$full}");
        }
    }

    private function read(string $dir, string $path): string
    {
        $contents = @file_get_contents($dir . '/' . $path);

        return ($contents === false ? '' : $contents);
    }

    private function removeTempTrees(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }

        $this->tempDirs = [];
    }

    private static function removeTree(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) === false || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path) && is_link($path) === false) {
                self::removeTree($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
