<?php

/**
 * Because php 5.3.3 is terrible
 */
class ImportHelper {
	
	/**
	 * Determine if $className is a subclass of $baseName
	 *
	 * Because is_a doesn't work in php 5.3.3 with strings
	 *
	 * @param string $className
	 * @param string $baseName
	 * @return boolean
	 */
	public static function is_a($className, $baseName) {
		return (strcasecmp($className, $baseName) === 0) || is_subclass_of($className, $baseName);
	}
}
