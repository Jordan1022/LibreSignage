<?php

namespace libresignage\common\php\exportable\migration;

use libresignage\common\php\exportable\migration\exceptions\MigrationException;
use libresignage\common\php\JSONUtils;
use libresignage\common\php\Util;

/**
* A class representing a transformation index.
*/
final class MigrationIndex {
	/**
	* Construct a new MigrationIndex.
	*/
	public function __construct() {
		$this->index = NULL;
	}

	/**
	* Load the transformation index.
	*
	* @param string $path The filepath of the index file.
	*
	* @return array The transformation index as a associative array.
	*
	* @throws MigrationException If the index file doesn't exist.
	*/
	public function load(string $file) {
		if (!is_file($file)) {
			throw new MigrationException(
				"Migration index missing!"
			);
		}

		$tmp = Util::file_lock_and_get($file);
		
		$index = [];
		foreach (JSONUtils::decode($tmp, $assoc=TRUE) as $data) {
			array_push($index, new MigrationIndexEntry(
				$data['from'],
				$data['to'],
				$data['fqcn'],
				$data['data_fqcn']
			));
		}
		self::sort_index($index);
		
		$this->index = $index;
	}

	/**
	* Get a transformation index entry for a data version for a class.
	*
	* @param string $fqcn The fully-qualified classname.
	* @param string $from The origin version.
	*
	* @return MigrationIndexEntry|NULL The corresponding entry or NULL
	*                                  if not found.
	*/
	public function get(string $fqcn, string $from) {
		foreach ($this->index as $t) {
			if ($t->transforms($fqcn, $from)) {
				return $t;
			}
		}
		return NULL;
	}
	
	/**
	* Sort a transformation index by the keys, ie. version numbers.
	*
	* @return bool TRUE on success or FALSE on failure. 
	*/
	public static function sort_index(array &$index) {
		return uksort($index, function ($a, $b) {
			$a_split = explode('.', $a);
			$b_split = explode('.', $b);
			assert(count($a_split) == count($b_split));
			
			for ($i = 0; $i < count($a_split); $i++) {
				if ($a_split[$i] < $b_split[$i]) {
					return -1;
				} else if ($a_split[$i] > $b_split[$i]) {
					return 1;
				}
			}
			return 0;
		});
	}
}