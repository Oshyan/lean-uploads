<?php
/**
 * Bulk conversion of the existing library, driven in batches from the admin (AJAX) or from WP-CLI.
 *
 * State lives in one option so a run can be paused, resumed after a closed tab, or reset. Each batch converts
 * a few attachments within a time budget and reports progress; every conversion is the same transactional
 * Library::convert_attachment() the Media Library actions use.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Bulk {

	const OPTION     = 'lean_uploads_bulk';
	const LOCK       = 'lean_uploads_bulk_lock';
	const AJAX       = 'lean_uploads_bulk';
	const CAP        = 'manage_options';
	const BATCH      = 5;
	const BUDGET_SEC = 20;
	/** A lock older than this is considered abandoned (a request that died mid-batch). */
	const LOCK_STALE_SEC = 180;

	/** @var Library */
	private $library;

	public function __construct( Library $library ) {
		$this->library = $library;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax' ) );
	}

	/* ------------------------------------------------------------------ state */

	/** @return array<string,mixed> */
	public static function blank_state(): array {
		return array(
			'status'  => 'idle', // idle | running | paused | done
			'ids'     => array(),
			'cursor'  => 0,
			'total'   => 0,
			'done'    => 0,
			'skipped' => 0,
			'failed'  => 0,
			'saved'   => 0,
			'rewrite' => true,
			'dry'     => false,
			'started' => 0,
			'updated' => 0,
			'in_flight' => 0, // attachment being processed when the state was last saved; non-zero after a crash
			'log'     => array(),
		);
	}

	/** @return array<string,mixed> */
	public function state(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::blank_state(), is_array( $s ) ? $s : array() );
	}

	/** @param array<string,mixed> $state */
	private function save( array $state ): void {
		$state['updated'] = time();
		$state['log']     = array_slice( (array) $state['log'], -40 );
		update_option( self::OPTION, $state, false );
	}

	/* ------------------------------------------------------------------ operations */

	/**
	 * What the library looks like right now: counts and bytes by what the plugin would do.
	 *
	 * @return array<string,array{count:int,bytes:int}>
	 */
	public function summary(): array {
		$out = array(
			'convert' => array( 'count' => 0, 'bytes' => 0 ),
			'resize'  => array( 'count' => 0, 'bytes' => 0 ),
			'skip'    => array( 'count' => 0, 'bytes' => 0 ),
			'missing' => array( 'count' => 0, 'bytes' => 0 ),
		);
		foreach ( $this->library->candidate_ids() as $id ) {
			$info = $this->library->inspect( $id );
			if ( is_wp_error( $info ) ) {
				++$out['missing']['count'];
				continue;
			}
			++$out[ $info['action'] ]['count'];
			$out[ $info['action'] ]['bytes'] += (int) $info['bytes'];
		}
		return $out;
	}

	/**
	 * @param bool       $rewrite Rewrite old URLs in content after each conversion.
	 * @param bool       $dry     Report only.
	 * @param int[]|null $ids     Attachments to process; null means every candidate in the library.
	 * @return array<string,mixed> New state.
	 */
	public function start( bool $rewrite, bool $dry, ?array $ids = null ): array {
		$state            = self::blank_state();
		$state['ids']     = null === $ids ? $this->library->candidate_ids() : array_values( array_map( 'intval', $ids ) );
		$state['total']   = count( $state['ids'] );
		$state['status']  = $state['total'] ? 'running' : 'done';
		$state['rewrite'] = $rewrite;
		$state['dry']     = $dry;
		$state['started'] = time();
		$this->save( $state );
		return $state;
	}

	public function pause(): array {
		$state = $this->state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			$this->save( $state );
		}
		return $state;
	}

	public function resume(): array {
		$state = $this->state();
		if ( 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			$this->save( $state );
		}
		return $state;
	}

	public function reset(): array {
		delete_option( self::OPTION );
		delete_option( self::LOCK );
		return self::blank_state();
	}

	/**
	 * Process the next few attachments. Safe to call repeatedly; stops when the status is not "running".
	 *
	 * Only one request at a time may run a batch (two tabs, or a reload plus Resume, would otherwise convert the
	 * same attachments concurrently). State is saved before and after every attachment, so a request that dies
	 * mid-way loses at most the one item it was on, and that item is reported as failed on the next step rather
	 * than retried forever.
	 *
	 * @return array<string,mixed> New state; 'busy' => true when another request holds the lock.
	 */
	public function step(): array {
		$state = $this->state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		if ( ! $this->lock() ) {
			$state['busy'] = true;
			return $state;
		}
		try {
			if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
				set_time_limit( self::BUDGET_SEC * 3 ); // headroom for one large image past the budget check
			}
			if ( ! empty( $state['in_flight'] ) ) {
				++$state['failed'];
				$state['log'][]     = sprintf( '#%d failed: the previous request did not finish it (time limit or fatal error)', (int) $state['in_flight'] );
				$state['in_flight'] = 0;
			}
			$deadline = microtime( true ) + self::BUDGET_SEC;
			$n        = 0;
			while ( $state['cursor'] < $state['total'] && $n < self::BATCH && microtime( true ) < $deadline ) {
				$id = (int) $state['ids'][ $state['cursor'] ];
				++$state['cursor'];
				++$n;
				$state['in_flight'] = $id;
				$this->save( $state );
				$this->process_one( $id, $state );
				$state['in_flight'] = 0;
				$this->save( $state );
			}
			if ( $state['cursor'] >= $state['total'] ) {
				$state['status'] = 'done';
				$state['ids']    = array(); // no need to keep the list around
			}
			$this->save( $state );
		} finally {
			$this->unlock();
		}
		return $state;
	}

	/** Take the batch lock; add_option() is atomic at the database (the row either gets inserted or it does not). */
	private function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK, 0 );
		if ( $held && time() - $held > self::LOCK_STALE_SEC ) {
			delete_option( self::LOCK ); // abandoned by a request that died
			return (bool) add_option( self::LOCK, time(), '', false );
		}
		return false;
	}

	private function unlock(): void {
		delete_option( self::LOCK );
	}

	/** @param array<string,mixed> $state Passed by reference and updated. */
	private function process_one( int $id, array &$state ): void {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			++$state['skipped'];
			$state['log'][] = "#$id skipped: no permission";
			return;
		}
		if ( $state['dry'] ) {
			$info = $this->library->inspect( $id, true );
			if ( is_wp_error( $info ) || 'skip' === $info['action'] ) {
				++$state['skipped'];
			} else {
				++$state['done'];
				$state['log'][] = sprintf( '#%d would %s %s (%s)', $id, $info['action'], basename( $info['path'] ), size_format( $info['bytes'] ) );
			}
			return;
		}
		$r = $this->library->convert_attachment( $id, (bool) $state['rewrite'] );
		if ( is_wp_error( $r ) ) {
			if ( 'skip' === $r->get_error_code() ) {
				++$state['skipped'];
				$state['log'][] = "#$id skipped: " . $r->get_error_message();
			} else {
				++$state['failed'];
				$state['log'][] = "#$id failed: " . $r->get_error_message();
			}
			return;
		}
		++$state['done'];
		$state['saved'] += max( 0, $r['bytes_before'] - $r['bytes_after'] );
		$state['log'][]  = sprintf( '#%d %s -> %s (%s -> %s)', $id, $r['old_name'], $r['new_name'], size_format( $r['bytes_before'] ), size_format( $r['bytes_after'] ) );
	}

	/* ------------------------------------------------------------------ AJAX */

	public function ajax(): void {
		check_ajax_referer( self::AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		switch ( $op ) {
			case 'summary':
				wp_send_json_success( array( 'summary' => $this->summary(), 'state' => $this->public_state( $this->state() ) ) );
				break;
			case 'start':
				$state = $this->start( ! empty( $_POST['rewrite'] ), ! empty( $_POST['dry'] ) );
				wp_send_json_success( array( 'state' => $this->public_state( $state ) ) );
				break;
			case 'step':
				$state = $this->step();
				if ( ! empty( $state['busy'] ) ) {
					wp_send_json_error( array( 'message' => 'busy' ), 409 );
				}
				wp_send_json_success( array( 'state' => $this->public_state( $state ) ) );
				break;
			case 'pause':
				wp_send_json_success( array( 'state' => $this->public_state( $this->pause() ) ) );
				break;
			case 'resume':
				wp_send_json_success( array( 'state' => $this->public_state( $this->resume() ) ) );
				break;
			case 'reset':
				wp_send_json_success( array( 'state' => $this->public_state( $this->reset() ) ) );
				break;
			default:
				wp_send_json_error( array( 'message' => 'unknown op' ), 400 );
		}
	}

	/** The state without the ID list (which can be large and is of no use to the page). */
	private function public_state( array $state ): array {
		unset( $state['ids'], $state['busy'] );
		return $state;
	}
}
