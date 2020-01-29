<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2009 Karel Klima
 * @copyright Copyright (c) 2010 Ondřej Vodáček
 * @license New BSD License
 */

namespace Vodacek\GettextExtractor;

use Nette\Utils\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Vodacek\GettextExtractor\Filters\IFilter;

class Extractor {

	private const ESCAPE_CHARS = '"\\';

	public const CONTEXT = 'context';
	public const SINGULAR = 'singular';
	public const PLURAL = 'plural';
	public const LINE = 'line';
	public const FILE = 'file';

	/** @var string */
	protected $logFile;

	/** @var array */
	protected $inputFiles = array();

	/** @var array */
	protected $filters = array(
		'php' => array('PHP')
	);

	/** @var array */
	protected $filterStore = array();

	/** @var array */
	protected $comments = array(
		'Gettext keys exported by GettextExtractor'
	);

	/** @var array */
	protected $meta = array(
		'POT-Creation-Date' => '',
		'PO-Revision-Date' => 'YEAR-MO-DA HO:MI+ZONE',
		'Last-Translator' => 'FULL NAME <EMAIL@ADDRESS>',
		'Language-Team' => 'LANGUAGE <LL@li.org>',
		'MIME-Version' => '1.0',
		'Content-Type' => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'Plural-Forms' => 'nplurals=INTEGER; plural=EXPRESSION;'
	);

	/** @var array */
	protected $data = array();

	public function __construct(string $logFile = 'php://stderr') {
		$this->logFile = $logFile;
		$this->addFilter('PHP', new Filters\PHPFilter());
		$this->setMeta('POT-Creation-Date', date('c'));
	}

	/**
	 * Writes messages into log or dumps them on screen
	 *
	 * @param string $message
	 */
	public function log(string $message): void {
		if ($this->logFile !== '') {
			file_put_contents($this->logFile, "$message\n", FILE_APPEND);
		}
	}

	protected function throwException(string $message): void {
		$message = $message ?: 'Something unexpected occured. See GettextExtractor log for details';
		$this->log($message);
		throw new RuntimeException($message);
	}

	/**
	 * Scans given files or directories and extracts gettext keys from the content
	 *
	 * @param string|string[] $resource
	 * @return self
	 */
	public function scan($resource): self {
		$this->inputFiles = array();
		if (!is_array($resource)) {
			$resource = array($resource);
		}
		foreach ($resource as $item) {
			$this->log("Scanning '$item'");
			$this->_scan($item);
		}
		$this->_extract($this->inputFiles);
		return $this;
	}

	/**
	 * Scans given files or directories (recursively)
	 *
	 * @param string $resource File or directory
	 */
	private function _scan(string $resource): void {
		if (is_file($resource)) {
			$this->inputFiles[] = $resource;
		} elseif (is_dir($resource)) {
			$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($resource, RecursiveDirectoryIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				$this->inputFiles[] = $file->getPathName();
			}
		} else {
			$this->throwException("Resource '$resource' is not a directory or file");
		}
	}

	/**
	 * Extracts gettext keys from input files
	 *
	 * @param string[] $inputFiles
	 * @return array
	 */
	private function _extract(array $inputFiles): array {
		$inputFiles = array_unique($inputFiles);
		sort($inputFiles);
		foreach ($inputFiles as $inputFile) {
			if (!file_exists($inputFile)) {
				$this->throwException('ERROR: Invalid input file specified: '.$inputFile);
			}
			if (!is_readable($inputFile)) {
				$this->throwException('ERROR: Input file is not readable: '.$inputFile);
			}

			$this->log('Extracting data from file '.$inputFile);

			$fileExtension = pathinfo($inputFile, PATHINFO_EXTENSION);
			if (isset($this->filters[$fileExtension])) {
				$this->log('Processing file '.$inputFile);

				foreach ($this->filters[$fileExtension] as $filterName) {
					$filter = $this->getFilter($filterName);
					$filterData = $filter->extract($inputFile);
					$this->log('  Filter '.$filterName.' applied');
					$this->addMessages($filterData, $inputFile);
				}
			}
		}
		return $this->data;
	}

	public function getFilter(string $filterName): IFilter {
		if (isset($this->filterStore[$filterName])) {
			return $this->filterStore[$filterName];
		}
		$this->throwException("ERROR: Filter '$filterName' not found.");
	}

	/**
	 * Assigns a filter to an extension
	 *
	 * @param string $extension
	 * @param string $filterName
	 * @return self
	 */
	public function setFilter(string $extension, string $filterName): self {
		if (!isset($this->filters[$extension]) || !in_array($filterName, $this->filters[$extension], true)) {
			$this->filters[$extension][] = $filterName;
		}
		return $this;
	}

	/**
	 * Add a filter object
	 *
	 * @param string $filterName
	 * @param IFilter $filter
	 * @return self
	 */
	public function addFilter(string $filterName, IFilter $filter): self {
		$this->filterStore[$filterName] = $filter;
		return $this;
	}

	/**
	 * Removes all filter settings in case we want to define a brand new one
	 *
	 * @return self
	 */
	public function removeAllFilters(): self {
		$this->filters = array();
		return $this;
	}

	/**
	 * Adds a comment to the top of the output file
	 *
	 * @param string $value
	 * @return self
	 */
	public function addComment(string $value): self {
		$this->comments[] = $value;
		return $this;
	}

	/**
	 * Gets a value of a meta key
	 *
	 * @param string $key
	 * @return string|null
	 */
	public function getMeta(string $key): ?string {
		return $this->meta[$key] ?? null;
	}

	/**
	 * Sets a value of a meta key
	 *
	 * @param string $key
	 * @param string $value
	 * @return self
	 */
	public function setMeta(string $key, string $value): self {
		$this->meta[$key] = $value;
		return $this;
	}

	/**
	 * Saves extracted data into gettext file
	 *
	 * @param string $outputFile
	 * @param array $data
	 * @return self
	 */
	public function save(string $outputFile, array $data = null): self {
		FileSystem::write($outputFile, $this->formatData($data ?: $this->data));
		return $this;
	}

	/**
	 * Formats fetched data to gettext syntax
	 *
	 * @param array $data
	 * @return string
	 */
	private function formatData(array $data): string {
		$output = array();
		foreach ($this->comments as $comment) {
			$output[] = '# '.$comment;
		}
		$output[] = '#, fuzzy';
		$output[] = 'msgid ""';
		$output[] = 'msgstr ""';
		foreach ($this->meta as $key => $value) {
			$output[] = '"'.$key.': '.$value.'\n"';
		}
		$output[] = '';

		foreach ($data as $message) {
			foreach ($message['files'] as $file) {
				$output[] = '#: '.$file[self::FILE].':'.$file[self::LINE];
			}
			if (isset($message[self::CONTEXT])) {
				$output[] = $this->formatMessage($message[self::CONTEXT], 'msgctxt');
			}
			$output[] = $this->formatMessage($message[self::SINGULAR], 'msgid');
			if (isset($message[self::PLURAL])) {
				$output[] = $this->formatMessage($message[self::PLURAL], 'msgid_plural');
				$output[] = 'msgstr[0] ""';
				$output[] = 'msgstr[1] ""';
			} else {
				$output[] = 'msgstr ""';
			}

			$output[] = '';
		}

		return implode("\n", $output);
	}

	private function addMessages(array $messages, string $file): void {
		foreach ($messages as $message) {
			$key = '';
			if (isset($message[self::CONTEXT])) {
				$key .= $message[self::CONTEXT];
			}
			$key .= chr(4);
			$key .= $message[self::SINGULAR];
			$key .= chr(4);
			if (isset($message[self::PLURAL])) {
				$key .= $message[self::PLURAL];
			}
			if ($key === chr(4).chr(4)) {
				continue;
			}
			$line = $message[self::LINE];
			if (!isset($this->data[$key])) {
				unset($message[self::LINE]);
				$this->data[$key] = $message;
				$this->data[$key]['files'] = array();
			}
			$this->data[$key]['files'][] = array(
				self::FILE => $file,
				self::LINE => $line
			);
		}
	}

	private function formatMessage(string $message, string $prefix = null): string {
		$message = addcslashes($message, self::ESCAPE_CHARS);
		$message = '"' . str_replace("\n", "\\n\"\n\"", $message) . '"';
		return ($prefix !== null ? $prefix.' ' : '') . $message;
	}
}
