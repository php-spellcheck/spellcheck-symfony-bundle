<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Report;

use PHPSpellcheck\Core\Report\WriterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapter between the core WriterInterface and symfony/console.
 *
 * Reporter output goes to stdout only; warnings and execution errors are
 * written to stderr by the commands themselves.
 */
final class ConsoleWriter implements WriterInterface
{
    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function write(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    public function writeln(string $text = ''): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    public function isDecorated(): bool
    {
        return $this->output->isDecorated();
    }
}
