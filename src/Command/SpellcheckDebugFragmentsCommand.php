<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Command;

use PHPSpellcheck\Core\Checker\ExitCodeCalculator;
use PHPSpellcheck\Core\Processor\ProcessorChain;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\Core\Tokenizer\TokenizerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows what the pipeline actually produces. This is the tool to reach for when
 * a word is not reported and it is not obvious why.
 */
#[AsCommand(
    name: 'spellcheck:debug:fragments',
    description: 'Dumps the fragments after the processor pipeline, with the extracted tokens',
)]
final class SpellcheckDebugFragmentsCommand extends Command
{
    public function __construct(
        private readonly ProcessorChain $processors,
        private readonly TokenizerRegistry $tokenizers,
        private readonly ?SourceInterface $translations = null,
        private readonly ?SourceInterface $code = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'translations or php', 'translations')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many fragments to print', '20')
            ->addOption('grep', null, InputOption::VALUE_REQUIRED, 'Only fragments containing this string')
            ->addOption('processors', null, InputOption::VALUE_NONE, 'List the processor chain and exit')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('processors')) {
            $io->title('Processor chain, in execution order');
            $io->listing($this->processors->getProcessorNames());

            return ExitCodeCalculator::SUCCESS;
        }

        $name = (string) $input->getOption('source');
        $source = 'php' === $name ? $this->code : $this->translations;

        if (null === $source) {
            $io->error(sprintf('The "%s" source is not available.', $name));

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $grep = $input->getOption('grep');
        $grep = \is_string($grep) && '' !== $grep ? $grep : null;

        $printed = 0;

        foreach ($source->fragments() as $fragment) {
            if (null !== $grep && !str_contains($fragment->text, $grep)) {
                continue;
            }

            foreach ($this->processors->process($fragment) as $processed) {
                $tokens = [];

                foreach ($this->tokenizers->tokenize($processed) as $word) {
                    $tokens[] = sprintf('%s@%d', $word->value, $word->offset);
                }

                $io->definitionList(
                    ['context' => $processed->context->fingerprintSeed()],
                    ['location' => (string) $processed->location],
                    ['language' => $processed->language],
                    ['tokenizer' => $processed->tokenizer->value],
                    ['original' => $processed->getOriginalText()],
                    ['processed' => $processed->text],
                    ['tokens' => [] === $tokens ? '(none)' : implode(', ', $tokens)],
                );

                if (++$printed >= $limit) {
                    $io->comment(sprintf('Stopped after %d fragments; raise --limit to see more.', $limit));

                    return ExitCodeCalculator::SUCCESS;
                }
            }
        }

        $io->comment(sprintf('%d fragment(s) printed.', $printed));

        return ExitCodeCalculator::SUCCESS;
    }
}
