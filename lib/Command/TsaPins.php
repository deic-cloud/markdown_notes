<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Command;

use OCA\MarkdownNotes\Service\TimestampService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ markdown_notes:tsa-pins
 *
 * The list of timestamp authorities this server accepts, and the window each is
 * accepted for. With no options it prints the list.
 *
 *   --add <file>            a PEM certificate, or a token (.tsr) to read the
 *                           authority's certificate out of
 *   --fingerprint <sha256>  add by digest instead, in any spelling
 *   --from / --until        YYYY-MM-DD, both optional; --until is inclusive
 *   --note                  free text, e.g. why this entry exists
 *   --remove <sha256>       drop an entry
 *
 * An empty list accepts any token that chains to the CA. Removing an entry is
 * how an authority is withdrawn: this CA publishes no revocation list, because
 * a list is signed by the CA and so is worthless exactly when the CA key is the
 * thing that leaked.
 */
class TsaPins extends Command {
	public function __construct(
		private TimestampService $timestamps,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('markdown_notes:tsa-pins')
			->setDescription('List, add or remove the timestamp authorities this server accepts.')
			->addOption('add', null, InputOption::VALUE_REQUIRED,
				'PEM certificate file, or a .tsr token to take the authority certificate from')
			->addOption('fingerprint', null, InputOption::VALUE_REQUIRED, 'SHA-256 fingerprint to add')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Accept tokens dated on or after (YYYY-MM-DD)')
			->addOption('until', null, InputOption::VALUE_REQUIRED, 'Accept tokens dated on or before (YYYY-MM-DD)')
			->addOption('note', null, InputOption::VALUE_REQUIRED, 'Free text kept with the entry')
			->addOption('remove', null, InputOption::VALUE_REQUIRED, 'SHA-256 fingerprint to remove')
			->addOption('write', null, InputOption::VALUE_NONE,
				'Store the result in app configuration instead of printing it for the config file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$pins = $this->timestamps->pins();

		$remove = (string)($input->getOption('remove') ?? '');
		if ($remove !== '') {
			$target = TimestampService::normalizeFingerprint($remove);
			$kept = array_values(array_filter($pins, static fn ($p) => $p['fingerprint'] !== $target));
			if (count($kept) === count($pins)) {
				$output->writeln('<error>No entry with that fingerprint.</error>');
				return 1;
			}
			$this->timestamps->setPins($kept);
			$output->writeln('Removed ' . $target . '.');
			$pins = $kept;
		}

		$add = (string)($input->getOption('add') ?? '');
		$fp  = TimestampService::normalizeFingerprint((string)($input->getOption('fingerprint') ?? ''));
		if ($add !== '') {
			$found = $this->fingerprintOf($add, $output);
			if ($found === '') {
				return 1;
			}
			$fp = $found;
		}
		if ($fp !== '') {
			$from  = $this->date((string)($input->getOption('from') ?? ''), $output);
			$until = $this->date((string)($input->getOption('until') ?? ''), $output);
			if ($from === null || $until === null) {
				return 1;
			}
			$pins = array_values(array_filter($pins, static fn ($p) => $p['fingerprint'] !== $fp));
			$pins[] = ['fingerprint' => $fp, 'from' => $from, 'until' => $until,
				'note' => (string)($input->getOption('note') ?? '')];
			if ($input->getOption('write')) {
				$this->timestamps->setPins($pins);
				$output->writeln('Stored in app configuration. Accepting ' . $fp
					. ($from !== '' ? ' from ' . $from : '')
					. ($until !== '' ? ' until ' . $until : '') . '.');
			} else {
				// Trust settings belong in a file someone reviewed and saved, not in
				// whatever a command happened to write, so the default is to hand the
				// block over rather than to change anything.
				$output->writeln('Nothing was changed. Put this in config/sciencedata.config.php '
					. '(or pass --write to store it in app configuration instead):');
				$output->writeln('');
				$output->writeln($this->block($pins));
				return 0;
			}
		}

		$output->writeln('Read from: ' . $this->timestamps->pinsSource());
		if ($pins === []) {
			$output->writeln('No authority is pinned: any token that chains to the CA is accepted.');
			return 0;
		}
		$output->writeln('Accepted timestamp authorities:');
		foreach ($pins as $p) {
			$output->writeln(sprintf('  %s  %s .. %s  %s', $p['fingerprint'],
				$p['from'] !== '' ? $p['from'] : 'always',
				$p['until'] !== '' ? $p['until'] : 'onwards',
				$p['note']));
		}
		return 0;
	}

	/**
	 * The whole list as a block to paste into a config file. Printed, never
	 * written: a text file is edited, reviewed and saved by a person.
	 *
	 * @param list<array{fingerprint: string, from: string, until: string, note: string}> $pins
	 */
	private function block(array $pins): string {
		$lines = ["'timestamp_authority' => [", "\t'pins' => ["];
		foreach ($pins as $p) {
			$lines[] = sprintf("\t\t['fingerprint' => '%s', 'from' => '%s', 'until' => '%s', 'note' => '%s'],",
				$p['fingerprint'], $p['from'], $p['until'], str_replace("'", "\\'", $p['note']));
		}
		$lines[] = "\t],";
		$lines[] = '],';
		return implode("\n", $lines);
	}

	/** Fingerprint of a PEM certificate, or of the authority certificate inside a token. */
	private function fingerprintOf(string $path, OutputInterface $output): string {
		if (!is_readable($path)) {
			$output->writeln('<error>Cannot read ' . $path . '</error>');
			return '';
		}
		$content = (string)file_get_contents($path);
		if (str_contains($content, '-----BEGIN CERTIFICATE-----')) {
			$digest = openssl_x509_fingerprint($content, 'sha256');
			if ($digest === false) {
				$output->writeln('<error>Not a readable certificate: ' . $path . '</error>');
				return '';
			}
			return TimestampService::normalizeFingerprint($digest);
		}
		// A token: take the certificate that signed it, skipping the CA it chains to.
		$certs = $this->timestamps->signerCertificates($path);
		if ($certs === []) {
			$output->writeln('<error>No certificate found in ' . $path
				. ' (expected a PEM certificate or a .tsr token).</error>');
			return '';
		}
		$first = '';
		foreach ($certs as $digest => $subject) {
			$output->writeln('  ' . $digest . '  ' . $subject);
			if ($first === '') {
				$first = $digest;
			}
		}
		if (count($certs) > 1) {
			$output->writeln('<comment>Taking the first, which is the signer; pass --fingerprint to choose '
				. 'another.</comment>');
		}
		return $first;
	}

	/** @return string|null the date, or null when it is unusable */
	private function date(string $value, OutputInterface $output): ?string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value . ' 00:00:00 UTC') === false) {
			$output->writeln('<error>Dates must be YYYY-MM-DD: ' . $value . '</error>');
			return null;
		}
		return $value;
	}
}
