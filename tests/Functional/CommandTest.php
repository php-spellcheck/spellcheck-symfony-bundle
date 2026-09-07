<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Functional;

use PHPSpellcheck\Core\Checker\ExitCodeCalculator;
use PHPSpellcheck\SpellcheckBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CommandTest extends TestCase
{
    private const BASELINE = __DIR__.'/../Fixtures/var/baseline.json';

    protected function setUp(): void
    {
        (new Filesystem())->remove(__DIR__.'/../Fixtures/var');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(__DIR__.'/../Fixtures/var');
    }

    public function testTranslationsCommandFindsTheTypos(): void
    {
        $tester = $this->doRun('spellcheck:translations', ['--format' => 'json']);

        self::assertSame(ExitCodeCalculator::ISSUES_FOUND, $tester->getStatusCode());

        $payload = $this->decode($tester->getDisplay());
        $words = array_column($payload['issues'], 'word');

        sort($words);

        self::assertSame(['indirizio', 'messagi'], $words);
    }

    public function testPlaceholdersAndMarkupProduceNoFalsePositives(): void
    {
        $payload = $this->decode($this->doRun('spellcheck:translations', ['--format' => 'json'])->getDisplay());
        $keys = array_column(array_column($payload['issues'], 'context'), 'key');

        self::assertNotContains('cta', $keys, 'HTML markup must not produce issues');
        self::assertNotContains('items', $keys, 'legacy plural intervals must not produce issues');
    }

    public function testLocaleOptionRestrictsTheRun(): void
    {
        $payload = $this->decode(
            $this->doRun('spellcheck:translations', ['--format' => 'json', '--locale' => ['en']])->getDisplay(),
        );

        self::assertSame([], $payload['issues']);
    }

    public function testDomainOptionRestrictsTheRun(): void
    {
        $payload = $this->decode(
            $this->doRun('spellcheck:translations', ['--format' => 'json', '--domain' => ['admin.forms']])->getDisplay(),
        );

        self::assertSame([], $payload['issues']);
    }

    public function testCodeCommandFindsTheTypoInAClassName(): void
    {
        $payload = $this->decode($this->doRun('spellcheck:code', ['--format' => 'json'])->getDisplay());

        self::assertContains('Suscriber', array_column($payload['issues'], 'word'));
    }

    public function testCodeCommandAcceptsExplicitPaths(): void
    {
        $tester = $this->doRun('spellcheck:code', [
            'paths' => [__DIR__.'/../Fixtures/php/OrderSuscriber.php'],
            '--format' => 'json',
        ]);

        self::assertContains('Suscriber', array_column($this->decode($tester->getDisplay())['issues'], 'word'));
    }

    public function testGithubFormatEmitsAnnotations(): void
    {
        $display = $this->doRun('spellcheck:translations', ['--format' => 'github'])->getDisplay();

        self::assertStringContainsString('::error ', $display);
        self::assertStringContainsString('Unknown word "messagi"', $display);
    }

    public function testUnknownFormatIsAnEnvironmentError(): void
    {
        $tester = $this->doRun('spellcheck:translations', ['--format' => 'nope']);

        self::assertSame(ExitCodeCalculator::ENVIRONMENT_ERROR, $tester->getStatusCode());
    }

    public function testBaselineSuppressesEverythingOnASecondRun(): void
    {
        $this->doRun('spellcheck:baseline');

        self::assertFileExists(self::BASELINE);

        $tester = $this->doRun('spellcheck', ['--format' => 'json']);

        self::assertSame(ExitCodeCalculator::SUCCESS, $tester->getStatusCode());

        $payload = $this->decode($tester->getDisplay());

        self::assertSame([], $payload['issues']);
        self::assertGreaterThan(0, $payload['summary']['suppressed_by_baseline']);
    }

    public function testBaselineIsIgnoredOnDemand(): void
    {
        $this->doRun('spellcheck:baseline');

        $tester = $this->doRun('spellcheck', ['--format' => 'json', '--no-baseline' => true]);

        self::assertSame(ExitCodeCalculator::ISSUES_FOUND, $tester->getStatusCode());
    }

    public function testBaselineIsDeterministic(): void
    {
        $this->doRun('spellcheck:baseline');
        $first = (string) file_get_contents(self::BASELINE);

        $this->doRun('spellcheck:baseline');
        $second = (string) file_get_contents(self::BASELINE);

        self::assertSame($first, $second);
    }

    public function testBaselineDryRunWritesNothing(): void
    {
        $this->doRun('spellcheck:baseline', ['--dry-run' => true]);

        self::assertFileDoesNotExist(self::BASELINE);
    }

    public function testDoctorDescribesTheEnvironment(): void
    {
        $tester = $this->doRun('spellcheck:doctor');
        $display = $tester->getDisplay();

        self::assertStringContainsString('wordlist', $display);
        self::assertStringContainsString('Locale resolution', $display);
        self::assertContains($tester->getStatusCode(), [
            ExitCodeCalculator::SUCCESS,
            ExitCodeCalculator::WARNINGS_ONLY,
        ]);
    }

    public function testDebugFragmentsShowsTheProcessedText(): void
    {
        $display = $this->doRun('spellcheck:debug:fragments', ['--limit' => '3'])->getDisplay();

        self::assertStringContainsString('processed', $display);
        self::assertStringContainsString('tokens', $display);
    }

    public function testDebugFragmentsCanListTheProcessorChain(): void
    {
        $display = $this->doRun('spellcheck:debug:fragments', ['--processors' => true])->getDisplay();

        // The chain must be ordered by descending priority.
        self::assertLessThan(
            strpos($display, 'PlaceholderProcessor'),
            strpos($display, 'IcuMessageProcessor'),
        );
    }

    public function testDictionaryAddSortsAndDeduplicates(): void
    {
        $file = __DIR__.'/../Fixtures/var/project.txt';
        (new Filesystem())->dumpFile($file, "zeta\nalpha\n");

        $this->doRun('spellcheck:dictionary:add', ['words' => ['beta', 'alpha'], '--file' => $file]);

        self::assertSame("alpha\nbeta\nzeta\n", $this->withoutComments((string) file_get_contents($file)));
    }

    public function testTranslationsCommandIsAbsentWithoutTheTranslator(): void
    {
        $kernel = new TestKernel('test', true, [], false);
        $kernel->boot();

        $application = new Application($kernel);

        self::assertFalse($application->has('spellcheck:translations'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function doRun(string $command, array $parameters = []): CommandTester
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($command));
        $tester->execute($parameters, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array{summary: array<string, mixed>, issues: list<array<string, mixed>>}
     */
    private function decode(string $display): array
    {
        /** @var array{summary: array<string, mixed>, issues: list<array<string, mixed>>}|null $payload */
        $payload = json_decode(trim($display), true);

        self::assertIsArray($payload, 'the json reporter must emit valid JSON, got: '.$display);

        return $payload;
    }

    private function withoutComments(string $contents): string
    {
        $lines = array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line): bool => '' !== trim($line) && !str_starts_with(trim($line), '#'),
        );

        return implode("\n", $lines)."\n";
    }
}
