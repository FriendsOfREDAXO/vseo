<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Command;

use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Stats\Aggregator;

final class AggregateCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('vseo: rebuild weekly sums and overview figures')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain ID or host');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $domainId = DomainOption::resolve($input->getOption('domain'));
        if (false === $domainId) {
            $io->error('Unknown domain.');
            return 1;
        }
        $domains = null !== $domainId ? array_filter([DomainRepository::get($domainId)]) : DomainRepository::all();
        foreach ($domains as $domain) {
            $weeks = Aggregator::rebuildWeeks($domain->id);
            Aggregator::refreshKpi($domain->id);
            $io->writeln(sprintf('%s: %d week(s)', $domain->name, $weeks));
        }
        return 0;
    }
}
