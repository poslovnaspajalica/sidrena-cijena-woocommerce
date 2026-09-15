<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: wp sidrena snapshot [--overwrite] | wp sidrena export | wp sidrena status
 */
final class SC_CLI {

	/**
	 * Zabilježi sidrene cijene (kopira redovnu cijenu) za sve proizvode bez sidrene.
	 *
	 * ## OPTIONS
	 * [--overwrite]
	 * : Prepiši i postojeće sidrene cijene.
	 */
	public function snapshot( array $args, array $assoc ): void {
		$r = SC_Snapshot::run_full( ! empty( $assoc['overwrite'] ) );
		WP_CLI::success( sprintf( 'Zabilježeno: %d, preskočeno (već postoji): %d', $r['written'], $r['skipped'] ) );
	}

	/** Generiraj cjenik (.csv i .xml) sada. */
	public function export(): void {
		$r = SC_Export::run_background( 'cli' );
		if ( $r === null ) {
			WP_CLI::error( 'Već je u tijeku drugo generiranje.' );
		}
		WP_CLI::success( sprintf( 'Cjenik generiran: %s / %s (%d redaka, %d bez sidrene, %ds)', $r['csv'], $r['xml'], $r['rows'], $r['missing'], $r['seconds'] ) );
	}

	/** Status. */
	public function status(): void {
		$st   = SC_Snapshot::stats();
		$last = SC_Export::last();
		WP_CLI::line( sprintf( 'Proizvodi s cijenom: %d, sa sidrenom: %d, bez: %d', $st['total'], $st['with'], $st['without'] ) );
		WP_CLI::line( $last ? sprintf( 'Zadnji export: %s (%d redaka)', wp_date( 'd.m.Y. H:i', (int) $last['time'] ), $last['rows'] ) : 'Export još nije pokrenut.' );
		$next = wp_next_scheduled( SC_Export::CRON_HOOK );
		WP_CLI::line( $next ? 'Sljedeći cron: ' . wp_date( 'd.m.Y. H:i', $next ) : 'Cron nije zakazan.' );
	}
}
