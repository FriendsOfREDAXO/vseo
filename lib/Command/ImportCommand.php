<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Command;

use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Viewsion\SeoMonitor\Import\Runner;
use Viewsion\SeoMonitor\Util\Budget;

final class ImportCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('vsn_seo: import data (recent days + next backfill step)')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain ID or host')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source (e.g. gsc)')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Time budget in seconds (0 = unlimited)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $domainId = DomainOption::resolve($input->getOption('domain'));
        if (false === $domainId) {
            $io->error('Unknown domain.');
            return 1;
        }
        $source = $input->getOption('source');
        $runner = new Runner();
        $ok = $runner->run(
            new Budget((int) $input->getOption('budget')),
            $domainId,
            is_string($source) && '' !== $source ? [$source] : null,
        );
        foreach ($runner->messages() as $message) {
            $io->writeln($message);
        }
        return $ok ? 0 : 1;
    }
}
