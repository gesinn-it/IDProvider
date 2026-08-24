<?php
/**
 * MediaWiki IDProvider Extension
 *
 * Provides (unique) IDs using different ID algorithms.
 *
 * @link https://github.com/gesinn-it/IDProvider
 *
 * @author gesinn.it GmbH & Co. KG
 * @license MIT
 */

/**
 * Hooks for IDProvider extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\IdProvider;

use DatabaseUpdater;
use Parser;
use PPFrame;

class Hooks {

	/**
	 * Adds extension specific unit-tests
	 *
	 * @param array &$files
	 * @return bool
	 */
	public static function onUnitTestsList( array &$files ): bool {
		$files = array_merge( $files, glob( __DIR__ . '/../tests/phpunit/*/*Test.php' ) );
		$files = array_merge( $files, glob( __DIR__ . '/../tests/phpunit/*/*/*Test.php' ) );

		return true;
	}

	/**
	 * Registers the database schema additions
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ): bool {
		$updater->addExtensionTable( 'idprovider_increments',
			__DIR__ . '/../sql/CreateIncrementTable.sql' );

		$updater->modifyExtensionField( 'idprovider_increments', 'prefix',
			__DIR__ . '/../sql/PatchPrefixField.sql' );
		$updater->addExtensionUpdate( [ [ self::class, 'mergeDuplicateIncrementPrefixes' ] ] );
		$updater->addExtensionIndex( 'idprovider_increments', 'idprovider_increments_prefix',
			__DIR__ . '/../sql/PatchPrefixUniqueIndex.sql' );

		return true;
	}

	/**
	 * Merges rows in idprovider_increments that share the same prefix (which could only
	 * have happened due to the race condition fixed alongside the UNIQUE index migration),
	 * keeping the highest increment value so no previously issued ID is reused.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $tableName
	 * @return void
	 */
	public static function mergeDuplicateIncrementPrefixes(
		DatabaseUpdater $updater, string $tableName = 'idprovider_increments'
	): void {
		$fname = __METHOD__;
		$dbw = $updater->getDB();
		$duplicates = $dbw->select(
			$tableName,
			[ 'prefix', 'maxIncrement' => 'MAX(increment)', 'minPid' => 'MIN(pid)' ],
			[],
			$fname,
			[ 'GROUP BY' => 'prefix', 'HAVING' => 'COUNT(*) > 1' ]
		);

		foreach ( $duplicates as $duplicate ) {
			$dbw->update(
				$tableName,
				[ 'increment' => (int)$duplicate->maxIncrement ],
				[ 'pid' => $duplicate->minPid ],
				$fname
			);
			$dbw->delete(
				$tableName,
				[
					'prefix' => $duplicate->prefix,
					'pid != ' . $dbw->addQuotes( $duplicate->minPid ),
				],
				$fname
			);
		}
	}

	/**
	 * Register parser hooks
	 * See also http://www.mediawiki.org/wiki/Manual:Parser_functions
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( $parser ): bool {
		// Register parser functions
		$parser->setFunctionHook( 'idprovider-increment',
			'MediaWiki\Extension\IdProvider\Hooks::incrementFunctionHook' );
		$parser->setFunctionHook( 'idprovider-random',
			'MediaWiki\Extension\IdProvider\Hooks::randomFunctionHook' );

		return true;
	}

	/**
	 * Wrapper for the {{#idprovider-increment}} parser function
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return array
	 * @throws \Exception
	 */
	public static function incrementFunctionHook( $parser, $frame ): array {
		$args = array_slice( func_get_args(), 2 );
		$params = self::extractOptions( $args );

		// If the prefix is not set as key-value, but the first parameter is set,
		// use it as prefix (short form)
		if ( $frame ) {
			$params['prefix'] = $frame;
		}

		$id = IdProviderFactory::increment( $params )->getId( $params );

		// Remove "mw-parser-output" wrapper for mw >= 1.30
		$opt = $parser->getOptions();
		if ( method_exists( $opt, 'setOption' ) ) {
			$opt->setOption( 'wrapclass', false );
		}

		return [ $id, 'noparse' => true ];
	}

	/**
	 * Wrapper for the {{#idprovider-random}} parser function
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return array
	 */
	public static function randomFunctionHook( $parser, $frame ): array {
		$args = array_slice( func_get_args(), 2 );
		$params = self::extractOptions( $args );

		// If the prefix is not set as key-value, but the first parameter is set
		// Use it as prefix (short form)
		if ( !isset( $params['type'] ) && $frame ) {
			$params['type'] = $frame;
		}

		$id = IdProviderFactory::random( $params )->getId( $params );

		// Remove "mw-parser-output" wrapper for mw >= 1.30
		$opt = $parser->getOptions();
		if ( method_exists( $opt, 'setOption' ) ) {
			$opt->setOption( 'wrapclass', false );
		}

		return [ $id, 'noparse' => true ];
	}

	/**
	 * Converts an array of values in form [0] => "name=value" into a real
	 * associative array in form [name] => value
	 *
	 * @param array $options
	 * @return array
	 */
	private static function extractOptions( array $options ): array {
		$results = [];

		foreach ( $options as $option ) {
			$pair = explode( '=', $option, 2 );
			if ( count( $pair ) == 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				$results[$name] = $value;
			}
		}

		return $results;
	}
}
