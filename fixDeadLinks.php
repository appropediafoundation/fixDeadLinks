<?php

/**
 * This maintenance script replaces dead links for the latest archive.org snapshot
 */

require_once __DIR__ . '/w/maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class FixDeadLinksScript extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Replace dead links for the latest archive.org snapshot' );
		$this->addOption( 'offset', '', false, true );
	}

	public function execute() {

		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbm = $lb->getConnectionRef( DB_MASTER );
		$res = $dbm->select( 'externallinks', [ 'el_from', 'el_to' ] );

		$offset = $this->getOption( 'offset', 0 );
		foreach ( $res as $k => $row ) {
			if ( $k < $offset ) continue; 

			// Check the URL response code
			$url = $row->el_to;
			$curl = curl_init( $url );
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $curl, CURLOPT_NOBODY, true );
			curl_setopt( $curl, CURLOPT_TIMEOUT_MS, 9999 );
			curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT_MS, 9999 );
			curl_exec( $curl );
			$httpCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
			curl_close( $curl );
			if ( !in_array( $httpCode, [ 0, 403, 404, 410 ] ) ) {
				continue;
			}

			// Output where we're at
			$this->output( $k . ' ' . $url . ' ' . $httpCode );

			// Don't double-archive dead archive.org links
			if ( preg_match( '@https?://web\.archive\.org/web/\d+/(.+)@', $url, $matches ) ) {
				$url = $matches[1];
			}

			// Get the archived link
			sleep( 1 ); // Don't overload archive.org
			$curl = curl_init();
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $curl, CURLOPT_URL, 'https://archive.org/wayback/available?url=' . urlencode( $url ) );
			$json = curl_exec( $curl );
			curl_close( $curl );
			if ( !$json ) {
				$this->output( ' .. archive.org returned no JSON' . PHP_EOL );
				continue;
			}
			$json = json_decode( $json );
			if ( empty( $json->archived_snapshots ) or empty( $json->archived_snapshots->closest ) ) {
				$this->output( ' .. archive.org returned no snapshots' . PHP_EOL );
				continue;
			}
			$archived = $json->archived_snapshots->closest->url;
			if ( !trim( $archived ) ) {
				$this->output( ' .. archive.org returned no closest URL' . PHP_EOL );
				continue;
			}

			// Get the content of the page
			$id = $row->el_from;
			$Title = Title::newFromID( $id );
			if ( !$Title->exists() ) {
				$this->output( ' .. title does not exist' . PHP_EOL );
				continue;
			}
			$Page = WikiPage::factory( $Title );
			$Revision = $Page->getRevision();
			$Content = $Revision->getContent( Revision::RAW );
			if ( $Title->isRedirect() ) {
				$Title = $Content->getRedirectTarget();
				if ( !$Title->exists() ) {
					$this->output( ' .. redirect target does not exist' . PHP_EOL );
					continue;
				}
				$Page = WikiPage::factory( $Title );
				$Revision = $Page->getRevision();
				$Content = $Revision->getContent( Revision::RAW );
			}
			$text = ContentHandler::getContentText( $Content );

			// Replace the dead URL
			if ( strpos( $text, $row->el_to ) === false ) {
				$this->output( ' .. URL not found in the wikitext' . PHP_EOL );
				continue;
			}
			$text = str_replace( $row->el_to, $archived, $text );

			// Save the page
			$Content = ContentHandler::makeContent( $text, $Title );
			$User = User::newSystemUser( 'Dead links script' );
			$Updater = $Page->newPageUpdater( $User );
			$Updater->setContent( 'main', $Content );
			$Updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Replace dead link for archived version' ), EDIT_SUPPRESS_RC );

			$this->output( ' .. fixed!' . PHP_EOL );
		}
	}
}

$maintClass = FixDeadLinksScript::class;
require_once RUN_MAINTENANCE_IF_MAIN;
