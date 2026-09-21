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
 * Shows which timestamp authorities this node accepts, and works out the
 * fingerprint of one so it can be added.
 *
 * It changes nothing, ever. The list lives in the `timestamp_authority` block
 * of a config file, which is edited, reviewed and saved by a person; this
 * command's job is to save them reading a digest off a certificate by hand and
 * to print a block in the right shape.
 */
class TsaPins extends Command {
	public function __construct(
		private TimestampService $timestamps,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('markdown_notes:tsa-pins')
			->setDescription('Show the timestamp authorities this node accepts, or work out a fingerprint to add.')
			->addOption('add', null, InputOption::VALUE_REQUIRED,
				'PEM certificate file, or a .tsr token to take the authority certificate from')
			->addOption('fingerprint', null, InputOption::VALUE_REQUIRED, 'SHA-256 fingerprint to put in the block')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Accept tokens dated on or after (YYYY-MM-DD)')
			->addOption('until', null, InputOption::VALUE_REQUIRED, 'Accept tokens dated on or before (YYYY-MM-DD)')
			->addOption('note', null, InputOption::VALUE_REQUIRED, 'Free text kept with the entry');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$pins = $this->timestamps->pins();

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
			$proposed = array_values(array_filter($pins, static fn ($p) => $p['fingerprint'] !== $fp));
			$proposed[] = ['fingerprint' => $fp, 'from' => $from, 'until' => $until,
				'note' => (string)($input->getOption('note') ?? '')];
			$output->writeln('Nothing has been changed. Put this in the config file, '
				. 'e.g. config/sciencedata.config.php:');
			$output->writeln('');
			$output->writeln($this->block($proposed));
			return 0;
		}

		if ($pins === []) {
			$output->writeln('No authority is pinned: any token that chains to the CA is accepted.');
			$output->writeln('The list is the "pins" key of the timestamp_authority block in a config file.');
			return 0;
		}
		$output->writeln('Accepted timestamp authorities (timestamp_authority.pins):');
		foreach ($pins as $p) {
			$output->writeln(sprintf('  %s  %s .. %s  %s', $p['fingerprint'],
				$p['from'] !== '' ? $p['from'] : 'always',
				$p['until'] !== '' ? $p['until'] : 'onwards',
				$p['note']));
		}
		return 0;
	}

	/**
	 * The whole list as a block to paste. Printed, never written.
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
