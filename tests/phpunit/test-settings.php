<?php
/**
 * Settings sanitisation.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Settings
 */
class Test_Video_Comments_Settings extends WP_UnitTestCase {

	public function test_defaults_when_option_missing(): void {
		delete_option( Video_Comments_Settings::OPTION_KEY );
		$this->assertTrue( Video_Comments_Settings::is_enabled() );
		$this->assertTrue( Video_Comments_Settings::allow_guests() );
		$this->assertSame( 50, Video_Comments_Settings::max_size_mb() );
		$this->assertFalse( Video_Comments_Settings::has_mux_credentials() );
	}

	public function test_sanitize_clamps_and_casts(): void {
		$out = Video_Comments_Settings::get_instance()->sanitize_options(
			[
				'enabled'      => 'yes',
				'max_size_mb'  => '99999',
				'mux_token_id' => " <b>id</b>\n",
			]
		);
		$this->assertSame( 1, $out['enabled'] );
		$this->assertSame( 0, $out['allow_guests'] );
		$this->assertSame( 2048, $out['max_size_mb'] );
		$this->assertSame( 'id', $out['mux_token_id'] );
	}

	public function test_sanitize_non_array_returns_defaults(): void {
		$out = Video_Comments_Settings::get_instance()->sanitize_options( 'garbage' );
		$this->assertSame( 50, $out['max_size_mb'] );
	}

	public function test_blank_secret_keeps_saved_secret(): void {
		update_option( Video_Comments_Settings::OPTION_KEY, [ 'mux_token_secret' => 'keep-me' ] );
		$out = Video_Comments_Settings::get_instance()->sanitize_options( [ 'mux_token_secret' => '' ] );
		$this->assertSame( 'keep-me', $out['mux_token_secret'] );
	}

	public function test_clear_checkbox_removes_secret(): void {
		update_option( Video_Comments_Settings::OPTION_KEY, [ 'mux_token_secret' => 'keep-me' ] );
		$out = Video_Comments_Settings::get_instance()->sanitize_options(
			[
				'mux_token_secret'       => '',
				'clear_mux_token_secret' => '1',
			]
		);
		$this->assertSame( '', $out['mux_token_secret'] );
	}

	public function test_new_secret_replaces_saved_secret(): void {
		update_option( Video_Comments_Settings::OPTION_KEY, [ 'mux_token_secret' => 'old' ] );
		$out = Video_Comments_Settings::get_instance()->sanitize_options( [ 'mux_token_secret' => 'new' ] );
		$this->assertSame( 'new', $out['mux_token_secret'] );
	}

	public function test_secret_field_does_not_echo_saved_value(): void {
		update_option( Video_Comments_Settings::OPTION_KEY, [ 'mux_token_secret' => 's3cret-value' ] );
		ob_start();
		Video_Comments_Settings::get_instance()->render_text(
			[
				'name'      => 'mux_token_secret',
				'type'      => 'password',
				'label_for' => 'video-comments-mux-token-secret',
			]
		);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 's3cret-value', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'clear_mux_token_secret', $html );
		$this->assertStringContainsString( 'id="video-comments-mux-token-secret"', $html );
	}
}
