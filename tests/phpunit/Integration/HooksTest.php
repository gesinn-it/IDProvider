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

namespace Tests\Integration;

use DatabaseUpdater;
use MediaWiki\Extension\IdProvider\Hooks;
use MediaWikiIntegrationTestCase;
use ParserOptions;
use Title;

/**
 * @group IDProvider
 * @group Database
 * @group medium
 *
 * @covers \MediaWiki\Extension\IdProvider\Hooks
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	private function parse( string $wikitext ): string {
		$parserOutput = $this->getServiceContainer()->getParser()->parse(
			$wikitext,
			Title::makeTitle( NS_MAIN, 'IDProviderHooksTest' ),
			ParserOptions::newFromAnon()
		);

		return version_compare( MW_VERSION, '1.42', '>=' )
			? $parserOutput->getRawText()
			: $parserOutput->getText();
	}

	/**
	 * assertMatchesRegularExpression() replaced the deprecated assertRegExp() in PHPUnit 9.1;
	 * assertRegExp() is what the extension's minimum supported MediaWiki version (1.39, which
	 * bundles PHPUnit 8.5) provides.
	 */
	private function assertMatchesRegex( string $pattern, string $string ): void {
		if ( method_exists( $this, 'assertMatchesRegularExpression' ) ) {
			$this->assertMatchesRegularExpression( $pattern, $string );
		} else {
			$this->assertRegExp( $pattern, $string );
		}
	}

	public function testIncrementFunctionHookRegistersParserFunction() {
		$html = $this->parse( '{{#idprovider-increment:prefix=IDPTestHooksInc|padding=4}}' );

		$this->assertMatchesRegex( '/IDPTestHooksInc\d{4}/', $html );
	}

	public function testIncrementFunctionHookUsesShortFormPrefix() {
		$html = $this->parse( '{{#idprovider-increment:IDPTestHooksShort|padding=3}}' );

		$this->assertMatchesRegex( '/IDPTestHooksShort\d{3}/', $html );
	}

	public function testRandomFunctionHookRegistersParserFunction() {
		$html = $this->parse( '{{#idprovider-random:type=uuid|prefix=IDPTestHooksRand}}' );

		$this->assertStringContainsString( 'IDPTestHooksRand', $html );
	}

	public function testRandomFunctionHookUsesShortFormType() {
		$html = $this->parse( '{{#idprovider-random:fakeid}}' );

		$this->assertNotSame( '', trim( $html ) );
	}

	public function testOnUnitTestsListAddsExtensionTestFiles() {
		$files = [];
		$result = Hooks::onUnitTestsList( $files );

		$this->assertTrue( $result );
		$this->assertNotEmpty( $files );
	}

	public function testOnLoadExtensionSchemaUpdatesRegistersTable() {
		$updater = $this->getMockBuilder( \DatabaseUpdater::class )
			->disableOriginalConstructor()
			->getMock();
		$updater->expects( $this->once() )
			->method( 'addExtensionTable' )
			->with( 'idprovider_increments', $this->stringContains( 'CreateIncrementTable.sql' ) );

		$result = Hooks::onLoadExtensionSchemaUpdates( $updater );

		$this->assertTrue( $result );
	}

	/**
	 * mergeDuplicateIncrementPrefixes() only has real duplicate rows to merge on an
	 * installation upgrading from before the UNIQUE index migration, which the current
	 * idprovider_increments schema no longer allows. A throwaway table without that
	 * constraint reproduces the pre-migration situation instead.
	 */
	public function testMergeDuplicateIncrementPrefixesKeepsHighestIncrement() {
		$dbw = method_exists( $this, 'getDb' ) ? $this->getDb() : $this->db;
		$prefix = 'IDPTestMerge' . rand( 0, 999999 );
		$tableName = 'idprovider_increments_premigration_test';
		$qualifiedTableName = $dbw->tableName( $tableName );

		$dbw->query( "DROP TEMPORARY TABLE IF EXISTS $qualifiedTableName", __METHOD__ );
		$dbw->query(
			"CREATE TEMPORARY TABLE $qualifiedTableName ( " .
				'pid int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT, ' .
				'prefix varbinary(255) NOT NULL DEFAULT \'\', ' .
				'increment int unsigned NOT NULL default 0 )',
			__METHOD__
		);
		$dbw->insert( $tableName, [ 'prefix' => $prefix, 'increment' => 3 ], __METHOD__ );
		$dbw->insert( $tableName, [ 'prefix' => $prefix, 'increment' => 7 ], __METHOD__ );

		$updater = $this->getMockBuilder( \DatabaseUpdater::class )
			->disableOriginalConstructor()
			->getMock();
		$updater->method( 'getDB' )->willReturn( $dbw );

		Hooks::mergeDuplicateIncrementPrefixes( $updater, $tableName );

		$rows = $dbw->select( $tableName, [ 'increment' ], [ 'prefix' => $prefix ], __METHOD__ );
		$values = array_map( static fn ( $row ) => (int)$row->increment, iterator_to_array( $rows ) );

		$this->assertSame( [ 7 ], $values );

		$dbw->query( "DROP TEMPORARY TABLE $qualifiedTableName", __METHOD__ );
	}

	/**
	 * Reads back the update entries DatabaseUpdater::addExtensionUpdate() queued into its
	 * private $extensionUpdates array, so a test can drive exactly what
	 * Hooks::onLoadExtensionSchemaUpdates() registered instead of hardcoding method names
	 * that would drift out of sync with the production hook.
	 *
	 * @param DatabaseUpdater $updater
	 * @return array
	 */
	private function getRegisteredExtensionUpdates( DatabaseUpdater $updater ): array {
		$reflection = new \ReflectionMethod( DatabaseUpdater::class, 'getExtensionUpdates' );
		$reflection->setAccessible( true );

		return $reflection->invoke( $updater );
	}

	/**
	 * Runs one extension-schema-update entry (as registered by
	 * Hooks::onLoadExtensionSchemaUpdates) via DatabaseUpdater's protected
	 * addField()/modifyField()/addIndex() methods, exactly as MediaWiki core's
	 * private runUpdates() would dispatch it.
	 *
	 * @param DatabaseUpdater $updater
	 * @param array $update Entry as queued by addExtension{Field,Index,ModifyField}()
	 */
	private function runExtensionUpdate( DatabaseUpdater $updater, array $update ): void {
		$method = array_shift( $update );
		if ( is_array( $method ) ) {
			// addExtensionUpdate() callback entry, e.g. [ [ Hooks::class, 'mergeDuplicateIncrementPrefixes' ] ].
			array_unshift( $update, $updater );
			( $method )( ...$update );
			return;
		}
		$reflection = new \ReflectionMethod( DatabaseUpdater::class, $method );
		$reflection->setAccessible( true );
		$reflection->invokeArgs( $updater, $update );
	}

	/**
	 * Reproduces https://github.com/gesinn-it-pub/IDProvider/issues/132: on a wiki
	 * upgrading from a pre-3.0 install, idprovider_increments.prefix already exists as a
	 * nullable BLOB column. addExtensionField() only checks column *existence*, so
	 * PatchPrefixField.sql (which converts it to varbinary(255) NOT NULL) is skipped, and
	 * the later UNIQUE index creation then fails with MySQL error 1170 ("BLOB/TEXT column
	 * ... used in key specification without a key length").
	 *
	 * The patch files hardcode the real `idprovider_increments` table name (they are run by
	 * update.php against the live table, never a caller-supplied one), so the pre-existing
	 * blob column is reproduced directly on that table rather than a throwaway one, and the
	 * original schema/data is restored afterwards.
	 */
	public function testSchemaUpdateMigratesPreExistingBlobPrefixColumnBeforeAddingUniqueIndex() {
		$dbw = method_exists( $this, 'getDb' ) ? $this->getDb() : $this->db;
		$tableName = 'idprovider_increments';
		$qualifiedTableName = $dbw->tableName( $tableName );
		$indexName = 'idprovider_increments_prefix';

		$rows = iterator_to_array(
			$dbw->select( $tableName, [ 'pid', 'prefix', 'increment' ], [], __METHOD__ ),
			false
		);

		$dbw->query( "ALTER TABLE $qualifiedTableName DROP INDEX $indexName", __METHOD__ );
		// Mirrors the pre-3.0 schema confirmed on the affected wiki via
		// `SHOW CREATE TABLE idprovider_increments` in the issue report.
		$dbw->query( "ALTER TABLE $qualifiedTableName MODIFY COLUMN prefix blob", __METHOD__ );
		$dbw->delete( $tableName, '*', __METHOD__ );
		$dbw->insert( $tableName, [ 'prefix' => 'IDPTestLegacy', 'increment' => 5 ], __METHOD__ );

		$updater = DatabaseUpdater::newForDB( $dbw );
		Hooks::onLoadExtensionSchemaUpdates( $updater );

		try {
			foreach ( $this->getRegisteredExtensionUpdates( $updater ) as $update ) {
				$this->runExtensionUpdate( $updater, $update );
			}

			$this->assertTrue(
				$dbw->indexExists( $tableName, $indexName, __METHOD__ ),
				'UNIQUE index should have been created after migrating the legacy blob column'
			);
		} finally {
			if ( !$dbw->indexExists( $tableName, $indexName, __METHOD__ ) ) {
				$dbw->query(
					"CREATE UNIQUE INDEX $indexName ON $qualifiedTableName (prefix)",
					__METHOD__
				);
			}
			$dbw->query(
				"ALTER TABLE $qualifiedTableName MODIFY COLUMN prefix varbinary(255) NOT NULL DEFAULT ''",
				__METHOD__
			);
			$dbw->delete( $tableName, '*', __METHOD__ );
			foreach ( $rows as $row ) {
				$dbw->insert( $tableName,
					[ 'pid' => $row->pid, 'prefix' => $row->prefix, 'increment' => $row->increment ],
					__METHOD__
				);
			}
		}
	}
}
