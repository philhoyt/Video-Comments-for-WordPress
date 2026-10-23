<?php
/**
 * Background deletion and the orphan sweep.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Cleanup
 */
class Test_Video_Comments_Cleanup extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	private Video_Comments_Cleanup $cleanup;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
		$this->cleanup = Video_Comments_Cleanup::get_instance();
	}

	public function test_sweep_is_scheduled_on_init(): void {
		wp_unschedule_hook( Video_Comments_Cleanup::SWEEP_HOOK );
		Video_Comments_Cleanup::schedule_sweep();
		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::SWEEP_HOOK ) );
		Video_Comments_Cleanup::schedule_sweep();
		$this->assertCount( 1, array_filter( _get_cron_array(), fn( $hooks ) => isset( $hooks[ Video_Comments_Cleanup::SWEEP_HOOK ] ) ), 'not scheduled twice' );
	}

	public function test_run_delete_asset_success_does_not_reschedule(): void {
		$this->mux_assets['asset_a'] = [
			'status'      => 'ready',
			'playback_id' => 'pb',
		];
		$this->cleanup->run_delete_asset( 'asset_a', 1 );
		$this->assertArrayNotHasKey( 'asset_a', $this->mux_assets );
		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_a', 2 ] ) );
	}

	public function test_run_delete_asset_retries_with_backoff_on_failure(): void {
		$this->mux_fail['DELETE'] = 500;
		$this->cleanup->run_delete_asset( 'asset_b', 1 );
		$next = wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_b', 2 ] );
		$this->assertNotFalse( $next );
		$this->assertGreaterThanOrEqual( time() + 100, $next, 'second attempt is delayed' );
	}

	public function test_run_delete_asset_gives_up_after_max_attempts(): void {
		$this->mux_fail['DELETE'] = 500;
		$this->cleanup->run_delete_asset( 'asset_c', 5 );
		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_c', 6 ] ) );
	}

	public function test_run_delete_asset_treats_404_as_done(): void {
		$this->cleanup->run_delete_asset( 'asset_gone', 1 );
		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_gone', 2 ] ) );
	}

	public function test_sweep_deletes_abandoned_upload_asset(): void {
		[ $upload_id, $asset_id ] = $this->mux_seed_ready_upload( 'uplOld', 'assetOld', 'pbOld' );
		update_option( Video_Comments_Cleanup::PENDING_OPTION, [ $upload_id => time() - 2 * DAY_IN_SECONDS ] );

		$this->cleanup->sweep_uploads();

		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ $asset_id, 1 ] ) );
		$this->assertSame( [], get_option( Video_Comments_Cleanup::PENDING_OPTION ) );
	}

	public function test_sweep_keeps_asset_referenced_by_a_comment(): void {
		[ $upload_id, $asset_id ] = $this->mux_seed_ready_upload( 'uplUsed', 'assetUsed', 'pbUsed' );
		$cid                      = self::factory()->comment->create();
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, $asset_id, true );
		wp_trash_comment( $cid ); // Even a trashed comment keeps its asset.
		update_option( Video_Comments_Cleanup::PENDING_OPTION, [ $upload_id => time() - 2 * DAY_IN_SECONDS ] );

		$this->cleanup->sweep_uploads();

		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ $asset_id, 1 ] ) );
		$this->assertSame( [], get_option( Video_Comments_Cleanup::PENDING_OPTION ), 'record is still cleared' );
	}

	public function test_sweep_leaves_recent_uploads_alone(): void {
		[ $upload_id, $asset_id ] = $this->mux_seed_ready_upload( 'uplNew', 'assetNew', 'pbNew' );
		update_option( Video_Comments_Cleanup::PENDING_OPTION, [ $upload_id => time() - HOUR_IN_SECONDS ] );

		$this->cleanup->sweep_uploads();

		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ $asset_id, 1 ] ) );
		$this->assertArrayHasKey( $upload_id, get_option( Video_Comments_Cleanup::PENDING_OPTION ) );
		$this->assertFalse( $this->mux_requested( 'GET' ) );
	}

	public function test_sweep_drops_unknown_upload_and_keeps_record_on_api_error(): void {
		update_option(
			Video_Comments_Cleanup::PENDING_OPTION,
			[
				'upl_unknown' => time() - 2 * DAY_IN_SECONDS,
			]
		);
		$this->cleanup->sweep_uploads();
		$this->assertSame( [], get_option( Video_Comments_Cleanup::PENDING_OPTION ), '404 removes the record' );

		$this->mux_fail['GET'] = 500;
		update_option( Video_Comments_Cleanup::PENDING_OPTION, [ 'upl_err' => time() - 2 * DAY_IN_SECONDS ] );
		$this->cleanup->sweep_uploads();
		$this->assertArrayHasKey( 'upl_err', get_option( Video_Comments_Cleanup::PENDING_OPTION ), 'transient error keeps the record' );

		update_option( Video_Comments_Cleanup::PENDING_OPTION, [ 'upl_ancient' => time() - 10 * DAY_IN_SECONDS ] );
		$this->cleanup->sweep_uploads();
		$this->assertSame( [], get_option( Video_Comments_Cleanup::PENDING_OPTION ), 'but not past 7 days' );
	}

	public function test_pending_list_is_bounded(): void {
		for ( $i = 0; $i < 505; $i++ ) {
			Video_Comments_Cleanup::record_pending_upload( 'upl_' . $i );
		}
		$this->assertLessThanOrEqual( 500, count( get_option( Video_Comments_Cleanup::PENDING_OPTION ) ) );
	}
}
