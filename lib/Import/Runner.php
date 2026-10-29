<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Import;

use Throwable;
use FriendsOfRedaxo\VSeo\Model\Domain;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Source\DataSources;
use FriendsOfRedaxo\VSeo\Stats\Cleanup;
use FriendsOfRedaxo\VSeo\Util\Budget;
use FriendsOfRedaxo\VSeo\Util\Lock;
use FriendsOfRedaxo\VSeo\Util\Log;

/**
 * Führt die Importe aller Datenquellen aus, mit Sperre gegen parallele Läufe.
 * Domains, die am längsten nicht importiert wurden, kommen zuerst dran.
 */
final class Runner
{
    public const LOCK = 'import';

    /** @var list<string> */
    private array $messages = [];

    private int $errors = 0;

    /**
     * @param list<string>|null $sources nur diese Quellen (Schlüssel)
     */
    public function run(Budget $budget, ?int $domainId = null, ?array $sources = null): bool
    {
        if (!Lock::acquire(self::LOCK)) {
            $this->messages[] = 'Another import is running.';
            return false;
        }
        try {
            $domains = null !== $domainId ? array_filter([DomainRepository::get($domainId)]) : DomainRepository::all(true);
            usort($domains, static fn (Domain $a, Domain $b) => strcmp((string) $a->lastImport, (string) $b->lastImport));

            foreach ($domains as $domain) {
                if ($budget->exceeded()) {
                    $this->messages[] = 'Budget exceeded, continuing next run.';
                    break;
                }
                $this->runDomain($domain, $budget, $sources);
            }
            if ($deleted = Cleanup::runDaily()) {
                $this->messages[] = sprintf('Cleanup: %d detail row(s) deleted.', $deleted);
            }
        } finally {
            Lock::release(self::LOCK);
        }
        return 0 === $this->errors;
    }

    /** @param list<string>|null $sources */
    private function runDomain(Domain $domain, Budget $budget, ?array $sources): void
    {
        $error = null;
        foreach (DataSources::all() as $key => $source) {
            if ((null !== $sources && !in_array($key, $sources, true)) || !$source->supports($domain)) {
                continue;
            }
            try {
                array_push($this->messages, ...$source->import($domain, $budget));
            } catch (Throwable $e) {
                ++$this->errors;
                $error = $source->label() . ': ' . $e->getMessage();
                $this->messages[] = $domain->name . ': ' . $error;
                Log::error($domain->name . ': ' . $error);
            }
        }
        DomainRepository::patch($domain->id, ['last_import' => date('Y-m-d H:i:s'), 'last_error' => $error]);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}
