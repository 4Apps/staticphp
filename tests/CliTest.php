<?php

namespace StaticPHP\Skeleton\Tests;

use PHPUnit\Framework\TestCase;
use StaticPHP\Skeleton\App\Cli as AppCli;
use StaticPHP\Skeleton\Cli;

/**
 * The top level help.
 *
 * It lists whatever is registered, framework commands included, and takes each summary off
 * constants those classes may or may not declare - so the cases worth pinning down are the
 * ones where a command tells it nothing.
 */
final class CliTest extends TestCase
{
    public function testEveryRegisteredCommandCanActuallyBeDispatched(): void
    {
        $commands = Cli::commands();

        self::assertArrayHasKey('app', $commands);
        self::assertArrayHasKey('upgrade', $commands);

        foreach ($commands as $name => $class) {
            self::assertTrue(class_exists($class), "{$name} points at a missing class");
            self::assertTrue(method_exists($class, 'run'), "{$name} has no run() to dispatch to");
        }
    }

    public function testADeclaredDescriptionIsUsedVerbatim(): void
    {
        $help = Cli::help(['app' => AppCli::class]);

        self::assertStringContainsString('app        ' . AppCli::DESCRIPTION, $help);
    }

    public function testACommandThatDeclaresNothingIsStillListed(): void
    {
        $help = Cli::help(['bare' => \stdClass::class]);

        self::assertMatchesRegularExpression('/^  bare\s*$/m', $help);
    }

    public function testCommandsAreListedInAPredictableOrder(): void
    {
        $help = Cli::help(['zebra' => \stdClass::class, 'alpha' => \stdClass::class]);

        self::assertLessThan(strpos($help, 'alpha'), strpos($help, 'Commands:'));
        self::assertLessThan(strpos($help, 'zebra'), strpos($help, 'alpha'));
    }

    /**
     * The real thing, at the width a terminal is likely to be. Both times this has been
     * wrong it was a line in the static text, not a generated one.
     */
    public function testNothingInTheHelpWrapsPastEightyColumns(): void
    {
        foreach (explode("\n", Cli::help(Cli::commands())) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line), "Wraps past 80 columns: {$line}");
        }
    }

    public function testAnOpeningParagraphBecomesTheSummary(): void
    {
        $usage = <<<TXT
        Usage: staticphp prose [options]

        Checks a handful of things and reports back. Reads only.

        Options:
          --help   This text
        TXT;

        // The first sentence, not the whole paragraph
        self::assertSame('Checks a handful of things and reports back.', Cli::opening($usage));
    }

    public function testAWrappedSentenceIsJoinedBackTogether(): void
    {
        $usage = <<<TXT
        Usage: staticphp wrapped

        Checks the things that
        need checking. And then some.
        TXT;

        self::assertSame('Checks the things that need checking.', Cli::opening($usage));
    }

    public function testAUsageThatGoesStraightIntoATableHasNoSummary(): void
    {
        $usage = <<<TXT
        Usage: staticphp tabular <command>

        Commands:
          status   List everything
        TXT;

        // Better an empty column than a line lifted out of an options table
        self::assertSame('', Cli::opening($usage));
    }

    public function testAnEmptyUsageIsNotASummary(): void
    {
        self::assertSame('', Cli::opening(''));
        self::assertSame('', Cli::opening('Usage: staticphp thing'));
    }

    public function testALongSentenceIsCutBetweenWords(): void
    {
        $usage = <<<TXT
        Usage: staticphp wordy

        Reconciles the supercalifragilistic configuration across every environment
        that has ever been declared anywhere.
        TXT;

        $summary = Cli::opening($usage);

        self::assertStringEndsWith('...', $summary);
        self::assertLessThanOrEqual(65, mb_strlen($summary));

        // Cut between words rather than through one
        self::assertStringNotContainsString('supercalif...', $summary);
        self::assertStringStartsWith('Reconciles the supercalifragilistic configuration', $summary);
    }
}
