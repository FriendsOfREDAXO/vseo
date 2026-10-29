<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Command;

use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use FriendsOfRedaxo\VSeo\Import\Runner;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Source\Gsc\SearchAnalyticsSource;
use FriendsOfRedaxo\VSeo\Stats\Aggregator;
use FriendsOfRedaxo\VSeo\Util\Budget;
use FriendsOfRedaxo\VSeo\Util\Lock;

/**
 * Lädt einen festen Zeitraum der Search Console neu, unabhängig vom Backfill-Stand.
 */
final class BackfillCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('vseo: (re)load Search Console data for a date range')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain ID or host')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD (default: yesterday)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $domainId = DomainOption::resolve($input->getOption('domain'));
        $domain = $domainId ? DomainRepository::get($domainId) : null;
        $from = (string) $input->getOption('from');
        $to = (string) ($input->getOption('to') ?: date('Y-m-d', strtotime('-1 day')));
        if (!$domain || !$domain->hasGsc()) {
            $io->error('--domain is required and needs a Search Console property.');
            return 1;
        }
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            $io->error('--from/--to must be YYYY-MM-DD and from <= to.');
            return 1;
        }
        if (!Lock::acquire(Runner::LOCK)) {
            $io->error('Another import is running.');
            return 1;
        }
        try {
            $source = new SearchAnalyticsSource();
            $budget = new Budget(0);
            $total = 0;
            // monatsweise, damit der Fortschritt sichtbar ist
            for ($start = $from; $start <= $to; $start = Aggregator::shift($end, 1)) {
                $end = min($to, Aggregator::shift($start, 29));
                $done = $source->importRange($domain, $start, $end, $budget, false);
                Aggregator::refreshWeeksFor($domain->id, $done);
                $total += count($done);
                $io->writeln(sprintf('%s … %s: %d day(s)', $start, $end, count($done)));
            }
            Aggregator::refreshKpi($domain->id);
        } finally {
            Lock::release(Runner::LOCK);
        }
        $io->success(sprintf('%d day(s) imported for %s.', $total, $domain->name));
        return 0;
    }
}
